<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Service;

use OCA\OpenAiApi\AppInfo\Application;
use OCA\OpenAiApi\Exception\ApiException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Stages inline attachments as files in the requesting user's storage.
 *
 * Task Processing resolves file slots through the user mount cache, so files in
 * appdata are rejected for task input - attachments have to live somewhere the
 * user has a mount for. They are written to a hidden folder in the user's root
 * and removed again once the request that needs them is done.
 */
class FileStagingService {
	/** Length of the random prefix that marks a file as one this app staged. */
	private const PREFIX_LENGTH = 16;

	private const PREFIX_PATTERN = '/^[a-z0-9]{' . self::PREFIX_LENGTH . '}-/';

	public function __construct(
		private IRootFolder $rootFolder,
		private Config $config,
		private ITimeFactory $timeFactory,
		private ISecureRandom $random,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param list<Attachment> $attachments
	 * @return list<int> the ids of the staged files
	 * @throws ApiException
	 */
	public function stage(string $userId, array $attachments): array {
		if ($attachments === []) {
			return [];
		}

		$this->assertWithinSizeLimit($attachments);

		try {
			$folder = $this->getStagingFolder($userId);
		} catch (ApiException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw ApiException::serverError('Could not prepare the attachment staging folder.', $e);
		}

		$fileIds = [];
		try {
			foreach ($attachments as $attachment) {
				$name = $this->prefix() . '-' . $this->sanitize($attachment->filename);
				$file = $folder->newFile($name, $attachment->content);
				$fileIds[] = $file->getId();
			}
		} catch (\Throwable $e) {
			$this->cleanup($userId, $fileIds);
			throw ApiException::serverError('Could not store an attachment.', $e);
		}

		return $fileIds;
	}

	/**
	 * Delete staged files. Never throws - a leftover file is swept later.
	 *
	 * @param list<int> $fileIds
	 */
	public function cleanup(string $userId, array $fileIds): void {
		if ($fileIds === []) {
			return;
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not open the user folder to clean up staged files', ['exception' => $e]);
			return;
		}
		foreach ($fileIds as $fileId) {
			try {
				$node = $userFolder->getFirstNodeById($fileId);
				$node?->delete();
			} catch (\Throwable $e) {
				$this->logger->warning('Could not delete staged file ' . $fileId, ['exception' => $e]);
			}
		}
	}

	/**
	 * @throws ApiException if the staging path is taken by something else
	 * @throws \OCP\Files\NotPermittedException
	 * @throws \OCP\User\Exceptions\UserNotFoundException
	 */
	private function getStagingFolder(string $userId): Folder {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		try {
			$folder = $userFolder->get(Application::STAGING_FOLDER);
		} catch (NotFoundException) {
			return $userFolder->newFolder(Application::STAGING_FOLDER);
		}

		if (!$folder instanceof Folder || !$this->isOwnHomeFolder($folder, $userId)) {
			throw new ApiException(
				'The staging path "' . Application::STAGING_FOLDER . '" in your files is not a folder'
				. ' in your own storage. Attachments cannot be staged until it is moved out of the way.',
				Http::STATUS_PRECONDITION_FAILED,
				'invalid_request_error',
				null,
				'staging_folder_unusable',
			);
		}

		$this->sweep($folder);
		return $folder;
	}

	/**
	 * Whether the node lives in the user's own home storage.
	 *
	 * A share or a group folder that happens to sit at the staging path would put
	 * the attachments of a request in reach of whoever else is mounted there, and
	 * would expose that folder's contents to the sweep.
	 */
	private function isOwnHomeFolder(Node $node, string $userId): bool {
		return $node->getOwner()?->getUID() === $userId
			&& $node->getMountPoint()->getMountType() === '';
	}

	/**
	 * @param list<Attachment> $attachments
	 * @throws ApiException
	 */
	private function assertWithinSizeLimit(array $attachments): void {
		$limit = $this->config->getMaxAttachmentSize();
		$total = 0;
		foreach ($attachments as $attachment) {
			$size = strlen($attachment->content);
			$total += $size;
			if ($size > $limit || $total > $limit) {
				throw new ApiException(
					'Attachments must not exceed ' . $limit . ' bytes in total.',
					Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
					'invalid_request_error',
				);
			}
		}
	}

	private function prefix(): string {
		return $this->random->generate(
			self::PREFIX_LENGTH,
			ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS,
		);
	}

	/**
	 * Drop files left behind by requests that died before they could clean up.
	 *
	 * Sweeping the requesting user's own folder on every request keeps this
	 * bounded and self-healing, without a job that would have to walk every
	 * account on the instance. Only files carrying the staging prefix are
	 * considered, so a folder a user created under this name themselves does not
	 * lose its contents.
	 */
	private function sweep(Folder $folder): void {
		$expiry = $this->timeFactory->getTime() - $this->config->getStagedFileTtl();
		try {
			foreach ($folder->getDirectoryListing() as $node) {
				if (!$node instanceof File || $node->getMTime() >= $expiry) {
					continue;
				}
				if (preg_match(self::PREFIX_PATTERN, $node->getName()) === 1) {
					$node->delete();
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not sweep the attachment staging folder', ['exception' => $e]);
		}
	}

	private function sanitize(string $filename): string {
		$sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'attachment';
		$sanitized = ltrim($sanitized, '.');
		if ($sanitized === '') {
			$sanitized = 'attachment';
		}
		return substr($sanitized, 0, 100);
	}
}
