<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Controller;

use OCA\OpenAiApi\Exception\ApiException;
use OCA\OpenAiApi\Response\ApiErrorResponse;
use OCA\OpenAiApi\Response\SseResponse;
use OCA\OpenAiApi\Service\ChatCompletionSerializer;
use OCA\OpenAiApi\Service\ChatTaskFactory;
use OCA\OpenAiApi\Service\ConcurrencyLimiter;
use OCA\OpenAiApi\Service\Config;
use OCA\OpenAiApi\Service\PreparedTask;
use OCA\OpenAiApi\Service\SseWriter;
use OCA\OpenAiApi\Service\StreamCursor;
use OCA\OpenAiApi\Service\TaskRunner;
use OCA\OpenAiApi\Service\ToolCallMapper;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;

class ChatController extends AbstractApiController {
	/** Seconds of provider silence after which a comment is written to notice a closed connection. */
	private const KEEPALIVE_SECONDS = 5;

	public function __construct(
		string $appName,
		IRequest $request,
		private ChatTaskFactory $chatTaskFactory,
		private TaskRunner $taskRunner,
		private ChatCompletionSerializer $serializer,
		private ToolCallMapper $toolCallMapper,
		private Config $config,
		private ConcurrencyLimiter $concurrencyLimiter,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function completions(): JSONResponse|SseResponse {
		try {
			$params = $this->request->getParams();
			$messages = $this->requireList($params['messages'] ?? null, 'messages');
			$tools = isset($params['tools']) ? $this->requireList($params['tools'], 'tools') : null;
			$model = is_string($params['model'] ?? null) ? $params['model'] : null;
			$maxTokens = $this->toPositiveInt(
				$params['max_completion_tokens'] ?? $params['max_tokens'] ?? null,
			);
			$stream = $this->toBool($params['stream'] ?? false);

			$prepared = $this->chatTaskFactory->create($this->userId, $model, $messages, $tools, $maxTokens);
		} catch (ApiException $e) {
			return $this->error($e);
		}

		if (!$stream) {
			return $this->complete($prepared);
		}
		if ($this->config->isRealStreamingEnabled()) {
			return $this->streamProgressively($prepared);
		}
		return $this->streamAtOnce($prepared);
	}

	private function complete(PreparedTask $prepared): JSONResponse {
		try {
			$task = $this->taskRunner->run($prepared->task);
		} catch (ApiException $e) {
			return $this->error($e);
		} finally {
			$this->chatTaskFactory->cleanup($this->userId, $prepared->stagedFileIds);
		}

		$output = $task->getOutput() ?? [];
		return new JSONResponse($this->serializer->completion(
			$this->completionId($task),
			$prepared->taskTypeId,
			$this->timeFactory->getTime(),
			(string)($output['output'] ?? ''),
			$this->toolCallMapper->fromProvider((string)($output['tool_calls'] ?? '')),
			(string)($output['reasoning'] ?? ''),
		));
	}

	/**
	 * Run the task to completion, then emit the answer as a single chunk.
	 *
	 * Used when real streaming is disabled: the client gets a well formed event
	 * stream, but a PHP worker is not held open for the polling loop.
	 */
	private function streamAtOnce(PreparedTask $prepared): SseResponse|ApiErrorResponse {
		$prepared->task->setPreferStreaming(false);
		try {
			$task = $this->taskRunner->run($prepared->task);
		} catch (ApiException $e) {
			return $this->error($e);
		} finally {
			$this->chatTaskFactory->cleanup($this->userId, $prepared->stagedFileIds);
		}

		$output = $task->getOutput() ?? [];
		$id = $this->completionId($task);
		$model = $prepared->taskTypeId;
		$created = $this->timeFactory->getTime();
		$content = (string)($output['output'] ?? '');
		$reasoning = (string)($output['reasoning'] ?? '');
		$toolCalls = $this->toolCallMapper->fromProvider((string)($output['tool_calls'] ?? ''));

		return new SseResponse(function (SseWriter $writer) use ($id, $model, $created, $content, $reasoning, $toolCalls): void {
			$writer->event($this->serializer->chunk($id, $model, $created, ['role' => 'assistant', 'content' => '']));
			if ($reasoning !== '') {
				$writer->event($this->serializer->chunk($id, $model, $created, ['reasoning_content' => $reasoning]));
			}
			if ($content !== '') {
				$writer->event($this->serializer->chunk($id, $model, $created, ['content' => $content]));
			}
			$this->emitFinalChunk($writer, $id, $model, $created, $toolCalls);
			$writer->done();
		});
	}

	/**
	 * Schedule the task and forward its output as the provider produces it.
	 *
	 * Providers report intermediate output cumulatively and Task Processing
	 * persists it at most once every two seconds, so chunks arrive at roughly
	 * that cadence rather than per token.
	 *
	 * The concurrency slot is claimed before the task is scheduled and held until
	 * the stream ends, so a user at the limit cannot queue tasks nobody waits for.
	 * Whenever the stream ends before the task did - client gone, polling failed -
	 * the task is cancelled instead of being left to run unobserved.
	 */
	private function streamProgressively(PreparedTask $prepared): SseResponse|ApiErrorResponse {
		$prepared->task->setPreferStreaming(true);
		try {
			$this->concurrencyLimiter->acquire($this->userId);
		} catch (ApiException $e) {
			$this->chatTaskFactory->cleanup($this->userId, $prepared->stagedFileIds);
			return $this->error($e);
		}
		try {
			$this->taskRunner->schedule($prepared->task);
		} catch (ApiException $e) {
			$this->concurrencyLimiter->release();
			$this->chatTaskFactory->cleanup($this->userId, $prepared->stagedFileIds);
			return $this->error($e);
		}

		return new SseResponse(function (SseWriter $writer) use ($prepared): void {
			$task = $prepared->task;
			$taskId = $task->getId();
			$id = $this->completionId($task);
			$model = $prepared->taskTypeId;
			$created = $this->timeFactory->getTime();
			$contentCursor = new StreamCursor();
			$reasoningCursor = new StreamCursor();
			$lastWriteAt = microtime(true);
			$final = $task;

			try {
				$writer->event($this->serializer->chunk($id, $model, $created, ['role' => 'assistant', 'content' => '']));

				foreach ($this->taskRunner->poll($task) as $current) {
					$final = $current;
					$wrote = $this->emitDeltas(
						$writer, $id, $model, $created,
						$current->getOutput() ?? [], $contentCursor, $reasoningCursor,
					);
					if ($wrote) {
						$lastWriteAt = microtime(true);
					} elseif (microtime(true) - $lastWriteAt >= self::KEEPALIVE_SECONDS) {
						$writer->comment('keepalive');
						$lastWriteAt = microtime(true);
					}
					if ($writer->isAborted()) {
						return;
					}
				}

				$final = $this->taskRunner->assertSuccessful($final);
				$output = $final->getOutput() ?? [];
				$this->emitDeltas($writer, $id, $model, $created, $output, $contentCursor, $reasoningCursor);
				$this->emitFinalChunk(
					$writer, $id, $model, $created,
					$this->toolCallMapper->fromProvider((string)($output['tool_calls'] ?? '')),
				);
				$writer->done();
			} catch (ApiException $e) {
				$writer->event($e->toArray());
				$writer->done();
			} catch (\Throwable $e) {
				$this->logger->error('Streaming chat completion failed', ['exception' => $e]);
				$writer->event(ApiException::serverError('The request could not be completed.')->toArray());
				$writer->done();
			} finally {
				if ($taskId !== null && !$this->taskRunner->isTerminal($final)) {
					$this->taskRunner->cancel($taskId);
				}
				$this->concurrencyLimiter->release();
				$this->chatTaskFactory->cleanup($this->userId, $prepared->stagedFileIds);
			}
		});
	}

	/**
	 * @param array<string, mixed> $output
	 * @return bool whether anything was written
	 */
	private function emitDeltas(
		SseWriter $writer,
		string $id,
		string $model,
		int $created,
		array $output,
		StreamCursor $contentCursor,
		StreamCursor $reasoningCursor,
	): bool {
		$wrote = false;

		$reasoning = $reasoningCursor->advance((string)($output['reasoning'] ?? ''));
		if ($reasoning !== '') {
			$writer->event($this->serializer->chunk($id, $model, $created, ['reasoning_content' => $reasoning]));
			$wrote = true;
		}

		$content = $contentCursor->advance((string)($output['output'] ?? ''));
		if ($content !== '') {
			$writer->event($this->serializer->chunk($id, $model, $created, ['content' => $content]));
			$wrote = true;
		}

		return $wrote;
	}

	/**
	 * @param list<array<string, mixed>> $toolCalls
	 */
	private function emitFinalChunk(
		SseWriter $writer,
		string $id,
		string $model,
		int $created,
		array $toolCalls,
	): void {
		$delta = [];
		foreach ($toolCalls as $index => $toolCall) {
			$delta['tool_calls'][] = ['index' => $index] + $toolCall;
		}
		$writer->event($this->serializer->chunk(
			$id, $model, $created, $delta,
			$toolCalls === [] ? 'stop' : 'tool_calls',
		));
	}

	private function completionId(Task $task): string {
		return 'chatcmpl-' . ($task->getId() ?? '0');
	}
}
