<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Service;

use OCA\OpenAiApi\AppInfo\Application;
use OCA\OpenAiApi\Exception\ApiException;
use OCP\AppFramework\Http;
use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;

/**
 * Caps how many requests of one user may occupy a PHP worker at the same time.
 *
 * Every endpoint drives a task to a terminal state inside the request, so a
 * request holds its worker until the task finishes or the request timeout is
 * reached. Without a cap a single account can occupy the whole PHP worker pool.
 *
 * A slot is a key claimed in the distributed cache. The keys carry a TTL, so a
 * worker that dies without releasing its slot frees it on its own. Acquiring is
 * reentrant: nested calls within a request share the slot the outermost call
 * claimed.
 */
class ConcurrencyLimiter {
	/** Grace period on top of the request timeout before a slot frees itself. */
	private const SLOT_TTL_MARGIN = 60;

	/** The limit is in force. */
	public const STATUS_ENFORCED = 'enforced';
	/** The administrator set the limit to 0. */
	public const STATUS_DISABLED = 'disabled';
	/** No distributed cache with atomic operations is configured, so nothing can be enforced. */
	public const STATUS_NO_CACHE = 'no_cache';

	private ?IMemcache $cache;
	private ?string $slot = null;
	private int $depth = 0;

	public function __construct(
		ICacheFactory $cacheFactory,
		private Config $config,
		private LoggerInterface $logger,
	) {
		// Claiming a slot has to be atomic, which only the memcache interface offers.
		$cache = $cacheFactory->createDistributed(Application::APP_ID . '-concurrency-');
		$this->cache = $cache instanceof IMemcache ? $cache : null;
	}

	/**
	 * Whether the limit is actually enforced, and if not, why.
	 *
	 * @return self::STATUS_*
	 */
	public function getStatus(): string {
		if ($this->config->getMaxConcurrentRequests() <= 0) {
			return self::STATUS_DISABLED;
		}
		if ($this->cache === null) {
			return self::STATUS_NO_CACHE;
		}
		return self::STATUS_ENFORCED;
	}

	/**
	 * Claim a slot for the user, unless this request already holds one.
	 *
	 * @throws ApiException if the user has no slot left
	 */
	public function acquire(?string $userId): void {
		if ($this->depth++ > 0) {
			return;
		}
		$this->slot = $this->claim($userId);
	}

	/**
	 * Release the slot once the outermost acquire is done with it.
	 */
	public function release(): void {
		if ($this->depth > 0) {
			$this->depth--;
		}
		if ($this->depth > 0 || $this->slot === null) {
			return;
		}
		try {
			$this->cache?->remove($this->slot);
		} catch (\Throwable $e) {
			$this->logger->debug('Could not release the concurrency slot', ['exception' => $e]);
		}
		$this->slot = null;
	}

	/**
	 * @return string|null the claimed key, or null when no limit is enforced
	 * @throws ApiException
	 */
	private function claim(?string $userId): ?string {
		$limit = $this->config->getMaxConcurrentRequests();
		if ($this->cache === null || $limit <= 0 || $userId === null) {
			return null;
		}

		$ttl = $this->config->getRequestTimeout() + self::SLOT_TTL_MARGIN;
		$prefix = hash('sha256', $userId) . '-';
		for ($slot = 0; $slot < $limit; $slot++) {
			if ($this->cache->add($prefix . $slot, 1, $ttl) === true) {
				return $prefix . $slot;
			}
		}

		throw new ApiException(
			'You already have ' . $limit . ' requests in flight. Wait for one of them to finish.',
			Http::STATUS_TOO_MANY_REQUESTS,
			'server_error',
			null,
			'rate_limit_exceeded',
		);
	}
}
