<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Middleware;

use OCA\OpenAiApi\Controller\AbstractApiController;
use OCA\OpenAiApi\Exception\ApiException;
use OCA\OpenAiApi\Response\ApiErrorResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\ISession;

/**
 * Keeps the CSRF exemption of the API routes from applying to browser sessions.
 *
 * The routes are `NoCSRFRequired` because an OpenAI client cannot obtain a CSRF
 * token, but that attribute also disables the strict cookie check, so a session
 * cookie would be enough to drive the API from another site. Requests that
 * authenticated with an app password are left alone - those cannot be forged by
 * a browser - and everything else has to pass the regular CSRF check.
 */
class CsrfMiddleware extends Middleware {
	/** Methods that carry no side effect and therefore nothing to forge. */
	private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

	public function __construct(
		private IRequest $request,
		private ISession $session,
	) {
	}

	/**
	 * @throws ApiException
	 */
	#[\Override]
	public function beforeController(Controller $controller, string $methodName): void {
		if (!$controller instanceof AbstractApiController) {
			return;
		}
		if (in_array($this->request->getMethod(), self::SAFE_METHODS, true)) {
			return;
		}
		if ($this->isTokenAuthenticated()) {
			return;
		}
		if ($this->request->passesCSRFCheck()) {
			return;
		}
		throw new ApiException(
			'CSRF check failed. Authenticate with an app password instead of a browser session.',
			Http::STATUS_PRECONDITION_FAILED,
			'invalid_request_error',
		);
	}

	#[\Override]
	public function afterException(Controller $controller, string $methodName, \Exception $exception) {
		if ($controller instanceof AbstractApiController && $exception instanceof ApiException) {
			return new ApiErrorResponse($exception);
		}
		throw $exception;
	}

	/**
	 * Whether the request authenticated with credentials rather than a session.
	 *
	 * `app_password` is set for both Basic and Bearer logins that used an app
	 * password. The `Authorization` header additionally covers a Basic login
	 * with the account password, which sets up a session instead; a cross-site
	 * request cannot set that header without a preflight.
	 */
	private function isTokenAuthenticated(): bool {
		return $this->session->exists('app_password')
			|| $this->request->getHeader('Authorization') !== '';
	}
}
