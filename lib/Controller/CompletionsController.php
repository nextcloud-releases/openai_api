<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Controller;

use OCA\OpenAiApi\AppInfo\Application;
use OCA\OpenAiApi\Exception\ApiException;
use OCA\OpenAiApi\Response\SseResponse;
use OCA\OpenAiApi\Service\ChatCompletionSerializer;
use OCA\OpenAiApi\Service\ModelService;
use OCA\OpenAiApi\Service\SseWriter;
use OCA\OpenAiApi\Service\TaskRunner;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\TaskProcessing\Task;

/**
 * The legacy completions endpoint.
 *
 * Streaming requests are always answered with a single chunk here, regardless of
 * the real streaming setting: the endpoint is deprecated upstream and the chat
 * endpoint is the one worth holding a worker open for.
 */
class CompletionsController extends AbstractApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ModelService $modelService,
		private TaskRunner $taskRunner,
		private ChatCompletionSerializer $serializer,
		private ITimeFactory $timeFactory,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(): JSONResponse|SseResponse {
		try {
			$params = $this->request->getParams();
			$prompt = $this->normalisePrompt($params['prompt'] ?? null);
			$model = is_string($params['model'] ?? null) ? $params['model'] : null;
			$stream = $this->toBool($params['stream'] ?? false);
			$maxTokens = $this->toPositiveInt($params['max_tokens'] ?? null);

			$taskTypeId = $this->modelService->resolve($model, ModelService::COMPLETION_TASK_TYPES, $this->userId);
			$slots = $this->modelService->getInputSlots($taskTypeId);

			$input = ['input' => $prompt];
			if ($maxTokens !== null && in_array('max_tokens', $slots, true)) {
				$input['max_tokens'] = $maxTokens;
			}

			$task = $this->taskRunner->run(
				new Task($taskTypeId, $input, Application::APP_ID, $this->userId),
			);
		} catch (ApiException $e) {
			return $this->error($e);
		}

		$text = (string)(($task->getOutput() ?? [])['output'] ?? '');
		$id = 'cmpl-' . ($task->getId() ?? '0');
		$created = $this->timeFactory->getTime();

		if (!$stream) {
			return new JSONResponse($this->serializer->textCompletion($id, $taskTypeId, $created, $text));
		}

		return new SseResponse(function (SseWriter $writer) use ($id, $taskTypeId, $created, $text): void {
			$writer->event($this->serializer->textCompletion($id, $taskTypeId, $created, $text));
			$writer->done();
		});
	}

	/**
	 * @throws ApiException
	 */
	private function normalisePrompt(mixed $prompt): string {
		if (is_string($prompt) && $prompt !== '') {
			return $prompt;
		}
		if (is_array($prompt) && $prompt !== []) {
			$parts = array_filter($prompt, 'is_string');
			if (count($parts) === count($prompt)) {
				return implode("\n", $parts);
			}
		}
		throw ApiException::invalidRequest('"prompt" must be a string or an array of strings.', 'prompt');
	}
}
