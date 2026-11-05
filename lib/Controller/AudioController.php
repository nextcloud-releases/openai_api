<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Controller;

use OCA\OpenAiApi\AppInfo\Application;
use OCA\OpenAiApi\Exception\ApiException;
use OCA\OpenAiApi\Service\Attachment;
use OCA\OpenAiApi\Service\FileStagingService;
use OCA\OpenAiApi\Service\ModelService;
use OCA\OpenAiApi\Service\TaskFileService;
use OCA\OpenAiApi\Service\TaskRunner;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\TaskProcessing\Task;

class AudioController extends AbstractApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ModelService $modelService,
		private TaskRunner $taskRunner,
		private FileStagingService $fileStagingService,
		private TaskFileService $taskFileService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Transcribe an uploaded audio file.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function transcriptions(): JSONResponse|DataDisplayResponse {
		$stagedFileIds = [];
		try {
			if ($this->userId === null) {
				throw new ApiException(
					'Transcription requires a user context.',
					Http::STATUS_FORBIDDEN,
					'invalid_request_error',
				);
			}

			$responseFormat = $this->request->getParam('response_format', 'json');
			if (!in_array($responseFormat, ['json', 'text'], true)) {
				throw ApiException::invalidRequest(
					'Only the "json" and "text" response formats are supported.',
					'response_format',
				);
			}

			$model = $this->request->getParam('model');
			$taskTypeId = $this->modelService->resolve(
				is_string($model) ? $model : null,
				ModelService::TRANSCRIPTION_TASK_TYPES,
				$this->userId,
			);

			$stagedFileIds = $this->fileStagingService->stage($this->userId, [$this->readUpload()]);
			$task = $this->taskRunner->run(new Task(
				$taskTypeId,
				['input' => $stagedFileIds[0]],
				Application::APP_ID,
				$this->userId,
			));
		} catch (ApiException $e) {
			return $this->error($e);
		} finally {
			if ($this->userId !== null) {
				$this->fileStagingService->cleanup($this->userId, $stagedFileIds);
			}
		}

		$text = (string)(($task->getOutput() ?? [])['output'] ?? '');
		if ($responseFormat === 'text') {
			return new DataDisplayResponse($text, Http::STATUS_OK, ['Content-Type' => 'text/plain; charset=utf-8']);
		}
		return new JSONResponse(['text' => $text]);
	}

	/**
	 * Generate speech from text.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function speech(): JSONResponse|DataDisplayResponse {
		try {
			$input = $this->requireString($this->request->getParam('input'), 'input');
			$model = $this->request->getParam('model');
			$taskTypeId = $this->modelService->resolve(
				is_string($model) ? $model : null,
				ModelService::SPEECH_TASK_TYPES,
				$this->userId,
			);

			$task = $this->taskRunner->run(new Task(
				$taskTypeId,
				['input' => $input],
				Application::APP_ID,
				$this->userId,
			));

			$fileId = ($task->getOutput() ?? [])['speech'] ?? null;
			if (!is_numeric($fileId)) {
				throw ApiException::serverError('The provider did not return any audio.');
			}

			$file = $this->taskFileService->getFile($task, (int)$fileId);
			return new DataDisplayResponse(
				$file->getContent(),
				Http::STATUS_OK,
				['Content-Type' => $this->taskFileService->getContentType($file)],
			);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * @throws ApiException
	 */
	private function readUpload(): Attachment {
		$upload = $this->request->getUploadedFile('file');
		if ($upload === null || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			throw ApiException::invalidRequest('A "file" upload is required.', 'file');
		}
		$tmpName = $upload['tmp_name'] ?? null;
		// Only ever read back what PHP itself received as an upload
		if (!is_string($tmpName) || !is_uploaded_file($tmpName)) {
			throw ApiException::invalidRequest('A "file" upload is required.', 'file');
		}
		$content = file_get_contents($tmpName);
		if ($content === false) {
			throw ApiException::serverError('The uploaded file could not be read.');
		}
		$name = is_string($upload['name'] ?? null) && $upload['name'] !== '' ? $upload['name'] : 'audio.mp3';
		return new Attachment($content, basename($name));
	}
}
