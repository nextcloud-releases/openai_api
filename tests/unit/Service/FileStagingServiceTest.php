<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Tests\unit\Service;

use OCA\OpenAiApi\AppInfo\Application;
use OCA\OpenAiApi\Exception\ApiException;
use OCA\OpenAiApi\Service\Attachment;
use OCA\OpenAiApi\Service\Config;
use OCA\OpenAiApi\Service\FileStagingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class FileStagingServiceTest extends TestCase {
	private const PREFIX = 'abcdefghij123456';

	private IRootFolder&MockObject $rootFolder;
	private Config&MockObject $config;
	private IUserFolder&MockObject $userFolder;
	private FileStagingService $fileStagingService;

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->config = $this->createMock(Config::class);
		$this->config->method('getMaxAttachmentSize')->willReturn(1024);
		$this->config->method('getStagedFileTtl')->willReturn(3600);

		$this->userFolder = $this->createMock(IUserFolder::class);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($this->userFolder);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(10000);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(self::PREFIX);

		$this->fileStagingService = new FileStagingService(
			$this->rootFolder,
			$this->config,
			$timeFactory,
			$random,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function stagingFolder(string $owner = 'alice', string $mountType = ''): Folder&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($owner);
		$mountPoint = $this->createMock(IMountPoint::class);
		$mountPoint->method('getMountType')->willReturn($mountType);

		$folder = $this->createMock(Folder::class);
		$folder->method('getOwner')->willReturn($user);
		$folder->method('getMountPoint')->willReturn($mountPoint);
		$folder->method('getDirectoryListing')->willReturn([]);

		$this->userFolder->method('get')->with(Application::STAGING_FOLDER)->willReturn($folder);
		return $folder;
	}

	private function stagedFile(int $id): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		return $file;
	}

	public function testStagedFilesCarryTheStagingPrefix(): void {
		$folder = $this->stagingFolder();
		$folder->expects(self::once())
			->method('newFile')
			->with(self::PREFIX . '-report.pdf', 'content')
			->willReturn($this->stagedFile(11));

		self::assertSame([11], $this->fileStagingService->stage('alice', [new Attachment('content', 'report.pdf')]));
	}

	public function testAnOversizedAttachmentIsRejected(): void {
		$this->userFolder->expects(self::never())->method('get');

		try {
			$this->fileStagingService->stage('alice', [new Attachment(str_repeat('a', 1025), 'big.bin')]);
			self::fail('Expected an ApiException');
		} catch (ApiException $e) {
			self::assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $e->getStatus());
		}
	}

	public function testAttachmentsAreAlsoLimitedInTotal(): void {
		$this->userFolder->expects(self::never())->method('get');

		$this->expectException(ApiException::class);
		$this->fileStagingService->stage('alice', [
			new Attachment(str_repeat('a', 600), 'one.bin'),
			new Attachment(str_repeat('b', 600), 'two.bin'),
		]);
	}

	public function testAStagingPathOwnedBySomebodyElseIsRefused(): void {
		$folder = $this->stagingFolder(owner: 'mallory');
		$folder->expects(self::never())->method('newFile');

		try {
			$this->fileStagingService->stage('alice', [new Attachment('secret', 'note.txt')]);
			self::fail('Expected an ApiException');
		} catch (ApiException $e) {
			self::assertSame(Http::STATUS_PRECONDITION_FAILED, $e->getStatus());
		}
	}

	public function testAStagingPathOnAShareMountIsRefused(): void {
		$folder = $this->stagingFolder(mountType: 'shared');
		$folder->expects(self::never())->method('newFile');

		$this->expectException(ApiException::class);
		$this->fileStagingService->stage('alice', [new Attachment('secret', 'note.txt')]);
	}

	public function testAStagingPathTakenByAFileIsRefused(): void {
		$this->userFolder->method('get')->willReturn($this->createMock(File::class));

		$this->expectException(ApiException::class);
		$this->fileStagingService->stage('alice', [new Attachment('content', 'note.txt')]);
	}

	public function testTheFolderIsCreatedWhenItDoesNotExistYet(): void {
		$this->userFolder->method('get')->willThrowException(new NotFoundException());

		$created = $this->createMock(Folder::class);
		$created->method('newFile')->willReturn($this->stagedFile(12));
		$this->userFolder->expects(self::once())
			->method('newFolder')
			->with(Application::STAGING_FOLDER)
			->willReturn($created);

		self::assertSame([12], $this->fileStagingService->stage('alice', [new Attachment('content', 'note.txt')]));
	}

	public function testTheSweepOnlyDeletesFilesThisAppStaged(): void {
		$expired = $this->createMock(File::class);
		$expired->method('getName')->willReturn(self::PREFIX . '-old.png');
		$expired->method('getMTime')->willReturn(1);
		$expired->expects(self::once())->method('delete');

		$foreign = $this->createMock(File::class);
		$foreign->method('getName')->willReturn('holiday-photo.png');
		$foreign->method('getMTime')->willReturn(1);
		$foreign->expects(self::never())->method('delete');

		$fresh = $this->createMock(File::class);
		$fresh->method('getName')->willReturn(self::PREFIX . '-new.png');
		$fresh->method('getMTime')->willReturn(9999);
		$fresh->expects(self::never())->method('delete');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$mountPoint = $this->createMock(IMountPoint::class);
		$mountPoint->method('getMountType')->willReturn('');

		$folder = $this->createMock(Folder::class);
		$folder->method('getOwner')->willReturn($user);
		$folder->method('getMountPoint')->willReturn($mountPoint);
		$folder->method('getDirectoryListing')->willReturn([$expired, $foreign, $fresh]);
		$folder->method('newFile')->willReturn($this->stagedFile(13));
		$this->userFolder->method('get')->willReturn($folder);

		$this->fileStagingService->stage('alice', [new Attachment('content', 'note.txt')]);
	}
}
