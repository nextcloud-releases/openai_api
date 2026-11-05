<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Tests\unit\Controller;

use OCA\OpenAiApi\AppInfo\Application;
use OCA\OpenAiApi\Controller\ChatController;
use OCA\OpenAiApi\Exception\ApiException;
use OCA\OpenAiApi\Response\ApiErrorResponse;
use OCA\OpenAiApi\Response\SseResponse;
use OCA\OpenAiApi\Service\ChatCompletionSerializer;
use OCA\OpenAiApi\Service\ChatTaskFactory;
use OCA\OpenAiApi\Service\ConcurrencyLimiter;
use OCA\OpenAiApi\Service\Config;
use OCA\OpenAiApi\Service\PreparedTask;
use OCA\OpenAiApi\Service\SseWriter;
use OCA\OpenAiApi\Service\TaskRunner;
use OCA\OpenAiApi\Service\ToolCallMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToTextChat;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ChatControllerTest extends TestCase {
	private const TASK_ID = 42;

	private ChatTaskFactory&MockObject $chatTaskFactory;
	private TaskRunner&MockObject $taskRunner;
	private ConcurrencyLimiter&MockObject $concurrencyLimiter;
	private ChatController $controller;
	private Task $task;

	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([
			'messages' => [['role' => 'user', 'content' => 'Hi']],
			'stream' => true,
		]);

		$this->task = new Task(TextToTextChat::ID, ['input' => 'Hi'], Application::APP_ID, 'alice');
		$this->chatTaskFactory = $this->createMock(ChatTaskFactory::class);
		$this->chatTaskFactory->method('create')
			->willReturn(new PreparedTask($this->task, TextToTextChat::ID, [7]));

		$this->taskRunner = $this->createMock(TaskRunner::class);
		$this->taskRunner->method('isTerminal')->willReturnCallback(
			static fn (Task $task): bool => in_array(
				$task->getStatus(),
				[Task::STATUS_SUCCESSFUL, Task::STATUS_FAILED, Task::STATUS_CANCELLED],
				true,
			),
		);

		$config = $this->createMock(Config::class);
		$config->method('isRealStreamingEnabled')->willReturn(true);

		$this->concurrencyLimiter = $this->createMock(ConcurrencyLimiter::class);

		$this->controller = new ChatController(
			Application::APP_ID,
			$request,
			$this->chatTaskFactory,
			$this->taskRunner,
			new ChatCompletionSerializer(),
			new ToolCallMapper(),
			$config,
			$this->concurrencyLimiter,
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			'alice',
		);
	}

	/**
	 * Run the body of a streamed response against a writer that records nothing.
	 *
	 * The real writer flushes every output buffer, PHPUnit's included, so the
	 * producer is invoked directly instead of through the response callback.
	 */
	private function runStream(SseResponse $response, bool $aborted = false): void {
		$writer = $this->createMock(SseWriter::class);
		$writer->method('isAborted')->willReturn($aborted);
		$producer = (new \ReflectionProperty(SseResponse::class, 'producer'))->getValue($response);
		$producer($writer);
	}

	public function testNoTaskIsScheduledWhenTheUserHasNoSlotLeft(): void {
		$this->concurrencyLimiter->method('acquire')->willThrowException(new ApiException(
			'Too many', Http::STATUS_TOO_MANY_REQUESTS, 'server_error',
		));
		$this->taskRunner->expects(self::never())->method('schedule');
		$this->chatTaskFactory->expects(self::once())->method('cleanup')->with('alice', [7]);

		$response = $this->controller->completions();

		self::assertInstanceOf(ApiErrorResponse::class, $response);
		self::assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
	}

	public function testTheSlotIsReleasedWhenSchedulingFails(): void {
		$this->concurrencyLimiter->expects(self::once())->method('acquire')->with('alice');
		$this->concurrencyLimiter->expects(self::once())->method('release');
		$this->taskRunner->method('schedule')->willThrowException(ApiException::serverError('nope'));

		self::assertInstanceOf(ApiErrorResponse::class, $this->controller->completions());
	}

	public function testTheSlotIsHeldUntilTheStreamEnds(): void {
		$calls = [];
		$this->concurrencyLimiter->method('acquire')->willReturnCallback(function () use (&$calls): void {
			$calls[] = 'acquire';
		});
		$this->concurrencyLimiter->method('release')->willReturnCallback(function () use (&$calls): void {
			$calls[] = 'release';
		});
		$this->taskRunner->method('schedule')->willReturnCallback(function (Task $task) use (&$calls): void {
			$calls[] = 'schedule';
			$task->setId(self::TASK_ID);
		});
		$this->taskRunner->method('poll')->willReturnCallback(function (Task $task) use (&$calls): \Generator {
			$calls[] = 'poll';
			$done = clone $task;
			$done->setStatus(Task::STATUS_SUCCESSFUL);
			$done->setOutput(['output' => 'Hello']);
			yield $done;
		});
		$this->taskRunner->method('assertSuccessful')->willReturnArgument(0);
		$this->taskRunner->expects(self::never())->method('cancel');

		$response = $this->controller->completions();
		self::assertInstanceOf(SseResponse::class, $response);
		self::assertSame(['acquire', 'schedule'], $calls);

		$this->runStream($response);
		self::assertSame(['acquire', 'schedule', 'poll', 'release'], $calls);
	}

	public function testTheTaskIsCancelledWhenPollingFails(): void {
		$this->taskRunner->method('schedule')->willReturnCallback(function (Task $task): void {
			$task->setId(self::TASK_ID);
		});
		$this->taskRunner->method('poll')->willReturnCallback(function (): \Generator {
			throw new ApiException('Too many', Http::STATUS_TOO_MANY_REQUESTS, 'server_error');
			/** @psalm-suppress UnevaluatedCode */
			yield;
		});
		$this->taskRunner->expects(self::once())->method('cancel')->with(self::TASK_ID);
		$this->concurrencyLimiter->expects(self::once())->method('release');
		$this->chatTaskFactory->expects(self::once())->method('cleanup')->with('alice', [7]);

		$response = $this->controller->completions();
		self::assertInstanceOf(SseResponse::class, $response);
		$this->runStream($response);
	}

	public function testTheTaskIsCancelledWhenTheClientGoesAway(): void {
		$this->taskRunner->method('schedule')->willReturnCallback(function (Task $task): void {
			$task->setId(self::TASK_ID);
			$task->setStatus(Task::STATUS_RUNNING);
		});
		$this->taskRunner->method('poll')->willReturnCallback(function (Task $task): \Generator {
			yield $task;
		});
		$this->taskRunner->expects(self::once())->method('cancel')->with(self::TASK_ID);

		$response = $this->controller->completions();
		self::assertInstanceOf(SseResponse::class, $response);
		$this->runStream($response, aborted: true);
	}
}
