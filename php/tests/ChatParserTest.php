<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tests;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Exceptions\ContextAtlasException;
use Phox\ContextAtlas\Parsing\Chat\ChatJson;
use Phox\ContextAtlas\Parsing\Chat\JsonMap;
use Phox\ContextAtlas\Parsing\Chat\JsonNumber;
use Phox\ContextAtlas\Parsing\ChatParser;
use Phox\ContextAtlas\Parsing\Formats;
use PHPUnit\Framework\TestCase;

final class ChatParserTest extends TestCase
{
    public function test_chat_is_a_document_format_named_only_by_the_caller(): void
    {
        self::assertContains('chat', Formats::DOCUMENT);
        self::assertNotContains('chat', Formats::EXTENSIONS);
        self::assertSame('json', Formats::of('session.json'));
        self::assertSame('jsonl', Formats::of('session.jsonl'));
        self::assertSame('Hi', Formats::blocks('[{"role":"user","content":"Hi"}]', 'chat')[0]->text);
    }

    public function test_a_long_string_is_read_without_a_regex(): void
    {
        $long = str_repeat('word "quoted" \\ ', 400000);
        $blocks = ChatParser::parse((string) json_encode([['role' => 'tool', 'tool_call_id' => 'c', 'content' => $long]]));

        self::assertSame(trim($long), $blocks[0]->text);
    }

    public function test_nesting_deeper_than_512_is_invalid(): void
    {
        self::assertTrue(ChatJson::valid(str_repeat('[', 512) . str_repeat(']', 512)));

        $this->expectExceptionObject(new ContextAtlasException('Invalid JSON at byte 512.'));
        ChatJson::decode(str_repeat('[', 513) . str_repeat(']', 513));
    }

    public function test_invalid_strings_fail_at_their_opening_quote(): void
    {
        foreach (['["a\\x"]' => 1, "[\"a\nb\"]" => 1, '{"k": "\\u12"}' => 6, '{1: 2}' => 1, '[1,]' => 3, '' => 0, '[] x' => 3] as $text => $byte) {
            try {
                ChatJson::decode((string) $text);
                self::fail('no error for ' . $text);
            } catch (ContextAtlasException $e) {
                self::assertSame("Invalid JSON at byte {$byte}.", $e->getMessage(), (string) $text);
            }
        }
    }

    public function test_compact_json_keeps_order_and_numbers_and_escapes_only_what_it_must(): void
    {
        $value = ChatJson::decode('{"b": -0.10e5, "a": ["é/ ", "\u0001\t", true, null], "signature": "x", "b": 2}');

        self::assertInstanceOf(JsonMap::class, $value);
        self::assertEquals(new JsonNumber('2'), $value->get('b'));
        self::assertSame("{\"b\":2,\"a\":[\"é/\u{2028}\",\"\\u0001\\t\",true,null]}", ChatJson::encode($value));
    }

    public function test_a_byte_order_mark_and_crlf_lines_are_accepted(): void
    {
        $blocks = ChatParser::parse("\u{FEFF}{\"role\":\"user\",\"content\":\"a\"}\r\n{\"role\":\"assistant\",\"content\":\"b\"}\r\n");

        self::assertSame(['a', 'b'], array_map(static fn (Block $block): string => $block->text, $blocks));
    }

    public function test_a_scalar_is_not_a_transcript(): void
    {
        $this->expectExceptionObject(new ContextAtlasException(ChatParser::UNRECOGNISED));

        ChatParser::parse('"hello"');
    }
}
