<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Service;

use OCA\OpenAiApi\Exception\ApiException;
use OCP\AppFramework\Http;
use OCP\TaskProcessing\Exception\Exception as TaskProcessingException;
use OCP\TaskProcessing\Exception\NotFoundException;
use OCP\TaskProcessing\Exception\PreConditionNotMetException;
use OCP\TaskProcessing\Exception\UnauthorizedException;
use OCP\TaskProcessing\Exception\ValidationException;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;

/**
 * Drives a task to completion on behalf of a request.
 */
class TaskRunner {
	public function __construct(
		private IManager $taskProcessingManager,
		private Config $config,
		private ConcurrencyLimiter $concurrencyLimiter,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Run a task and return it once it reached a terminal state.
	 *
	 * @throws ApiException
	 */
	public function run(Task $task): Task {
		// Held across the whole run: the request occupies a worker until the task
		// reaches a terminal state, whichever branch gets it there.
		$this->concurrencyLimiter->acquire($task->getUserId());
		try {
			if ($this->hasSynchronousProvider($task->getTaskTypeId())) {
				// A synchronous provider runs inside this request, so there is nothing
				// to poll for and no dependency on a background worker.
				try {
					$finished = $this->taskProcessingManager->runTask($task);
				} catch (\Throwable $e) {
					throw $this->mapException($e);
				}
				return $this->assertSuccessful($finished);
			}

			$this->schedule($task);
			$finished = $task;
			foreach ($this->poll($task) as $current) {
				$finished = $current;
			}
			return $this->assertSuccessful($finished);
		} finally {
			$this->concurrencyLimiter->release();
		}
	}

	/**
	 * @throws ApiException
	 */
	public function schedule(Task $task): void {
		try {
			$this->taskProcessingManager->scheduleTask($task);
		} catch (\Throwable $e) {
			throw $this->mapException($e);
		}
	}

	/**
	 * Yield the task on every poll until it reaches a terminal state.
	 *
	 * The task must already be scheduled. Consumers that stop iterating early -
	 * because the client went away - are responsible for cancelling the task.
	 *
	 * @return \Generator<int, Task>
	 * @throws ApiException
	 */
	public function poll(Task $task): \Generator {
		$taskId = $task->getId();
		if ($taskId === null) {
			throw ApiException::serverError('Cannot poll a task that was never scheduled.');
		}

		$deadline = microtime(true) + (float)$this->config->getRequestTimeout();
		$interval = $this->config->getPollIntervalMs() * 1000;

		// The polling loop is what holds the worker open for a streamed request.
		// Closing the generator early runs the release, so a consumer that stops
		// iterating does not keep the slot.
		$this->concurrencyLimiter->acquire($task->getUserId());
		try {
			while (true) {
				try {
					$current = $this->taskProcessingManager->getTask($taskId);
				} catch (\Throwable $e) {
					throw $this->mapException($e);
				}

				yield $current;

				if ($this->isTerminal($current)) {
					return;
				}
				if (microtime(true) >= $deadline) {
					$this->cancel($taskId);
					throw new ApiException(
						'The task did not finish within ' . $this->config->getRequestTimeout() . ' seconds.',
						Http::STATUS_GATEWAY_TIMEOUT,
						'server_error',
						null,
						'timeout',
					);
				}
				usleep($interval);
			}
		} finally {
			$this->concurrencyLimiter->release();
		}
	}

	/**
	 * Cancel a task, e.g. because the client disconnected. Never throws.
	 */
	public function cancel(int $taskId): void {
		try {
			$this->taskProcessingManager->cancelTask($taskId);
		} catch (\Throwable $e) {
			$this->logger->debug('Could not cancel task ' . $taskId, ['exception' => $e]);
		}
	}

	public function isTerminal(Task $task): bool {
		return in_array(
			$task->getStatus(),
			[Task::STATUS_SUCCESSFUL, Task::STATUS_FAILED, Task::STATUS_CANCELLED],
			true,
		);
	}

	/**
	 * Whether the preferred provider for this task type runs inside the request
	 * rather than being served by a separate worker or ex-app.
	 */
	public function hasSynchronousProvider(string $taskTypeId): bool {
		try {
			return $this->taskProcessingManager->getPreferredProvider($taskTypeId) instanceof ISynchronousProvider;
		} catch (TaskProcessingException $e) {
			$this->logger->debug('Could not determine the preferred provider for ' . $taskTypeId, ['exception' => $e]);
			return false;
		}
	}

	/**
	 * @throws ApiException
	 */
	public function assertSuccessful(Task $task): Task {
		return match ($task->getStatus()) {
			Task::STATUS_SUCCESSFUL => $task,
			Task::STATUS_CANCELLED => throw new ApiException(
				'The task was cancelled.',
				Http::STATUS_INTERNAL_SERVER_ERROR,
				'server_error',
				null,
				'task_cancelled',
			),
			default => throw $this->taskFailed($task),
		};
	}

	/**
	 * The error of a failed task, without the provider's own message.
	 *
	 * Only `userFacingErrorMessage` is meant to be shown to the requesting user.
	 * The raw error message carries whatever the provider threw - upstream URLs,
	 * internal host names, raw upstream responses - and is logged rather than
	 * returned.
	 */
	private function taskFailed(Task $task): ApiException {
		$errorMessage = $task->getErrorMessage();
		if ($errorMessage !== null) {
			$this->logger->error('Task ' . ($task->getId() ?? 0) . ' failed: ' . $errorMessage);
		}
		return new ApiException(
			$task->getUserFacingErrorMessage() ?? 'The task failed.',
			Http::STATUS_INTERNAL_SERVER_ERROR,
			'server_error',
			null,
			'task_failed',
		);
	}

	private function mapException(\Throwable $e): ApiException {
		if ($e instanceof ApiException) {
			return $e;
		}
		if ($e instanceof ValidationException) {
			return ApiException::invalidRequest($e->getMessage());
		}
		if ($e instanceof UnauthorizedException) {
			return new ApiException($e->getMessage(), Http::STATUS_FORBIDDEN, 'invalid_request_error');
		}
		if ($e instanceof PreConditionNotMetException) {
			return new ApiException(
				$e->getMessage(),
				Http::STATUS_SERVICE_UNAVAILABLE,
				'server_error',
				null,
				'no_provider',
			);
		}
		if ($e instanceof NotFoundException) {
			return new ApiException($e->getMessage(), Http::STATUS_NOT_FOUND, 'invalid_request_error');
		}
		$this->logger->error('Task processing request failed', ['exception' => $e]);
		return ApiException::serverError('The request could not be completed.', $e);
	}
}
