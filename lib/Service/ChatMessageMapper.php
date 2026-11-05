<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Service;

use OCA\OpenAiApi\Exception\ApiException;

/**
 * Maps an OpenAI `messages` array onto the input slots of the chat task types.
 *
 * The slots follow the convention established by the existing providers and
 * consumers (integration_openai, context_agent): `history` carries JSON encoded
 * message objects rather than plain alternating strings, so roles, tool calls
 * and structured content survive the mapping. Only the final message is
 * special-cased - it becomes `input`, or `tool_message` when the conversation
 * ends in tool results.
 */
class ChatMessageMapper {
	private const EXTENSIONS = [
		'image/png' => 'png',
		'image/jpeg' => 'jpg',
		'image/jpg' => 'jpg',
		'image/gif' => 'gif',
		'image/webp' => 'webp',
		'audio/mpeg' => 'mp3',
		'audio/mp3' => 'mp3',
		'audio/wav' => 'wav',
		'audio/x-wav' => 'wav',
		'audio/ogg' => 'ogg',
		'audio/webm' => 'webm',
		'audio/flac' => 'flac',
		'video/mp4' => 'mp4',
		'application/pdf' => 'pdf',
		'text/plain' => 'txt',
		'text/rtf' => 'rtf',
	];

	public function __construct(
		private ToolCallMapper $toolCallMapper,
	) {
	}

	/**
	 * @param list<mixed> $messages the OpenAI `messages` array
	 * @param list<mixed>|null $tools the OpenAI `tools` array
	 * @throws ApiException
	 */
	public function map(array $messages, ?array $tools): MappedChatRequest {
		if ($messages === []) {
			throw ApiException::invalidRequest('At least one message is required.', 'messages');
		}
		if ($tools !== null && !array_is_list($tools)) {
			throw ApiException::invalidRequest('"tools" must be an array.', 'tools');
		}

		$systemPrompts = [];
		$conversation = [];
		foreach ($messages as $message) {
			if (!is_array($message) || !isset($message['role']) || !is_string($message['role'])) {
				throw ApiException::invalidRequest('Every message needs a role.', 'messages');
			}
			if ($message['role'] === 'system' || $message['role'] === 'developer') {
				$systemPrompts[] = $this->contentToText($message['content'] ?? '');
				continue;
			}
			$conversation[] = $message;
		}

		if ($conversation === []) {
			throw ApiException::invalidRequest(
				'A conversation needs at least one non-system message.',
				'messages',
			);
		}

		// Split off the trailing tool results, or the final user turn
		$toolMessage = '';
		$input = '';
		$attachments = [];

		$last = $conversation[array_key_last($conversation)];
		if ($last['role'] === 'tool') {
			$trailing = [];
			while ($conversation !== [] && $conversation[array_key_last($conversation)]['role'] === 'tool') {
				array_unshift($trailing, array_pop($conversation));
			}
			$toolMessage = json_encode(array_map(
				fn (array $message): array => [
					'name' => (string)($message['name'] ?? ''),
					'content' => $this->toolContentToText($message['content'] ?? ''),
					'tool_call_id' => (string)($message['tool_call_id'] ?? ''),
				],
				$trailing,
			), JSON_UNESCAPED_SLASHES) ?: '';
		} elseif ($last['role'] === 'user') {
			array_pop($conversation);
			$input = $this->contentToText($last['content'] ?? '');
			$attachments = $this->extractAttachments($last['content'] ?? '');
		} else {
			throw ApiException::invalidRequest(
				'The last message must come from the user or carry tool results; '
				. 'assistant prefill is not supported.',
				'messages',
			);
		}

		$history = [];
		foreach ($conversation as $message) {
			$history[] = $this->encodeHistoryEntry($message);
		}

		return new MappedChatRequest(
			implode("\n\n", array_filter($systemPrompts, static fn (string $p): bool => $p !== '')),
			$input,
			$history,
			$toolMessage,
			json_encode($tools ?? [], JSON_UNESCAPED_SLASHES) ?: '[]',
			$attachments,
		);
	}

	/**
	 * Flatten a mapped request into a single prompt.
	 *
	 * Used for task types that have no `history` slot, where dropping the earlier
	 * turns would silently strip the conversation down to its last message.
	 */
	public function flattenToPrompt(MappedChatRequest $request): string {
		$lines = [];
		if ($request->systemPrompt !== '') {
			$lines[] = $request->systemPrompt;
		}
		foreach ($request->history as $entry) {
			$decoded = json_decode($entry, true);
			if (!is_array($decoded)) {
				continue;
			}
			$role = (string)($decoded['role'] ?? 'user');
			$text = $this->contentToText($decoded['content'] ?? '');
			if ($text === '') {
				continue;
			}
			$lines[] = ucfirst($role) . ': ' . $text;
		}
		if ($request->input !== '') {
			$lines[] = 'User: ' . $request->input;
		}
		return implode("\n\n", $lines);
	}

	/**
	 * @throws ApiException
	 */
	private function encodeHistoryEntry(array $message): string {
		$role = $message['role'];
		if ($role === 'human') {
			$role = 'user';
		}
		if (!in_array($role, ['user', 'assistant', 'tool'], true)) {
			throw ApiException::invalidRequest('Unsupported message role "' . $role . '".', 'messages');
		}

		$entry = ['role' => $role];

		// Structured content is passed on so that providers can forward the content
		// parts; only the final turn needs staging as files.
		$content = $message['content'] ?? '';

		if ($role === 'tool') {
			$entry['content'] = $this->toolContentToText($content);
			$entry['name'] = (string)($message['name'] ?? '');
			$entry['tool_call_id'] = (string)($message['tool_call_id'] ?? '');
		} else {
			$entry['content'] = is_array($content)
				? $this->normaliseHistoryContent($content)
				: $this->contentToText($content);
		}

		if ($role === 'assistant' && isset($message['tool_calls']) && is_array($message['tool_calls'])) {
			$entry['tool_calls'] = $this->toolCallMapper->toProvider($message['tool_calls']);
		}

		return json_encode($entry, JSON_UNESCAPED_SLASHES) ?: '{}';
	}

	/**
	 * Rebuild the content parts of an earlier turn from the fields that are known.
	 *
	 * Providers forward these parts to their inference backend as they are, and a
	 * backend may fetch any URL it finds in them. Only inline data is therefore
	 * accepted, the same as for the final turn, and parts are rebuilt rather than
	 * copied so that no unknown field - a `video_url`, say - rides along.
	 *
	 * @return list<array<string, mixed>|string>
	 * @throws ApiException
	 */
	private function normaliseHistoryContent(array $content): array {
		$parts = [];
		foreach ($content as $part) {
			if (is_string($part)) {
				$parts[] = $part;
				continue;
			}
			if (!is_array($part) || !is_string($part['type'] ?? null)) {
				throw ApiException::invalidRequest('Malformed content part.', 'messages');
			}
			switch ($part['type']) {
				case 'text':
					if (!is_string($part['text'] ?? null)) {
						throw ApiException::invalidRequest('Malformed text content part.', 'messages');
					}
					$parts[] = ['type' => 'text', 'text' => $part['text']];
					break;
				case 'refusal':
					if (!is_string($part['refusal'] ?? null)) {
						throw ApiException::invalidRequest('Malformed refusal content part.', 'messages');
					}
					$parts[] = ['type' => 'refusal', 'refusal' => $part['refusal']];
					break;
				case 'image_url':
					$url = $part['image_url']['url'] ?? null;
					if (!is_string($url)) {
						throw ApiException::invalidRequest('Malformed image_url content part.', 'messages');
					}
					$this->assertInlineDataUrl($url);
					$imageUrl = ['url' => $url];
					if (is_string($part['image_url']['detail'] ?? null)) {
						$imageUrl['detail'] = $part['image_url']['detail'];
					}
					$parts[] = ['type' => 'image_url', 'image_url' => $imageUrl];
					break;
				case 'input_audio':
					$data = $part['input_audio']['data'] ?? null;
					$format = $part['input_audio']['format'] ?? 'wav';
					if (!is_string($data) || !is_string($format)) {
						throw ApiException::invalidRequest('Malformed input_audio content part.', 'messages');
					}
					$parts[] = ['type' => 'input_audio', 'input_audio' => ['data' => $data, 'format' => $format]];
					break;
				case 'file':
					$fileData = $part['file']['file_data'] ?? null;
					if (!is_string($fileData)) {
						throw ApiException::invalidRequest(
							'Only inline file_data is supported in file content parts.',
							'messages',
						);
					}
					$this->assertInlineDataUrl($fileData);
					$file = ['file_data' => $fileData];
					if (is_string($part['file']['filename'] ?? null)) {
						$file['filename'] = basename($part['file']['filename']);
					}
					$parts[] = ['type' => 'file', 'file' => $file];
					break;
				default:
					throw ApiException::invalidRequest(
						'Unsupported content part type "' . $part['type'] . '".',
						'messages',
					);
			}
		}
		return $parts;
	}

	/**
	 * @throws ApiException
	 */
	private function assertInlineDataUrl(string $url): void {
		if (!str_starts_with($url, 'data:')) {
			throw ApiException::invalidRequest(
				'Only inline data: URLs are supported for attachments; fetching remote URLs is not implemented.',
				'messages',
			);
		}
	}

	/**
	 * Concatenate the text parts of a message content.
	 */
	private function contentToText(mixed $content): string {
		if (is_string($content)) {
			return $content;
		}
		if (!is_array($content)) {
			return '';
		}
		$texts = [];
		foreach ($content as $part) {
			if (is_string($part)) {
				$texts[] = $part;
			} elseif (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
				$texts[] = $part['text'];
			}
		}
		return implode('', $texts);
	}

	/**
	 * Tool results may be structured; providers expect a string.
	 */
	private function toolContentToText(mixed $content): string {
		if (is_string($content)) {
			return $content;
		}
		$text = $this->contentToText($content);
		if ($text !== '') {
			return $text;
		}
		return json_encode($content, JSON_UNESCAPED_SLASHES) ?: '';
	}

	/**
	 * @return list<Attachment>
	 * @throws ApiException
	 */
	private function extractAttachments(mixed $content): array {
		if (!is_array($content)) {
			return [];
		}

		$attachments = [];
		foreach ($content as $index => $part) {
			if (!is_array($part) || !isset($part['type']) || !is_string($part['type'])) {
				continue;
			}
			switch ($part['type']) {
				case 'text':
					break;
				case 'image_url':
					$url = $part['image_url']['url'] ?? null;
					if (!is_string($url)) {
						throw ApiException::invalidRequest('Malformed image_url content part.', 'messages');
					}
					$attachments[] = $this->decodeDataUrl($url, 'attachment-' . $index);
					break;
				case 'input_audio':
					$data = $part['input_audio']['data'] ?? null;
					$format = $part['input_audio']['format'] ?? 'wav';
					if (!is_string($data) || !is_string($format)) {
						throw ApiException::invalidRequest('Malformed input_audio content part.', 'messages');
					}
					$decoded = base64_decode($data, true);
					if ($decoded === false) {
						throw ApiException::invalidRequest('input_audio data is not valid base64.', 'messages');
					}
					$attachments[] = new Attachment(
						$decoded,
						'attachment-' . $index . '.' . preg_replace('/[^a-z0-9]/i', '', $format),
					);
					break;
				case 'file':
					$fileData = $part['file']['file_data'] ?? null;
					if (!is_string($fileData)) {
						throw ApiException::invalidRequest(
							'Only inline file_data is supported in file content parts.',
							'messages',
						);
					}
					$filename = $part['file']['filename'] ?? null;
					$attachment = $this->decodeDataUrl($fileData, 'attachment-' . $index);
					if (is_string($filename) && $filename !== '') {
						$attachment = new Attachment($attachment->content, basename($filename));
					}
					$attachments[] = $attachment;
					break;
				default:
					throw ApiException::invalidRequest(
						'Unsupported content part type "' . $part['type'] . '".',
						'messages',
					);
			}
		}
		return $attachments;
	}

	/**
	 * @throws ApiException
	 */
	private function decodeDataUrl(string $url, string $basename): Attachment {
		$this->assertInlineDataUrl($url);
		if (preg_match('/^data:([^;,]*)((?:;[^,]*)*),(.*)$/s', $url, $matches) !== 1) {
			throw ApiException::invalidRequest('Malformed data: URL.', 'messages');
		}

		[, $mime, $parameters, $payload] = $matches;
		if (str_contains($parameters, ';base64')) {
			$content = base64_decode($payload, true);
			if ($content === false) {
				throw ApiException::invalidRequest('data: URL payload is not valid base64.', 'messages');
			}
		} else {
			$content = rawurldecode($payload);
		}

		$extension = self::EXTENSIONS[strtolower($mime)] ?? 'bin';
		return new Attachment($content, $basename . '.' . $extension);
	}
}
