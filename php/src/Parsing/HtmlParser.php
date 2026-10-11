<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\BlockType;
use Phox\ContextAtlas\Parsing\Html\Content;
use Phox\ContextAtlas\Parsing\Html\Element;
use Phox\ContextAtlas\Parsing\Html\Table;
use Phox\ContextAtlas\Parsing\Html\Tree;
use Phox\ContextAtlas\Text;

/**
 * Reads the content of an HTML page into blocks: headings, paragraphs (a list as one paragraph
 * of `- ` or `1. ` lines), code from `pre`, and tables with their caption, header and rows in
 * meta. Reading starts at `main` (or `role="main"`), else `body`; page furniture such as
 * scripts, navigation and site headers is dropped. Speaker markup in transcripts gives the
 * paragraphs after it `meta.speaker`. No DOM library is used, so the JavaScript and Python
 * packages can follow spec/formats/html.md exactly.
 */
final class HtmlParser
{
    /** @var list<Block> */
    private array $blocks = [];

    private string $buffer = '';

    /** @var list<string> */
    private array $lines = [];

    /** @var array{speaker: string, speaker_full?: string}|null */
    private ?array $speaker = null;

    private ?Element $speakerScope = null;

    private bool $afterSpeaker = false;

    /** @var list<array{Element, int}> open blocks, each with the number of blocks emitted when it opened */
    private array $open = [];

    /** @var int|null the most words in one link's text in the buffer, null without a link */
    private ?int $bufferLink = null;

    /** @var int|null the same for the list lines */
    private ?int $listLink = null;

    /** @var array<int, string> block index => repeat key of a paragraph that may be boilerplate */
    private array $candidates = [];

    private const int BOILERPLATE_WORDS = 8;

    private const int BOILERPLATE_LINK_WORDS = 4;

    private const int BOILERPLATE_REPEATS = 3;

    private const string SEPARATORS = '/^[\s\-–—:.,;]+/u';

    private const string SEPARATORS_END = '/[\s\-–—:.,;]+$/u';

    private const string MARKER = '/^(?:\(?(?:[0-9]{1,3}|[A-Za-z]{1,2}|[ivxlcIVXLC]{1,6})[.)]|[-–•*·])$/u';

    /**
     * @return list<Block>
     */
    public static function parse(string $text): array
    {
        $root = Tree::build(str_replace(["\r\n", "\r"], "\n", (string) mb_scrub($text, 'UTF-8')));
        $start = Content::find($root, static fn (Element $e): bool => $e->name === 'main' || $e->token('role') === 'main')
            ?? Content::find($root, static fn (Element $e): bool => $e->name === 'body')
            ?? $root;

        $parser = new self();
        $parser->open = [[$start, 0]];
        $parser->walk($start, Content::opensArticle($start));
        $parser->flush();

        return self::titled($parser->withoutBoilerplate(), $root);
    }

    /**
     * The page's `title` as a level-1 heading in front, when no level-1 heading was read.
     *
     * @param  list<Block>  $blocks
     * @return list<Block>
     */
    private static function titled(array $blocks, Element $root): array
    {
        foreach ($blocks as $block) {
            if ($block->isHeading() && $block->level === 1) {
                return $blocks;
            }
        }

        $title = Content::find($root, static fn (Element $e): bool => $e->name === 'title', ['svg']);
        $text = $title === null ? '' : Text::squash(implode('', array_filter($title->children, is_string(...))));

        return $text === '' ? $blocks : [Block::heading($text, 1), ...$blocks];
    }

    private function walk(Element $parent, bool $inArticle): void
    {
        foreach ($parent->children as $child) {
            if (is_string($child)) {
                $this->buffer .= $child;
            } elseif (Content::dropped($child, $inArticle, self::isSoleButton($parent, $child))) {
                continue;
            } elseif ($child->name === 'math') {
                $this->buffer .= Content::math($child);
            } elseif (Content::isSpeaker($child)) {
                $this->speak($child, $inArticle);
            } else {
                $this->bufferLink = self::link($child, $inArticle, $this->bufferLink);
                $this->element($child, $inArticle || Content::opensArticle($child));
            }
        }
    }

    private function element(Element $element, bool $inArticle): void
    {
        $name = $element->name;

        if ($name === 'br') {
            $this->buffer .= "\n";

            return;
        }
        $level = Content::headingLevel($element);
        if ($level === null && ! Content::isBlock($name)) {
            $this->buffer .= Content::script($element, $this->buffer);
            $this->walk($element, $inArticle);

            return;
        }

        $this->flush();

        match (true) {
            $level !== null => $this->emit(BlockType::Heading, Text::squash(Content::line($element, $inArticle, true)), $level),
            $name === 'dl' && self::isMarkerList($element, $inArticle) => $this->markers($element, $inArticle, 0),
            $name === 'pre' => $this->emit(BlockType::Code, self::code($element, $inArticle)),
            $name === 'table' => $this->table($element, $inArticle),
            in_array($name, Content::LISTS, true) => $this->items($element, $inArticle, 0),
            default => $this->block($element, $inArticle),
        };

        $this->emitList();
        $this->flush();
    }

    private function block(Element $element, bool $inArticle): void
    {
        $this->open[] = [$element, count($this->blocks)];
        $this->walk($element, $inArticle);
        $this->flush();
        array_pop($this->open);

        if ($this->speakerScope === $element) {
            $this->speaker = null;
            $this->speakerScope = null;
        }
    }

    /**
     * A speaker element is not read as text: it names who speaks in the paragraphs after it,
     * up to the next speaker element or the end of the block it sits in. When it opens a `p`,
     * that block is the one around the `p`, so the speech's later paragraphs are included.
     */
    private function speak(Element $element, bool $inArticle): void
    {
        $full = (string) preg_replace(self::SEPARATORS_END, '', Text::squash(Content::line($element, $inArticle)));
        if ($full === '') {
            return;
        }

        $named = Content::find($element, static function (Element $e): bool {
            foreach ($e->tokens('class') as $token) {
                if (str_contains($token, 'name')) {
                    return true;
                }
            }

            return false;
        });
        $name = $named === null ? '' : (string) preg_replace(self::SEPARATORS_END, '', Text::squash(Content::line($named, $inArticle)));
        $name = $name !== '' ? $name : $full;

        $opens = Text::trim($this->buffer) === '';
        $this->flush();
        $this->speaker = $name === $full ? ['speaker' => $name] : ['speaker' => $name, 'speaker_full' => $full];

        $top = count($this->open) - 1;
        [$block, $openedAt] = $this->open[$top];
        if ($opens && $block->name === 'p' && $openedAt === count($this->blocks) && $top > 0) {
            $block = $this->open[$top - 1][0];
        }
        $this->speakerScope = $block;
        $this->afterSpeaker = true;
    }

    /**
     * A `button` that is the only content of a `summary` or `dt`, which holds its label.
     */
    private static function isSoleButton(Element $parent, Element $child): bool
    {
        if ($child->name !== 'button' || ($parent->name !== 'summary' && $parent->name !== 'dt')) {
            return false;
        }

        foreach ($parent->children as $other) {
            if ($other !== $child && (! is_string($other) || Text::trim($other) !== '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * A `dl` whose `dt` children are all enumeration markers, as statutes number their
     * points: `1.`, `a)`, `(iv)`, `-`.
     */
    private static function isMarkerList(Element $dl, bool $inArticle): bool
    {
        $markers = 0;

        foreach ($dl->children as $child) {
            if (is_string($child)) {
                if (Text::trim($child) !== '') {
                    return false;
                }
            } elseif ($child->name === 'dt') {
                if (preg_match(self::MARKER, Text::squash(Content::line($child, $inArticle))) !== 1) {
                    return false;
                }
                $markers++;
            } elseif ($child->name !== 'dd' && ! Content::dropped($child, $inArticle)) {
                return false;
            }
        }

        return $markers > 0;
    }

    /**
     * Adds a marker list's lines: each `dt` marker, a space, and the text of the `dd` after it.
     */
    private function markers(Element $dl, bool $inArticle, int $depth): void
    {
        $marker = '';

        foreach ($dl->children as $child) {
            if (! $child instanceof Element || Content::dropped($child, $inArticle)) {
                continue;
            }
            if ($child->name === 'dt') {
                $marker = Text::squash(Content::line($child, $inArticle)) . ' ';

                continue;
            }

            $prefix = str_repeat('  ', $depth) . $marker;
            $text = '';
            $this->item($child, $inArticle || Content::opensArticle($child), $depth, $prefix, $text);
            $this->line($prefix, $text, $depth);
            $marker = '';
        }
    }

    /**
     * Emits the buffer as a paragraph. Right after a speaker element, the separators between
     * the name and the speech (`M. Dupont. - Texte`) are left out.
     */
    private function flush(): void
    {
        $text = Text::squash($this->buffer);
        if ($this->afterSpeaker) {
            $text = (string) preg_replace(self::SEPARATORS, '', $text);
            $this->afterSpeaker = false;
        }
        $this->emit(BlockType::Paragraph, $text, null, $this->bufferLink);
        $this->buffer = '';
        $this->bufferLink = null;
    }

    private function emitList(): void
    {
        $this->emit(BlockType::Paragraph, implode("\n", $this->lines), null, $this->listLink);
        $this->lines = [];
        $this->listLink = null;
    }

    /**
     * `$link` is the most words in one link's text inside the block, null when it has none.
     */
    private function emit(BlockType $type, string $text, ?int $level = null, ?int $link = null): void
    {
        if (Text::trim($text) === '') {
            return;
        }

        $meta = $type === BlockType::Paragraph && $this->speaker !== null ? $this->speaker : [];
        $key = Text::squash((string) preg_replace('/[0-9]+/', '', $text));
        if ($link !== null && $link <= self::BOILERPLATE_LINK_WORDS && preg_match('/[A-Za-z]/', $key) === 1 && self::isLinkLine($text)) {
            $this->candidates[count($this->blocks)] = $key;
        }
        $this->blocks[] = new Block($type, $text, $level, $meta);
    }

    /**
     * The most words in one link's text so far, counting `$element` when it is an `a`.
     */
    private static function link(Element $element, bool $inArticle, ?int $most): ?int
    {
        if ($element->name !== 'a') {
            return $most;
        }

        $text = Text::squash(Content::line($element, $inArticle));
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);

        return max($most ?? 0, $words);
    }

    /**
     * Short lines, none ending a sentence: what a row of links such as "Reply | Share" or
     * "Link to this speech" reads as.
     */
    private static function isLinkLine(string $text): bool
    {
        foreach (explode("\n", $text) as $line) {
            $line = Text::trim((string) preg_replace('/^\s*(?:-|-?[0-9]+\.)\s/', '', $line));
            if (count(preg_split('/\s+/u', $line) ?: []) > self::BOILERPLATE_WORDS || preg_match('/[.!?]$/', $line) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * The blocks without boilerplate: a paragraph holding a link, no link longer than four
     * words, made of short lines, whose text with digits removed has an ASCII letter and recurs
     * at least three times in the page.
     *
     * @return list<Block>
     */
    private function withoutBoilerplate(): array
    {
        $repeats = array_count_values($this->candidates);
        $blocks = [];

        foreach ($this->blocks as $i => $block) {
            if (! isset($this->candidates[$i]) || $repeats[$this->candidates[$i]] < self::BOILERPLATE_REPEATS) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    private function table(Element $element, bool $inArticle): void
    {
        $table = Table::read($element, $inArticle);

        if ($table !== null) {
            $this->blocks[] = Table::block($table);
        }
    }

    /**
     * A `pre` element's text as written, a `br` as a line break, without one leading line
     * break and without trailing whitespace.
     */
    private static function code(Element $pre, bool $inArticle): string
    {
        $text = Content::raw($pre, $inArticle);

        return rtrim(str_starts_with($text, "\n") ? substr($text, 1) : $text, " \t\n\f");
    }

    /**
     * Adds a list's lines: one per item, `- ` or `<n>. ` after two spaces per level of nesting.
     */
    private function items(Element $list, bool $inArticle, int $depth): void
    {
        $ordered = $list->name === 'ol';
        $start = trim($list->attribute('start') ?? '', " \t\n\f\r");
        $number = $ordered && preg_match('/^-?[0-9]{1,9}$/', $start) === 1 ? (int) $start : 1;

        $this->children($list, $inArticle, $depth, $ordered, $number);
    }

    private function children(Element $parent, bool $inArticle, int $depth, bool $ordered, int &$number): void
    {
        $indent = str_repeat('  ', $depth);

        foreach ($parent->children as $child) {
            if (is_string($child)) {
                $text = Text::squash($child);
                if ($text !== '') {
                    $this->lines[] = $indent . ($ordered ? "{$number}. " : '- ') . $text;
                    $number++;
                }
            } elseif (Content::dropped($child, $inArticle)) {
                continue;
            } elseif (in_array($child->name, Content::LISTS, true)) {
                $this->items($child, $inArticle || Content::opensArticle($child), $depth + 1);
            } elseif ($child->name === 'li') {
                $prefix = $indent . ($ordered ? "{$number}. " : '- ');
                $text = '';
                $this->item($child, $inArticle || Content::opensArticle($child), $depth, $prefix, $text);
                $this->line($prefix, $text, $depth);
                $number++;
            } else {
                $this->children($child, $inArticle || Content::opensArticle($child), $depth, $ordered, $number);
            }
        }
    }

    /**
     * Reads an item's text in order. A list inside it adds its lines after the text so far;
     * a `pre` or table ends the list paragraph so far, is emitted, and the list goes on after.
     */
    private function item(Element $parent, bool $inArticle, int $depth, string &$prefix, string &$text): void
    {
        foreach ($parent->children as $child) {
            if (is_string($child)) {
                $text .= $child;

                continue;
            }
            if (Content::dropped($child, $inArticle)) {
                continue;
            }

            $inside = $inArticle || Content::opensArticle($child);
            $name = $child->name;
            $this->listLink = self::link($child, $inside, $this->listLink);
            if ($name === 'br') {
                $text .= ' ';
            } elseif ($name === 'math') {
                $text .= Content::math($child);
            } elseif ($name === 'dl' && self::isMarkerList($child, $inside)) {
                $this->line($prefix, $text, $depth);
                $this->markers($child, $inside, $depth + 1);
            } elseif (in_array($name, Content::LISTS, true)) {
                $this->line($prefix, $text, $depth);
                $this->items($child, $inside, $depth + 1);
            } elseif ($name === 'pre' || $name === 'table') {
                $this->line($prefix, $text, $depth);
                $this->emitList();
                $name === 'pre' ? $this->emit(BlockType::Code, self::code($child, $inside)) : $this->table($child, $inside);
            } elseif (Content::isBlock($name)) {
                $text .= ' ';
                $this->item($child, $inside, $depth, $prefix, $text);
                $text .= ' ';
            } else {
                $text .= Content::script($child, $text);
                $this->item($child, $inside, $depth, $prefix, $text);
            }
        }
    }

    /**
     * Ends the text collected for an item as one line. After an item's first line, its later
     * lines are indented one level with no marker.
     */
    private function line(string &$prefix, string &$text, int $depth): void
    {
        $line = Text::squash($text);
        $text = '';

        if ($line !== '') {
            $this->lines[] = $prefix . $line;
            $prefix = str_repeat('  ', $depth + 1);
        }
    }
}
