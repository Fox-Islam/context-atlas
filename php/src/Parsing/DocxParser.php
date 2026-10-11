<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use DOMElement;
use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Exceptions\ContextAtlasException;
use Phox\ContextAtlas\Parsing\Docx\Numbering;
use Phox\ContextAtlas\Parsing\Docx\Package;
use Phox\ContextAtlas\Parsing\Docx\Runs;
use Phox\ContextAtlas\Parsing\Docx\Styles;
use Phox\ContextAtlas\Text;

/**
 * Reads a Word document (.docx) from a path: the body of word/document.xml in order, with
 * headings, lists and code from paragraph styles and numbering, tables, text boxes where they
 * appear, and footnotes and endnotes appended. Comments, headers, footers and images are not
 * read. The rules are stated in spec/formats/docx.md.
 */
final class DocxParser
{
    private const string MC = 'http://schemas.openxmlformats.org/markup-compatibility/2006';

    private const string DC = 'http://purl.org/dc/elements/1.1/';

    private Styles $styles;

    private Numbering $numbering;

    private Runs $runs;

    private function __construct() {}

    /**
     * @return list<Block>
     *
     * @throws ContextAtlasException
     */
    public static function parse(string $path): array
    {
        $parts = Package::open($path);
        $reader = new self();
        $reader->styles = new Styles($parts['styles']);
        $reader->numbering = new Numbering($parts['numbering']);
        $reader->runs = new Runs($parts['footnotes'], $parts['endnotes'], $reader->body(...));
        $body = Package::child($parts['document'], 'body');
        $items = $body === null ? [] : $reader->body($body);

        return $reader->render($items, self::title($parts['core']));
    }

    /**
     * Block-level items of a container, in order: paragraphs (each followed by the items of its
     * text boxes) and tables; a table of one column gives the items of its cells instead.
     *
     * @return list<array<string, mixed>>
     */
    private function body(DOMElement $container): array
    {
        $items = [];
        foreach ($container->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if (Package::isW($node, 'p')) {
                array_push($items, ...$this->paragraph($node));
            } elseif (Package::isW($node, 'tbl')) {
                array_push($items, ...$this->table($node));
            } elseif (Package::isW($node, 'sdt') && ! Runs::isContents($node)) {
                $content = Package::child($node, 'sdtContent');
                array_push($items, ...($content === null ? [] : $this->body($content)));
            } elseif (Package::isW($node, 'customXml') || Package::isW($node, 'ins') || Package::isW($node, 'moveTo')) {
                array_push($items, ...$this->body($node));
            } elseif ($node->namespaceURI === self::MC && $node->localName === 'AlternateContent') {
                $choice = self::choice($node);
                array_push($items, ...($choice === null ? [] : $this->body($choice)));
            }
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paragraph(DOMElement $paragraph): array
    {
        $properties = Package::child($paragraph, 'pPr');
        $style = $this->styles->resolve(Package::value($properties, 'pStyle'));
        $name = mb_strtolower($this->styles->name($style));
        $numbering = Package::child($properties, 'numPr');
        $numId = Package::value($numbering, 'numId') ?? $this->styles->inherited($style, 'numId');
        $ilvl = Styles::integer(Package::value($numbering, 'ilvl')) ?? $this->styles->inherited($style, 'ilvl') ?? 0;
        $outline = Styles::integer(Package::value($properties, 'outlineLvl')) ?? $this->styles->inherited($style, 'outline');
        [$text, $boxes] = $this->runs->read($paragraph);

        $item = ['kind' => 'paragraph', 'text' => $text];
        if ($name === 'title') {
            $item['kind'] = 'title';
        } elseif (preg_match('/^heading ([1-9])$/D', $name, $match) === 1 || (is_int($outline) && $outline >= 0 && $outline <= 8)) {
            $item = ['kind' => 'heading', 'text' => $text, 'level' => min(6, isset($match[1]) ? (int) $match[1] : (int) $outline + 1)];
        } elseif (is_string($numId) && $numId !== '0' && $this->numbering->has($numId)) {
            $ilvl = max(0, min(8, (int) $ilvl));
            $item = ['kind' => 'item', 'text' => $text, 'ilvl' => $ilvl, 'marker' => $this->numbering->marker($numId, $ilvl)];
        } elseif (preg_match('/code|source|preformatted/', $name) === 1) {
            $item['kind'] = 'code';
        } elseif ($name === 'caption') {
            $item['kind'] = 'caption';
        }

        return [$item, ...$boxes];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function table(DOMElement $table): array
    {
        $rows = [];
        $heads = [];
        $width = 0;
        $above = [];

        foreach (self::children($table, 'tr') as $tr) {
            $properties = Package::child($tr, 'trPr');
            $grid = array_fill(0, max(0, min(100, Styles::integer(Package::value($properties, 'gridBefore')) ?? 0)), ['text' => '', 'items' => [], 'continued' => false]);
            foreach (self::children($tr, 'tc') as $tc) {
                $cellProperties = Package::child($tc, 'tcPr');
                $span = max(1, min(100, Styles::integer(Package::value($cellProperties, 'gridSpan')) ?? 1));
                $merge = Package::child($cellProperties, 'vMerge');
                $continues = $merge !== null && in_array(Package::attribute($merge, 'val'), [null, 'continue'], true);
                $items = $this->body($tc);
                for ($k = 0; $k < $span; $k++) {
                    $grid[] = $continues
                        ? ['text' => $above[count($grid)]['text'] ?? '', 'items' => [], 'continued' => true]
                        : ['text' => self::flatten($items), 'items' => $k === 0 ? $items : [], 'continued' => false];
                }
            }
            $header = Package::child($properties, 'tblHeader');
            $heads[] = $header !== null && ! in_array(Package::attribute($header, 'val'), ['0', 'false', 'off'], true);
            $rows[] = $grid;
            $width = max($width, count($grid));
            $above = $grid;
        }

        if ($width === 1) {
            $items = [];
            foreach ($rows as $row) {
                foreach ($row as $cell) {
                    array_push($items, ...$cell['items']);
                }
            }

            return $items;
        }

        $count = 0;
        while ($count < count($heads) && $heads[$count]) {
            $count++;
        }
        $count = $rows === [] ? 0 : max(1, $count);
        $texts = [];
        foreach ($rows as $r => $row) {
            $texts[] = array_map(static fn (array $cell): string => $r < $count && $cell['continued'] ? '' : $cell['text'], $row);
        }

        return [['kind' => 'table', 'header' => array_slice($texts, 0, $count), 'rows' => array_slice($texts, $count)]];
    }

    /**
     * Child elements `w:<name>`, also inside `w:sdt` content and `w:customXml`.
     *
     * @return list<DOMElement>
     */
    private static function children(DOMElement $parent, string $name): array
    {
        $found = [];
        foreach ($parent->childNodes as $node) {
            if (Package::isW($node, $name) && $node instanceof DOMElement) {
                $found[] = $node;
            } elseif (Package::isW($node, 'sdt') && $node instanceof DOMElement) {
                $content = Package::child($node, 'sdtContent');
                array_push($found, ...($content === null ? [] : self::children($content, $name)));
            } elseif (Package::isW($node, 'customXml') && $node instanceof DOMElement) {
                array_push($found, ...self::children($node, $name));
            }
        }

        return $found;
    }

    /**
     * A cell's text: the squashed text of its items (a table's cells in order), non-empty ones
     * joined by spaces.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private static function flatten(array $items): string
    {
        $parts = [];
        foreach ($items as $item) {
            if ($item['kind'] === 'table') {
                foreach ([...$item['header'], ...$item['rows']] as $row) {
                    array_push($parts, ...$row);
                }
            } else {
                $parts[] = Text::squash((string) $item['text']);
            }
        }

        return implode(' ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private static function choice(DOMElement $alternate): ?DOMElement
    {
        $fallback = null;
        foreach ($alternate->childNodes as $child) {
            if ($child instanceof DOMElement && $child->namespaceURI === self::MC) {
                if ($child->localName === 'Choice') {
                    return $child;
                }
                $fallback ??= $child->localName === 'Fallback' ? $child : null;
            }
        }

        return $fallback;
    }

    private static function title(?DOMElement $core): string
    {
        foreach (($core === null ? [] : $core->childNodes) as $node) {
            if ($node instanceof DOMElement && $node->namespaceURI === self::DC && $node->localName === 'title') {
                return Text::squash($node->textContent);
            }
        }

        return '';
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<Block>
     */
    private function render(array $items, string $title): array
    {
        $items = self::captions($items);
        $shift = 0;
        foreach ($items as $item) {
            $shift = $item['kind'] === 'title' && Text::squash((string) $item['text']) !== '' ? 1 : $shift;
        }

        $out = new BlockBuilder();
        $list = [];
        $code = null;
        foreach ($items as $item) {
            $kind = $item['kind'];
            $text = Text::squash((string) ($item['text'] ?? ''));
            if ($kind !== 'code' && $code !== null && ! ($kind === 'paragraph' && $text === '')) {
                $out->code($code);
                $code = null;
            }
            if ($kind !== 'item' && $list !== [] && ! ($kind === 'paragraph' && $text === '')) {
                $out->paragraph(implode("\n", $list));
                $list = [];
            }

            if ($kind === 'code') {
                $code = [...($code ?? []), ...explode("\n", (string) $item['text'])];
            } elseif ($kind === 'item') {
                $marker = (string) $item['marker'];
                if ($text !== '') {
                    $list[] = str_repeat('  ', (int) $item['ilvl']) . ($marker === '' ? $text : $marker . ' ' . $text);
                }
            } elseif ($kind === 'title') {
                $out->heading($text, 1);
            } elseif ($kind === 'heading') {
                $out->heading($text, min(6, (int) $item['level'] + $shift));
            } elseif ($kind === 'table') {
                $out->table((string) ($item['caption'] ?? ''), $item['header'], $item['rows']);
            } elseif ($kind !== 'taken') {
                $out->paragraph($text);
            }
        }
        if ($code !== null) {
            $out->code($code);
        }
        if ($list !== []) {
            $out->paragraph(implode("\n", $list));
        }
        foreach ($this->runs->notes() as $note) {
            $out->paragraph($note);
        }

        return self::withTitle($out->blocks(), $title);
    }

    /**
     * Gives each table the text of a caption-styled paragraph next to it (before it first, then
     * after it, skipping paragraphs whose text is empty); the paragraph is then not emitted.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function captions(array $items): array
    {
        foreach ($items as $i => $item) {
            if ($item['kind'] !== 'table') {
                continue;
            }
            foreach ([-1, 1] as $step) {
                $j = $i + $step;
                while (isset($items[$j]) && in_array($items[$j]['kind'], ['paragraph', 'caption'], true) && Text::squash((string) $items[$j]['text']) === '') {
                    $j += $step;
                }
                if (isset($items[$j]) && $items[$j]['kind'] === 'caption') {
                    $items[$i]['caption'] = Text::squash((string) $items[$j]['text']);
                    $items[$j]['kind'] = 'taken';
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * @param  list<Block>  $blocks
     * @return list<Block>
     */
    private static function withTitle(array $blocks, string $title): array
    {
        foreach ($blocks as $block) {
            if ($block->isHeading() && $block->level === 1) {
                return $blocks;
            }
        }

        return $title === '' ? $blocks : [Block::heading($title, 1), ...$blocks];
    }
}
