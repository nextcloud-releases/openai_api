<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Settings;

use OCA\OpenAiApi\AppInfo\Application;
use OCA\OpenAiApi\ConfigLexicon;
use OCA\OpenAiApi\Service\ConcurrencyLimiter;
use OCA\OpenAiApi\Service\Config;
use OCA\OpenAiApi\Service\ModelService;
use OCA\OpenAiApi\Service\TaskRunner;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

class Admin implements IDelegatedSettings {
	public function __construct(
		private Config $config,
		private ModelService $modelService,
		private TaskRunner $taskRunner,
		private ConcurrencyLimiter $concurrencyLimiter,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
		private IInitialState $initialState,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('realStreaming', $this->config->isRealStreamingEnabled());
		$this->initialState->provideInitialState('baseUrl', $this->getBaseUrl());
		$this->initialState->provideInitialState('needsWorker', $this->needsWorker());
		$this->initialState->provideInitialState('concurrencyLimit', [
			'status' => $this->concurrencyLimiter->getStatus(),
			'limit' => $this->config->getMaxConcurrentRequests(),
		]);

		Util::addScript(Application::APP_ID, Application::APP_ID . '-settings-admin');
		return new TemplateResponse(Application::APP_ID, 'settings-admin', [], '');
	}

	#[\Override]
	public function getSection(): string {
		return 'ai';
	}

	#[\Override]
	public function getPriority(): int {
		return 70;
	}

	#[\Override]
	public function getName(): ?string {
		return $this->l->t('OpenAI-compatible API');
	}

	#[\Override]
	public function getAuthorizedAppConfig(): array {
		return [
			Application::APP_ID => [
				ConfigLexicon::REAL_STREAMING,
			],
		];
	}

	/**
	 * The base URL clients have to be pointed at.
	 */
	private function getBaseUrl(): string {
		$modelsUrl = $this->urlGenerator->linkToRouteAbsolute('openai_api.models.index');
		return substr($modelsUrl, 0, -strlen('/models'));
	}

	/**
	 * Whether progressive streaming depends on a task processing worker.
	 *
	 * A provider that runs inside the request cannot be polled while it works, so
	 * progressively streamed requests wait for `occ taskprocessing:worker` (or
	 * cron) to pick the scheduled task up.
	 */
	private function needsWorker(): bool {
		foreach ($this->modelService->getAvailableModels(null) as $taskTypeId) {
			if (!in_array($taskTypeId, ModelService::CHAT_TASK_TYPES, true)) {
				continue;
			}
			if ($this->taskRunner->hasSynchronousProvider($taskTypeId)) {
				return true;
			}
		}
		return false;
	}
}
