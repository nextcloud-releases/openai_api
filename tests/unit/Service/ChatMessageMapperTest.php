<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAiApi\Tests\unit\Service;

use OCA\OpenAiApi\Exception\ApiException;
use OCA\OpenAiApi\Service\ChatMessageMapper;
use OCA\OpenAiApi\Service\ToolCallMapper;
use Test\TestCase;

class ChatMessageMapperTest extends TestCase {
	private ChatMessageMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new ChatMessageMapper(new ToolCallMapper());
	}

	public function testSingleUserMessage(): void {
		$result = $this->mapper->map([
			['role' => 'system', 'content' => 'Be brief.'],
			['role' => 'user', 'content' => 'Hi'],
		], null);

		self::assertSame('Be brief.', $result->systemPrompt);
		self::assertSame('Hi', $result->input);
		self::assertSame([], $result->history);
		self::assertSame('', $result->toolMessage);
		self::assertSame('[]', $result->tools);
		self::assertSame([], $result->attachments);
	}

	public function testSystemMessagesAreConcatenated(): void {
		$result = $this->mapper->map([
			['role' => 'system', 'content' => 'One.'],
			['role' => 'developer', 'content' => 'Two.'],
			['role' => 'user', 'content' => 'Hi'],
		], null);

		self::assertSame("One.\n\nTwo.", $result->systemPrompt);
	}

	public function testEarlierTurnsBecomeJsonHistory(): void {
		$result = $this->mapper->map([
			['role' => 'user', 'content' => 'A'],
			['role' => 'assistant', 'content' => 'B'],
			['role' => 'user', 'content' => 'C'],
		], null);

		self::assertSame([
			'{"role":"user","content":"A"}',
			'{"role":"assistant","content":"B"}',
		], $result->history);
		self::assertSame('C', $result->input);
	}

	public function testHumanRoleIsNormalisedToUser(): void {
		$result = $this->mapper->map([
			['role' => 'human', 'content' => 'A'],
			['role' => 'assistant', 'content' => 'B'],
			['role' => 'user', 'content' => 'C'],
		], null);

		self::assertSame('{"role":"user","content":"A"}', $result->history[0]);
	}

	public function testTrailingToolResultsBecomeToolMessage(): void {
		$result = $this->mapper->map([
			['role' => 'user', 'content' => 'weather?'],
			['role' => 'assistant', 'content' => null, 'tool_calls' => [[
				'id' => 'call_1',
				'type' => 'function',
				'function' => ['name' => 'weather', 'arguments' => '{"city":"Berlin"}'],
			]]],
			['role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'weather', 'content' => 'sunny'],
		], null);

		self::assertSame('', $result->input);
		self::assertSame(
			'[{"name":"weather","content":"sunny","tool_call_id":"call_1"}]',
			$result->toolMessage,
		);
		self::assertSame(
			'{"role":"assistant","content":"","tool_calls":[{"id":"call_1","name":"weather","args":{"city":"Berlin"}}]}',
			$result->history[1],
		);
	}

	public function testParallelToolResultsAreAllForwarded(): void {
		$result = $this->mapper->map([
			['role' => 'user', 'content' => 'go'],
			['role' => 'assistant', 'content' => ''],
			['role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'a', 'content' => 'x'],
			['role' => 'tool', 'tool_call_id' => 'call_2', 'name' => 'b', 'content' => 'y'],
		], null);

		$decoded = json_decode($result->toolMessage, true);
		self::assertCount(2, $decoded);
		self::assertSame('call_2', $decoded[1]['tool_call_id']);
	}

	public function testToolResultsInTheMiddleStayInHistory(): void {
		$result = $this->mapper->map([
			['role' => 'user', 'content' => 'go'],
			['role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'a', 'content' => 'x'],
			['role' => 'user', 'content' => 'and now?'],
		], null);

		self::assertSame('', $result->toolMessage);
		self::assertSame(
			'{"role":"tool","content":"x","name":"a","tool_call_id":"call_1"}',
			$result->history[1],
		);
	}

	public function testStructuredToolResultIsStringified(): void {
		$result = $this->mapper->map([
			['role' => 'user', 'content' => 'go'],
			['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => ['ok' => true]],
		], null);

		$decoded = json_decode($result->toolMessage, true);
		self::assertSame('{"ok":true}', $decoded[0]['content']);
	}

	public function testInlineImageOnTheFinalTurnBecomesAnAttachment(): void {
		$result = $this->mapper->map([
			['role' => 'user', 'content' => [
				['type' => 'text', 'text' => 'What is this?'],
				['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('PNGDATA')]],
			]],
		], null);

		self::assertSame('What is this?', $result->input);
		self::assertCount(1, $result->attachments);
		self::assertSame('PNGDATA', $result->attachments[0]->content);
		self::assertSame('attachment-1.png', $result->attachments[0]->filename);
	}

	public function testInlineAudioOnTheFinalTurnBecomesAnAttachment(): void {
		$result = $this->mapper->map([
			['role' => 'user', 'content' => [
				['type' => 'input_audio', 'input_audio' => ['data' => base64_encode('WAVE'), 'format' => 'wav']],
			]],
		], null);

		self::assertSame('WAVE', $result->attachments[0]->content);
		self::assertSame('attachment-0.wav', $result->attachments[0]->filename);
	}

	public function testFileContentPartKeepsItsFilename(): void {
		$result = $this->mapper->map([
			['role' => 'user', 'content' => [
				['type' => 'file', 'file' => [
					'file_data' => 'data:application/pdf;base64,' . base64_encode('%PDF'),
					'filename' => 'report.pdf',
				]],
			]],
		], null);

		self::assertSame('%PDF', $result->attachments[0]->content);
		self::assertSame('report.pdf', $result->attachments[0]->filename);
	}

	public function testStructuredContentInHistoryIsPassedThrough(): void {
		$result = $this->mapper->map([
			['role' => 'user', 'content' => [
				['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAA']],
			]],
			['role' => 'user', 'content' => 'follow up'],
		], null);

		$decoded = json_decode($result->history[0], true);
		self::assertSame('image_url', $decoded['content'][0]['type']);
		self::assertSame([], $result->attachments);
	}

	public function testRemoteImageUrlIsRejected(): void {
		$this->expectException(ApiException::class);
		$this->mapper->map([
			['role' => 'user', 'content' => [
				['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/cat.png']],
			]],
		], null);
	}

	public function testAssistantPrefillIsRejected(): void {
		$this->expectException(ApiException::class);
		$this->mapper->map([
			['role' => 'user', 'content' => 'Hi'],
			['role' => 'assistant', 'content' => 'Partial'],
		], null);
	}

	public function testEmptyMessagesAreRejected(): void {
		$this->expectException(ApiException::class);
		$this->mapper->map([], null);
	}

	public function testSystemOnlyConversationIsRejected(): void {
		$this->expectException(ApiException::class);
		$this->mapper->map([['role' => 'system', 'content' => 'Be brief.']], null);
	}

	public function testUnknownContentPartIsRejected(): void {
		$this->expectException(ApiException::class);
		$this->mapper->map([
			['role' => 'user', 'content' => [['type' => 'video_url', 'video_url' => ['url' => 'data:video/mp4;base64,AA']]]],
		], null);
	}

	public function testToolsAreForwardedAsJson(): void {
		$tools = [['type' => 'function', 'function' => ['name' => 'weather', 'parameters' => ['type' => 'object']]]];
		$result = $this->mapper->map([['role' => 'user', 'content' => 'Hi']], $tools);

		self::assertSame(json_encode($tools, JSON_UNESCAPED_SLASHES), $result->tools);
	}

	public function testFlattenToPromptRendersTheWholeConversation(): void {
		$result = $this->mapper->map([
			['role' => 'system', 'content' => 'Be brief.'],
			['role' => 'user', 'content' => 'A'],
			['role' => 'assistant', 'content' => 'B'],
			['role' => 'user', 'content' => 'C'],
		], null);

		self::assertSame(
			"Be brief.\n\nUser: A\n\nAssistant: B\n\nUser: C",
			$this->mapper->flattenToPrompt($result),
		);
	}

	public function testRemoteImageUrlInHistoryIsRejected(): void {
		$this->expectException(ApiException::class);
		$this->mapper->map([
			['role' => 'user', 'content' => [
				['type' => 'image_url', 'image_url' => ['url' => 'http://169.254.169.254/latest/meta-data/']],
			]],
			['role' => 'user', 'content' => 'follow up'],
		], null);
	}

	public function testRemoteFileInHistoryIsRejected(): void {
		$this->expectException(ApiException::class);
		$this->mapper->map([
			['role' => 'user', 'content' => [
				['type' => 'file', 'file' => ['file_data' => 'http://intranet.local/report.pdf']],
			]],
			['role' => 'user', 'content' => 'follow up'],
		], null);
	}

	public function testUnknownContentPartInHistoryIsRejected(): void {
		$this->expectException(ApiException::class);
		$this->mapper->map([
			['role' => 'user', 'content' => [
				['type' => 'video_url', 'video_url' => ['url' => 'http://intranet.local/clip.mp4']],
			]],
			['role' => 'user', 'content' => 'follow up'],
		], null);
	}

	public function testUnknownFieldsAreDroppedFromHistoryParts(): void {
		$result = $this->mapper->map([
			['role' => 'user', 'content' => [
				[
					'type' => 'text',
					'text' => 'look',
					'image_url' => ['url' => 'http://intranet.local/cat.png'],
				],
				[
					'type' => 'image_url',
					'image_url' => ['url' => 'data:image/png;base64,AAA', 'detail' => 'low', 'extra' => 'x'],
				],
			]],
			['role' => 'user', 'content' => 'follow up'],
		], null);

		$decoded = json_decode($result->history[0], true);
		self::assertSame([
			['type' => 'text', 'text' => 'look'],
			['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAA', 'detail' => 'low']],
		], $decoded['content']);
	}

	public function testStructuredToolResultsInHistoryAreNotRestricted(): void {
		$result = $this->mapper->map([
			['role' => 'user', 'content' => 'weather?'],
			['role' => 'assistant', 'content' => '', 'tool_calls' => [
				['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'weather', 'arguments' => '{}']],
			]],
			['role' => 'tool', 'tool_call_id' => 'c1', 'content' => [['temperature' => 20]]],
			['role' => 'assistant', 'content' => [['type' => 'refusal', 'refusal' => 'no']]],
			['role' => 'user', 'content' => 'thanks'],
		], null);

		self::assertCount(4, $result->history);
		self::assertSame('[{"temperature":20}]', json_decode($result->history[2], true)['content']);
	}
}
