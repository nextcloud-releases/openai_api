<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Service;

use OCA\OpenAiApi\Exception\ApiException;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\User\Exceptions\UserNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Reads files a task produced. Output files live in appdata, so they are looked
 * up there when the user has no mount for them.
 */
class TaskFileService {
	public function __construct(
		private IManager $taskProcessingManager,
		private IRootFolder $rootFolder,
		private IMimeTypeDetector $mimeTypeDetector,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws ApiException
	 */
	public function getFile(Task $task, int $fileId): File {
		try {
			$referenced = $this->taskProcessingManager->extractFileIdsFromTask($task);
		} catch (\Throwable $e) {
			throw ApiException::serverError('Could not read the files of the task.', $e);
		}
		if (!in_array($fileId, $referenced, true)) {
			throw new ApiException('File not found.', Http::STATUS_NOT_FOUND, 'invalid_request_error');
		}

		$node = null;
		$userId = $task->getUserId();
		if ($userId !== null) {
			try {
				$node = $this->rootFolder->getUserFolder($userId)->getFirstNodeById($fileId);
			} catch (NotPermittedException|UserNotFoundException $e) {
				$this->logger->debug('Could not open the user folder of ' . $userId, ['exception' => $e]);
			}
		}
		if ($node === null) {
			// Files a provider produced are stored in appdata, which the user has no mount for
			$node = $this->rootFolder->getFirstNodeByIdInPath(
				$fileId,
				'/' . $this->rootFolder->getAppDataDirectoryName() . '/',
			);
		}
		if (!$node instanceof File) {
			throw new ApiException('File not found.', Http::STATUS_NOT_FOUND, 'invalid_request_error');
		}
		return $node;
	}

	/**
	 * @throws ApiException
	 */
	public function getContent(Task $task, int $fileId): string {
		$file = $this->getFile($task, $fileId);
		try {
			$content = $file->getContent();
		} catch (\Throwable $e) {
			throw ApiException::serverError('Could not read the file content.', $e);
		}
		return $content;
	}

	/**
	 * The type to serve a task file as.
	 *
	 * Both the stored type and the sniffed one are passed through
	 * getSecureMimeType(), which downgrades everything a browser could execute.
	 * Its `application/octet-stream` answer means "not known to be safe", so it
	 * must not be taken as a reason to fall back to the unchecked type.
	 */
	public function getContentType(File $file): string {
		$stored = $this->mimeTypeDetector->getSecureMimeType($file->getMimeType());
		if (!function_exists('mime_content_type')) {
			return $stored;
		}
		$handle = $file->fopen('rb');
		if ($handle === false) {
			return $stored;
		}
		try {
			$detected = mime_content_type($handle);
		} finally {
			fclose($handle);
		}
		if ($detected === false) {
			return $stored;
		}
		$detected = $this->mimeTypeDetector->getSecureMimeType($detected);
		return $detected === 'application/octet-stream' ? $stored : $detected;
	}
}
