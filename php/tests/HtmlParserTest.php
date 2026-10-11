<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tests;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Parsing\Formats;
use Phox\ContextAtlas\Parsing\Html\Entities;
use Phox\ContextAtlas\Parsing\HtmlParser;
use PHPUnit\Framework\TestCase;

final class HtmlParserTest extends TestCase
{
    public function test_the_named_references_are_the_html4_set_and_apos(): void
    {
        self::assertCount(253, Entities::NAMED);
        self::assertSame("'", Entities::decode('&apos;'));
        self::assertSame('&Apos;', Entities::decode('&Apos;'));
    }

    public function test_a_level_one_heading_keeps_the_title_out(): void
    {
        $blocks = HtmlParser::parse('<title>Site</title><h1>Page</h1><p>Text</p>');

        self::assertSame([['type' => 'heading', 'text' => 'Page', 'level' => 1], ['type' => 'paragraph', 'text' => 'Text']], self::arrays($blocks));
    }

    public function test_a_title_inside_svg_is_not_the_page_title(): void
    {
        self::assertSame([['type' => 'paragraph', 'text' => 'Body']], self::arrays(HtmlParser::parse('<body><svg><title>Icon</title></svg><p>Body</p></body>')));
    }

    public function test_header_and_footer_are_kept_inside_an_article(): void
    {
        $blocks = HtmlParser::parse('<body><header>Site</header><article><header><h2>Post</h2></header><footer>By Ada</footer></article><footer>Legal</footer></body>');

        self::assertSame(['Post', 'By Ada'], array_map(static fn (Block $block): string => $block->text, $blocks));
    }

    public function test_html_files_are_read_by_this_parser(): void
    {
        self::assertSame('html', Formats::of('page.xhtml'));
        self::assertSame([['type' => 'paragraph', 'text' => 'Hi']], self::arrays(Formats::blocks('<p>Hi</p>', 'html')));
    }

    public function test_an_unclosed_raw_text_element_runs_to_the_end(): void
    {
        self::assertSame([['type' => 'paragraph', 'text' => 'Before']], self::arrays(HtmlParser::parse('<p>Before</p><script>never closed <p>hidden</p>')));
    }

    /**
     * @param  list<Block>  $blocks
     * @return list<array<string, mixed>>
     */
    private static function arrays(array $blocks): array
    {
        return array_map(static fn (Block $block): array => $block->toArray(), $blocks);
    }
}
