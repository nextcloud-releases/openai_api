<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Tests\unit\Middleware;

use OCA\OpenAiApi\Controller\ChatController;
use OCA\OpenAiApi\Exception\ApiException;
use OCA\OpenAiApi\Middleware\CsrfMiddleware;
use OCA\OpenAiApi\Response\ApiErrorResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\ISession;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class CsrfMiddlewareTest extends TestCase {
	private IRequest&MockObject $request;
	private ISession&MockObject $session;
	private CsrfMiddleware $middleware;
	private ChatController&MockObject $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->session = $this->createMock(ISession::class);
		$this->middleware = new CsrfMiddleware($this->request, $this->session);
		$this->controller = $this->createMock(ChatController::class);
	}

	private function context(
		bool $appPassword,
		string $authorization,
		bool $passesCsrf,
		string $method = 'POST',
	): void {
		$this->request->method('getMethod')->willReturn($method);
		$this->session->method('exists')->with('app_password')->willReturn($appPassword);
		$this->request->method('getHeader')->with('Authorization')->willReturn($authorization);
		$this->request->method('passesCSRFCheck')->willReturn($passesCsrf);
	}

	public function testAnAppPasswordRequestNeedsNoCsrfToken(): void {
		$this->context(appPassword: true, authorization: '', passesCsrf: false);

		$this->middleware->beforeController($this->controller, 'completions');
		self::assertTrue(true);
	}

	public function testABasicAuthRequestNeedsNoCsrfToken(): void {
		$this->context(appPassword: false, authorization: 'Basic YWxpY2U6c2VjcmV0', passesCsrf: false);

		$this->middleware->beforeController($this->controller, 'completions');
		self::assertTrue(true);
	}

	public function testABrowserSessionWithoutACsrfTokenIsRejected(): void {
		$this->context(appPassword: false, authorization: '', passesCsrf: false);

		try {
			$this->middleware->beforeController($this->controller, 'completions');
			self::fail('Expected an ApiException');
		} catch (ApiException $e) {
			self::assertSame(Http::STATUS_PRECONDITION_FAILED, $e->getStatus());
		}
	}

	public function testABrowserSessionWithACsrfTokenIsAllowed(): void {
		$this->context(appPassword: false, authorization: '', passesCsrf: true);

		$this->middleware->beforeController($this->controller, 'completions');
		self::assertTrue(true);
	}

	public function testAReadOnlyRequestNeedsNoCsrfToken(): void {
		// A generated image is fetched with a plain GET, which has nothing to forge
		$this->context(appPassword: false, authorization: '', passesCsrf: false, method: 'GET');

		$this->middleware->beforeController($this->controller, 'get');
		self::assertTrue(true);
	}

	public function testControllersOfOtherAppsAreLeftAlone(): void {
		$this->request->method('getMethod')->willReturn('POST');
		$this->session->expects(self::never())->method('exists');

		$this->middleware->beforeController($this->createMock(Controller::class), 'index');
		self::assertTrue(true);
	}

	public function testTheRejectionIsRenderedInTheOpenAiErrorEnvelope(): void {
		$exception = ApiException::invalidRequest('nope', 'messages');

		$response = $this->middleware->afterException($this->controller, 'completions', $exception);

		self::assertInstanceOf(ApiErrorResponse::class, $response);
		self::assertSame(
			['error' => ['message' => 'nope', 'type' => 'invalid_request_error', 'param' => 'messages', 'code' => null]],
			$response->getData(),
		);
	}

	public function testOtherExceptionsAreLeftToTheNextMiddleware(): void {
		$exception = new \RuntimeException('boom');

		$this->expectExceptionObject($exception);
		$this->middleware->afterException($this->controller, 'completions', $exception);
	}
}
