<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\AppInfo;

use OCA\OpenAiApi\ConfigLexicon;
use OCA\OpenAiApi\Middleware\CsrfMiddleware;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'openai_api';

	/**
	 * Name of the folder in the user's root that request-scoped input files are
	 * staged in. Task Processing only accepts file ids the task's user has a
	 * mount for, so inline attachments cannot be kept in appdata.
	 */
	public const STAGING_FOLDER = '.openai_api';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerConfigLexicon(ConfigLexicon::class);
		$context->registerMiddleware(CsrfMiddleware::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
