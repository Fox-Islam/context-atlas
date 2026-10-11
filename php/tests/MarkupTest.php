<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tests;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Document\Document;
use Phox\ContextAtlas\Exceptions\ContextAtlasException;
use Phox\ContextAtlas\Processor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkupTest extends TestCase
{
    public function test_invalid_json_names_the_byte(): void
    {
        $this->expectException(ContextAtlasException::class);
        $this->expectExceptionMessage('Invalid JSON at byte 7.');

        (new Processor())->ingestMarkup('{"a":1,}', 'x', 'json');
    }

    public function test_an_unknown_format_is_refused(): void
    {
        $this->expectException(ContextAtlasException::class);
        $this->expectExceptionMessage('Cannot read "ini" as markup; use yaml, json, jsonl, xml or toml.');

        (new Processor())->ingestMarkup('a = 1', 'x', 'ini');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidMarkup(): iterable
    {
        yield 'xml: an end tag with another name' => ['xml', '<a><b></a>', 'Invalid XML at byte 6.'];
        yield 'xml: an end tag that is not a name' => ['xml', '<a></1>', 'Invalid XML at byte 3.'];
        yield 'xml: the text ends inside an element' => ['xml', "<a>\n  <b/>", 'Invalid XML at byte 10.'];
        yield 'xml: no root element' => ['xml', '  just text', 'Invalid XML at byte 2.'];
        yield 'xml: empty text' => ['xml', '', 'Invalid XML at byte 0.'];
        yield 'xml: text after the root' => ['xml', '<a/>tail', 'Invalid XML at byte 4.'];
        yield 'xml: a second root element' => ['xml', '<a/><b/>', 'Invalid XML at byte 4.'];
        yield 'xml: an attribute value without quotes' => ['xml', '<a x=1/>', 'Invalid XML at byte 5.'];
        yield 'xml: attributes without whitespace between them' => ['xml', '<a x="1"y="2"/>', 'Invalid XML at byte 8.'];
        yield 'xml: an unclosed attribute value' => ['xml', '<a x="1/>', 'Invalid XML at byte 5.'];
        yield 'xml: an unclosed comment' => ['xml', '<a><!-- open </a>', 'Invalid XML at byte 3.'];
        yield 'xml: an unclosed CDATA section' => ['xml', '<a><![CDATA[ open </a>', 'Invalid XML at byte 3.'];
        yield 'xml: an unclosed doctype' => ['xml', '<!DOCTYPE a [ <!ENTITY b "c"> <a/>', 'Invalid XML at byte 0.'];
        yield 'xml: a doctype after the root' => ['xml', '<a/><!DOCTYPE a>', 'Invalid XML at byte 4.'];
        yield 'jsonl: the line number counts blank lines' => ['jsonl', "{\"a\": 1}\n\n{\"a\":}\n", 'Invalid JSONL at line 3: Invalid JSON at byte 5.'];
        yield 'jsonl: a CRLF line' => ['jsonl', "[1]\r\n[2,]\r\n", 'Invalid JSONL at line 2: Invalid JSON at byte 3.'];
    }

    #[DataProvider('invalidMarkup')]
    public function test_invalid_markup_names_where_reading_stopped(string $format, string $text, string $message): void
    {
        $this->expectException(ContextAtlasException::class);
        $this->expectExceptionMessage($message);

        (new Processor())->ingestMarkup($text, 'x', $format);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function longStrings(): iterable
    {
        $raw = str_repeat('word \\"quoted\\" ', 8000) . 'end';
        $plain = str_repeat('word "quoted" ', 8000) . 'end';
        yield 'json' => ['json', '[{"name": "' . $raw . '"}, {"name": "b"}]'];
        yield 'jsonl' => ['jsonl', '{"name": "' . $raw . '"}' . "\n" . '{"name": "b"}'];
        yield 'yaml double-quoted' => ['yaml', "- name: \"{$raw}\"\n- name: b\n"];
        yield 'yaml single-quoted' => ['yaml', "- name: '{$plain}'\n- name: b\n"];
        yield 'toml' => ['toml', "[[a]]\nname = \"{$raw}\"\n\n[[a]]\nname = \"b\"\n"];
        yield 'xml' => ['xml', '<a><b name="' . htmlspecialchars($plain) . '"/><b name="b"/></a>'];
    }

    #[DataProvider('longStrings')]
    public function test_a_string_of_a_hundred_thousand_characters_is_read_whole(string $format, string $text): void
    {
        $document = (new Processor())->ingestMarkup($text, 'x', $format);
        $labels = array_merge(...array_map(static fn ($unit): array => $unit->path, $document->units));

        self::assertContains('[name=' . str_repeat('word "quoted" ', 8000) . 'end]', array_map(static fn (string $segment): string => (string) preg_replace('/^[a-z]+(?=\[)/', '', $segment), $labels));
    }

    public function test_toml_is_read_without_errors(): void
    {
        $document = (new Processor())->ingestMarkup("[a\nb = = 1\nc = \"open\nd = '''never closed\n", 'x', 'toml');

        self::assertSame("x: (top level)\n[a\nb = = 1\nc = \"open\nd = '''never closed", $document->units[0]->text);
    }

    public function test_markup_is_not_a_profile_for_blocks(): void
    {
        $this->expectException(ContextAtlasException::class);
        $this->expectExceptionMessage('Unknown prose profile "markup"');

        (new Processor())->ingestBlocks([Block::paragraph('text')], 'x', 'markup');
    }

    public function test_a_stored_markup_document_reads_back_unchanged(): void
    {
        $document = (new Processor())->ingestMarkup("a:\n  b: 1\n  '0': two\n", 'x', 'yaml');

        self::assertSame(json_encode($document->toArray()), json_encode(Document::fromArray(json_decode((string) json_encode($document->toArray()), true))->toArray()));
    }
}
