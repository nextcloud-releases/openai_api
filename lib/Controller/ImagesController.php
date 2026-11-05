<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Controller;

use OCA\OpenAiApi\AppInfo\Application;
use OCA\OpenAiApi\Exception\ApiException;
use OCA\OpenAiApi\Service\ModelService;
use OCA\OpenAiApi\Service\TaskFileService;
use OCA\OpenAiApi\Service\TaskRunner;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\TaskProcessing\Task;

class ImagesController extends AbstractApiController {
	/** Matches the upper bound OpenAI enforces for `n`. */
	private const MAX_IMAGES = 10;

	public function __construct(
		string $appName,
		IRequest $request,
		private ModelService $modelService,
		private TaskRunner $taskRunner,
		private TaskFileService $taskFileService,
		private IURLGenerator $urlGenerator,
		private ITimeFactory $timeFactory,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function generations(): JSONResponse {
		try {
			$params = $this->request->getParams();
			$prompt = $this->requireString($params['prompt'] ?? null, 'prompt');
			$model = is_string($params['model'] ?? null) ? $params['model'] : null;
			$numberOfImages = $this->toPositiveInt($params['n'] ?? null) ?? 1;
			if ($numberOfImages > self::MAX_IMAGES) {
				throw ApiException::invalidRequest(
					'"n" must be between 1 and ' . self::MAX_IMAGES . '.',
					'n',
				);
			}

			// Unlike OpenAI this defaults to b64_json: a URL would point back at an
			// authenticated Nextcloud route, and clients do not send credentials
			// when fetching generated images.
			$responseFormat = $params['response_format'] ?? 'b64_json';
			if (!in_array($responseFormat, ['b64_json', 'url'], true)) {
				throw ApiException::invalidRequest(
					'"response_format" must be "b64_json" or "url".',
					'response_format',
				);
			}

			$taskTypeId = $this->modelService->resolve($model, ModelService::IMAGE_TASK_TYPES, $this->userId);
			$task = $this->taskRunner->run(new Task(
				$taskTypeId,
				['input' => $prompt, 'numberOfImages' => $numberOfImages],
				Application::APP_ID,
				$this->userId,
			));

			$fileIds = ($task->getOutput() ?? [])['images'] ?? [];
			if (!is_array($fileIds) || $fileIds === []) {
				throw ApiException::serverError('The provider did not return any images.');
			}

			$data = [];
			foreach ($fileIds as $fileId) {
				if (!is_numeric($fileId)) {
					continue;
				}
				$data[] = $responseFormat === 'url'
					? ['url' => $this->urlGenerator->linkToRouteAbsolute(
						'openai_api.taskfile.get',
						['taskId' => $task->getId(), 'fileId' => (int)$fileId],
					)]
					: ['b64_json' => base64_encode($this->taskFileService->getContent($task, (int)$fileId))];
			}

			return new JSONResponse([
				'created' => $this->timeFactory->getTime(),
				'data' => $data,
			]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}
}
