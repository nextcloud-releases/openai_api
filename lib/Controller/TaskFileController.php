<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Controller;

use OCA\OpenAiApi\AppInfo\Application;
use OCA\OpenAiApi\Exception\ApiException;
use OCA\OpenAiApi\Service\TaskFileService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;
use OCP\TaskProcessing\Exception\NotFoundException;
use OCP\TaskProcessing\IManager;

/**
 * Serves files a task produced, for clients that asked for URLs instead of
 * inline data.
 */
class TaskFileController extends AbstractApiController {
	/**
	 * Types that may be rendered by the browser instead of being downloaded.
	 *
	 * Generated images are meant to be usable straight from an `<img>` tag, so
	 * media keeps its inline disposition. Anything else is served as an
	 * attachment, which keeps a document type a provider chose from being
	 * rendered on the Nextcloud origin.
	 */
	private const INLINE_TYPES = [
		'image/png',
		'image/jpeg',
		'image/gif',
		'image/webp',
		'audio/mpeg',
		'audio/wav',
		'audio/x-wav',
		'audio/ogg',
		'audio/flac',
		'audio/mp4',
		'audio/webm',
		'video/mp4',
		'video/webm',
	];

	public function __construct(
		string $appName,
		IRequest $request,
		private IManager $taskProcessingManager,
		private TaskFileService $taskFileService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function get(int $taskId, int $fileId): StreamResponse|JSONResponse {
		try {
			try {
				$task = $this->taskProcessingManager->getUserTask($taskId, $this->userId);
			} catch (NotFoundException $e) {
				throw new ApiException('Task not found.', Http::STATUS_NOT_FOUND, 'invalid_request_error', null, null, $e);
			}
			// An app password handed to a third party client should not reach the
			// files of tasks that other apps scheduled for the same user.
			if ($task->getAppId() !== Application::APP_ID) {
				throw new ApiException('Task not found.', Http::STATUS_NOT_FOUND, 'invalid_request_error');
			}

			$file = $this->taskFileService->getFile($task, $fileId);
			$handle = $file->fopen('rb');
			if ($handle === false) {
				throw ApiException::serverError('The file could not be opened.');
			}

			$contentType = $this->taskFileService->getContentType($file);
			$disposition = in_array($contentType, self::INLINE_TYPES, true) ? 'inline' : 'attachment';

			$response = new StreamResponse($handle);
			// Nothing in a task file is allowed to load or run anything
			$response->setContentSecurityPolicy(new EmptyContentSecurityPolicy());
			$response->addHeader('Content-Type', $contentType);
			$response->addHeader(
				'Content-Disposition',
				$disposition . '; filename="' . rawurlencode($file->getName()) . '"',
			);
			return $response;
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}
}
