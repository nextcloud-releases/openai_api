<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Tests\unit\Service;

use OCA\OpenAiApi\Exception\ApiException;
use OCA\OpenAiApi\Service\ConcurrencyLimiter;
use OCA\OpenAiApi\Service\Config;
use OCA\OpenAiApi\Service\TaskRunner;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class TaskRunnerTest extends TestCase {
	private IManager&MockObject $taskProcessingManager;
	private Config&MockObject $config;
	private ConcurrencyLimiter&MockObject $concurrencyLimiter;
	private LoggerInterface&MockObject $logger;
	private TaskRunner $taskRunner;

	protected function setUp(): void {
		parent::setUp();
		$this->taskProcessingManager = $this->createMock(IManager::class);
		$this->config = $this->createMock(Config::class);
		$this->config->method('getRequestTimeout')->willReturn(300);
		$this->config->method('getPollIntervalMs')->willReturn(50);
		$this->concurrencyLimiter = $this->createMock(ConcurrencyLimiter::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->taskRunner = new TaskRunner(
			$this->taskProcessingManager,
			$this->config,
			$this->concurrencyLimiter,
			$this->logger,
		);
	}

	private function failedTask(?string $errorMessage, ?string $userFacingErrorMessage): Task {
		$task = new Task('core:text2text', ['input' => 'hi'], 'openai_api', 'alice');
		$task->setId(42);
		$task->setStatus(Task::STATUS_FAILED);
		$task->setErrorMessage($errorMessage);
		$task->setUserFacingErrorMessage($userFacingErrorMessage);
		return $task;
	}

	public function testAFailedTaskDoesNotLeakTheProvidersErrorMessage(): void {
		$task = $this->failedTask('Connection to https://provider.internal:8080 failed: bad key sk-123', null);

		try {
			$this->taskRunner->assertSuccessful($task);
			self::fail('Expected an ApiException');
		} catch (ApiException $e) {
			self::assertSame('The task failed.', $e->getMessage());
			self::assertStringNotContainsString('provider.internal', $e->getMessage());
		}
	}

	public function testAFailedTaskIsReportedWithItsUserFacingMessage(): void {
		$task = $this->failedTask('upstream said 429 at https://provider.internal', 'The model is busy.');

		$this->expectExceptionMessage('The model is busy.');
		$this->taskRunner->assertSuccessful($task);
	}

	public function testTheProvidersErrorMessageIsLogged(): void {
		$this->logger->expects(self::once())
			->method('error')
			->with(self::stringContains('provider.internal'));

		try {
			$this->taskRunner->assertSuccessful($this->failedTask('boom at provider.internal', null));
		} catch (ApiException) {
		}
	}

	public function testACancelledTaskIsReportedAsCancelled(): void {
		$task = $this->failedTask(null, null);
		$task->setStatus(Task::STATUS_CANCELLED);

		$this->expectExceptionMessage('The task was cancelled.');
		$this->taskRunner->assertSuccessful($task);
	}

	public function testRunHoldsAConcurrencySlotForTheWholeRun(): void {
		$task = new Task('core:text2text', ['input' => 'hi'], 'openai_api', 'alice');
		$finished = clone $task;
		$finished->setStatus(Task::STATUS_SUCCESSFUL);

		$this->taskProcessingManager->method('getPreferredProvider')
			->willReturn($this->createMock(ISynchronousProvider::class));
		$this->taskProcessingManager->method('runTask')->willReturn($finished);

		$this->concurrencyLimiter->expects(self::once())->method('acquire')->with('alice');
		$this->concurrencyLimiter->expects(self::once())->method('release');

		$this->taskRunner->run($task);
	}

	public function testRunReleasesTheConcurrencySlotWhenTheTaskFails(): void {
		$task = new Task('core:text2text', ['input' => 'hi'], 'openai_api', 'alice');

		$this->taskProcessingManager->method('getPreferredProvider')
			->willReturn($this->createMock(ISynchronousProvider::class));
		$this->taskProcessingManager->method('runTask')
			->willReturn($this->failedTask('internal detail', null));

		$this->concurrencyLimiter->expects(self::once())->method('release');

		$this->expectException(ApiException::class);
		$this->taskRunner->run($task);
	}

	public function testPollHoldsAConcurrencySlotWhileItRuns(): void {
		$task = new Task('core:text2text', ['input' => 'hi'], 'openai_api', 'alice');
		$task->setId(7);
		$finished = clone $task;
		$finished->setStatus(Task::STATUS_SUCCESSFUL);

		$this->taskProcessingManager->method('getTask')->willReturn($finished);

		$this->concurrencyLimiter->expects(self::once())->method('acquire')->with('alice');
		$this->concurrencyLimiter->expects(self::once())->method('release');

		foreach ($this->taskRunner->poll($task) as $_) {
		}
	}

	public function testAbandoningThePollReleasesTheConcurrencySlot(): void {
		$task = new Task('core:text2text', ['input' => 'hi'], 'openai_api', 'alice');
		$task->setId(7);
		$running = clone $task;
		$running->setStatus(Task::STATUS_RUNNING);

		$this->taskProcessingManager->method('getTask')->willReturn($running);

		$this->concurrencyLimiter->expects(self::once())->method('release');

		// A client that goes away leaves the generator half consumed
		foreach ($this->taskRunner->poll($task) as $_) {
			break;
		}
		gc_collect_cycles();
	}
}
