<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi;

use OCP\Config\Lexicon\Entry;
use OCP\Config\Lexicon\ILexicon;
use OCP\Config\Lexicon\Strictness;
use OCP\Config\ValueType;

/**
 * Config Lexicon for the OpenAI-compatible API.
 *
 * {@see ILexicon}
 */
class ConfigLexicon implements ILexicon {
	public const REAL_STREAMING = 'real_streaming';
	public const REQUEST_TIMEOUT = 'request_timeout';
	public const POLL_INTERVAL_MS = 'poll_interval_ms';
	public const STAGED_FILE_TTL = 'staged_file_ttl';
	public const MAX_CONCURRENT_REQUESTS = 'max_concurrent_requests';
	public const MAX_ATTACHMENT_SIZE = 'max_attachment_size';

	public const REAL_STREAMING_DEFAULT = true;
	public const REQUEST_TIMEOUT_DEFAULT = 300;
	public const POLL_INTERVAL_MS_DEFAULT = 500;
	public const STAGED_FILE_TTL_DEFAULT = 3600;
	public const MAX_CONCURRENT_REQUESTS_DEFAULT = 5;
	public const MAX_ATTACHMENT_SIZE_DEFAULT = 52428800;

	#[\Override]
	public function getStrictness(): Strictness {
		return Strictness::NOTICE;
	}

	#[\Override]
	public function getAppConfigs(): array {
		return [
			new Entry(
				self::REAL_STREAMING,
				ValueType::BOOL,
				defaultRaw: self::REAL_STREAMING_DEFAULT,
				definition: 'Whether "stream": true requests emit provider output progressively.'
					. ' When disabled, the request is run to completion and the answer is emitted as a single chunk,'
					. ' which holds a PHP worker for less time per request.',
			),
			new Entry(
				self::REQUEST_TIMEOUT,
				ValueType::INT,
				defaultRaw: self::REQUEST_TIMEOUT_DEFAULT,
				definition: 'Seconds to wait for a task to reach a terminal state before giving up and cancelling it.',
			),
			new Entry(
				self::POLL_INTERVAL_MS,
				ValueType::INT,
				defaultRaw: self::POLL_INTERVAL_MS_DEFAULT,
				definition: 'Milliseconds between task polls while streaming. Task Processing throttles'
					. ' intermediate output to one database write every 2 seconds, so lower values do not'
					. ' produce smaller chunks.',
			),
			new Entry(
				self::STAGED_FILE_TTL,
				ValueType::INT,
				defaultRaw: self::STAGED_FILE_TTL_DEFAULT,
				definition: 'Seconds after which staged input files of abandoned requests are deleted.',
			),
			new Entry(
				self::MAX_CONCURRENT_REQUESTS,
				ValueType::INT,
				defaultRaw: self::MAX_CONCURRENT_REQUESTS_DEFAULT,
				definition: 'How many requests of one user may occupy a PHP worker at the same time.'
					. ' Every request holds its worker until the task finishes, so this keeps a single'
					. ' account from exhausting the worker pool. Set to 0 to disable the limit.'
					. ' Requires a distributed memory cache to be configured.',
			),
			new Entry(
				self::MAX_ATTACHMENT_SIZE,
				ValueType::INT,
				defaultRaw: self::MAX_ATTACHMENT_SIZE_DEFAULT,
				definition: 'Maximum size in bytes of a single inline attachment, and of all attachments'
					. ' of one request taken together.',
			),
		];
	}

	#[\Override]
	public function getUserConfigs(): array {
		return [];
	}
}
