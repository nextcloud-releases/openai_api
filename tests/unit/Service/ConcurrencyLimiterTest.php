<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Tests\unit\Service;

use OCA\OpenAiApi\Exception\ApiException;
use OCA\OpenAiApi\Service\ConcurrencyLimiter;
use OCA\OpenAiApi\Service\Config;
use OCP\AppFramework\Http;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ConcurrencyLimiterTest extends TestCase {
	private ICacheFactory&MockObject $cacheFactory;
	private IMemcache&MockObject $cache;
	private Config&MockObject $config;

	protected function setUp(): void {
		parent::setUp();
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cache = $this->createMock(IMemcache::class);
		$this->config = $this->createMock(Config::class);
		$this->config->method('getRequestTimeout')->willReturn(300);
	}

	private function limiter(int $limit): ConcurrencyLimiter {
		$this->config->method('getMaxConcurrentRequests')->willReturn($limit);
		$this->cacheFactory->method('createDistributed')->willReturn($this->cache);
		return new ConcurrencyLimiter(
			$this->cacheFactory,
			$this->config,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testAcquireClaimsTheFirstFreeSlot(): void {
		$this->cache->expects(self::exactly(2))
			->method('add')
			->willReturnOnConsecutiveCalls(false, true);

		$this->limiter(3)->acquire('alice');
	}

	public function testAcquireThrowsOnceEverySlotIsTaken(): void {
		$this->cache->method('add')->willReturn(false);

		$this->expectException(ApiException::class);
		try {
			$this->limiter(2)->acquire('alice');
		} catch (ApiException $e) {
			self::assertSame(Http::STATUS_TOO_MANY_REQUESTS, $e->getStatus());
			throw $e;
		}
	}

	public function testSlotsExpireOnTheirOwnSoADeadWorkerDoesNotLeakOne(): void {
		$this->cache->expects(self::once())
			->method('add')
			->with(self::anything(), 1, self::greaterThan(300))
			->willReturn(true);

		$this->limiter(1)->acquire('alice');
	}

	public function testReleaseFreesTheClaimedSlot(): void {
		$this->cache->method('add')->willReturn(true);
		$this->cache->expects(self::once())->method('remove');

		$limiter = $this->limiter(1);
		$limiter->acquire('alice');
		$limiter->release();
	}

	public function testNestedAcquiresShareASingleSlot(): void {
		$this->cache->expects(self::once())->method('add')->willReturn(true);
		$this->cache->expects(self::once())->method('remove');

		$limiter = $this->limiter(1);
		$limiter->acquire('alice');
		$limiter->acquire('alice');
		$limiter->release();
		$limiter->release();
	}

	public function testReleaseAfterAFailedAcquireLeavesNoSlotHeld(): void {
		$this->cache->method('add')->willReturn(false);
		$this->cache->expects(self::never())->method('remove');

		$limiter = $this->limiter(1);
		try {
			$limiter->acquire('alice');
		} catch (ApiException) {
		}
		$limiter->release();

		// The depth is back to zero, so the next attempt claims again
		$this->expectException(ApiException::class);
		$limiter->acquire('alice');
	}

	public function testNoLimitIsEnforcedWithoutAUser(): void {
		$this->cache->expects(self::never())->method('add');

		$this->limiter(1)->acquire(null);
	}

	public function testNoLimitIsEnforcedWhenItIsTurnedOff(): void {
		$this->cache->expects(self::never())->method('add');

		$this->limiter(0)->acquire('alice');
	}

	public function testNoLimitIsEnforcedWithoutADistributedCache(): void {
		$this->config->method('getMaxConcurrentRequests')->willReturn(1);
		$this->cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		$limiter = new ConcurrencyLimiter(
			$this->cacheFactory,
			$this->config,
			$this->createMock(LoggerInterface::class),
		);

		$limiter->acquire('alice');
		$limiter->release();
		self::assertTrue(true);
	}

	public function testStatusIsEnforcedWithALimitAndADistributedCache(): void {
		self::assertSame(ConcurrencyLimiter::STATUS_ENFORCED, $this->limiter(5)->getStatus());
	}

	public function testStatusIsDisabledWhenTheLimitIsTurnedOff(): void {
		self::assertSame(ConcurrencyLimiter::STATUS_DISABLED, $this->limiter(0)->getStatus());
	}

	public function testStatusReportsAMissingDistributedCache(): void {
		$this->config->method('getMaxConcurrentRequests')->willReturn(5);
		$this->cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		$limiter = new ConcurrencyLimiter(
			$this->cacheFactory,
			$this->config,
			$this->createMock(LoggerInterface::class),
		);

		self::assertSame(ConcurrencyLimiter::STATUS_NO_CACHE, $limiter->getStatus());
	}
}
