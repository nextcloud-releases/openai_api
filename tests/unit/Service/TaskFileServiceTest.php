<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Tests\unit\Service;

use OCA\OpenAiApi\Service\TaskFileService;
use OCP\Files\File;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class TaskFileServiceTest extends TestCase {
	private IMimeTypeDetector&MockObject $mimeTypeDetector;
	private TaskFileService $taskFileService;

	protected function setUp(): void {
		parent::setUp();
		$this->mimeTypeDetector = $this->createMock(IMimeTypeDetector::class);
		$this->taskFileService = new TaskFileService(
			$this->createMock(IManager::class),
			$this->createMock(IRootFolder::class),
			$this->mimeTypeDetector,
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * @param array<string, string> $secureTypes the downgrade the detector applies
	 */
	private function file(string $storedType, string $content, array $secureTypes): File&MockObject {
		$handle = fopen('php://memory', 'r+b');
		fwrite($handle, $content);
		rewind($handle);

		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn($storedType);
		$file->method('fopen')->willReturn($handle);

		$this->mimeTypeDetector->method('getSecureMimeType')
			->willReturnCallback(static fn (string $type): string => $secureTypes[$type] ?? 'application/octet-stream');

		return $file;
	}

	public function testAnImageKeepsItsType(): void {
		$file = $this->file('image/png', "\x89PNG\r\n\x1a\n", ['image/png' => 'image/png']);

		self::assertSame('image/png', $this->taskFileService->getContentType($file));
	}

	public function testTheStoredTypeIsDowngradedToo(): void {
		// Content that is not recognised leaves only the stored type to go by;
		// serving that unchecked is what would turn a .html file into a page.
		$file = $this->file('text/html', "\x00\x01<script>alert(1)</script>", [
			'text/html' => 'text/plain',
			'application/octet-stream' => 'application/octet-stream',
		]);

		self::assertSame('text/plain', $this->taskFileService->getContentType($file));
	}

	public function testAnUnknownDetectedTypeDoesNotFallBackToTheUncheckedStoredType(): void {
		$file = $this->file('image/svg+xml', '<svg onload="alert(1)"/>', [
			'image/svg+xml' => 'text/plain',
		]);

		self::assertNotSame('image/svg+xml', $this->taskFileService->getContentType($file));
	}
}
