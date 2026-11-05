<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Tests\unit\Controller;

use OCA\OpenAiApi\AppInfo\Application;
use OCA\OpenAiApi\Controller\TaskFileController;
use OCA\OpenAiApi\Service\TaskFileService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\IRequest;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class TaskFileControllerTest extends TestCase {
	private IManager&MockObject $taskProcessingManager;
	private TaskFileService&MockObject $taskFileService;
	private TaskFileController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->taskProcessingManager = $this->createMock(IManager::class);
		$this->taskFileService = $this->createMock(TaskFileService::class);
		$this->controller = new TaskFileController(
			Application::APP_ID,
			$this->createMock(IRequest::class),
			$this->taskProcessingManager,
			$this->taskFileService,
			'alice',
		);
	}

	private function task(string $appId): Task {
		$task = new Task('core:text2image', ['input' => 'a cat'], $appId, 'alice');
		$task->setId(5);
		return $task;
	}

	private function serve(string $contentType, string $name = 'output.png'): StreamResponse|JSONResponse {
		$this->taskProcessingManager->method('getUserTask')->willReturn($this->task(Application::APP_ID));

		$file = $this->createMock(File::class);
		$file->method('fopen')->willReturn(fopen('php://memory', 'rb'));
		$file->method('getName')->willReturn($name);
		$this->taskFileService->method('getFile')->willReturn($file);
		$this->taskFileService->method('getContentType')->willReturn($contentType);

		return $this->controller->get(5, 9);
	}

	public function testATaskOfAnotherAppIsNotServed(): void {
		$this->taskProcessingManager->method('getUserTask')->willReturn($this->task('assistant'));
		$this->taskFileService->expects(self::never())->method('getFile');

		$response = $this->controller->get(5, 9);

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testAnImageIsServedInline(): void {
		$response = $this->serve('image/png');

		self::assertInstanceOf(StreamResponse::class, $response);
		self::assertStringStartsWith('inline;', $response->getHeaders()['Content-Disposition']);
	}

	public function testAnythingElseIsServedAsAnAttachment(): void {
		$response = $this->serve('text/plain', 'output.html');

		self::assertInstanceOf(StreamResponse::class, $response);
		self::assertStringStartsWith('attachment;', $response->getHeaders()['Content-Disposition']);
	}

	public function testServedFilesMayNotLoadOrRunAnything(): void {
		$response = $this->serve('image/png');

		self::assertInstanceOf(EmptyContentSecurityPolicy::class, $response->getContentSecurityPolicy());
		self::assertStringContainsString(
			"default-src 'none'",
			$response->getContentSecurityPolicy()->buildPolicy(),
		);
	}
}
