<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Tests\unit\Controller;

use OCA\OpenAiApi\AppInfo\Application;
use OCA\OpenAiApi\Controller\ImagesController;
use OCA\OpenAiApi\Service\ModelService;
use OCA\OpenAiApi\Service\TaskFileService;
use OCA\OpenAiApi\Service\TaskRunner;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToImage;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class ImagesControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private ModelService&MockObject $modelService;
	private TaskRunner&MockObject $taskRunner;
	private ImagesController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->modelService = $this->createMock(ModelService::class);
		$this->modelService->method('resolve')->willReturn(TextToImage::ID);
		$this->taskRunner = $this->createMock(TaskRunner::class);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1000);

		$this->controller = new ImagesController(
			Application::APP_ID,
			$this->request,
			$this->modelService,
			$this->taskRunner,
			$this->createMock(TaskFileService::class),
			$this->createMock(IURLGenerator::class),
			$timeFactory,
			'alice',
		);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function request(array $params): void {
		$this->request->method('getParams')->willReturn($params + ['prompt' => 'a cat']);
	}

	public function testMoreImagesThanOpenAiAllowsIsRejected(): void {
		$this->request(['n' => 100000]);
		$this->taskRunner->expects(self::never())->method('run');

		$response = $this->controller->generations();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('n', $response->getData()['error']['param']);
	}

	public function testTheUpperBoundIsStillAccepted(): void {
		$this->request(['n' => 10]);

		$task = new Task(TextToImage::ID, ['input' => 'a cat'], Application::APP_ID, 'alice');
		$task->setId(1);
		$task->setOutput(['images' => []]);
		$this->taskRunner->expects(self::once())
			->method('run')
			->with(self::callback(static fn (Task $t): bool => $t->getInput()['numberOfImages'] === 10))
			->willReturn($task);

		$this->controller->generations();
	}
}
