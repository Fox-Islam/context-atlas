<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Text;

/**
 * Reads the block structure of Org: headings with TODO keywords, priorities, tags and cookies
 * removed, paragraphs, lists, source and example blocks, fixed-width lines and tables.
 * Keywords, comments, drawers and planning lines are dropped. Inline markup is left in the
 * text. The rules are stated in spec/formats/org.md.
 */
final class OrgParser
{
    private const string HEADING = '/^(\*+)[ \t]+(.*)$/';

    private const string BLOCK = '/^[ \t]*#\+begin_([^ \t]+)(?:[ \t].*)?$/i';

    private const string KEYWORD = '/^[ \t]*#\+([^ \t]+?):(?:[ \t]+(.*))?$/';

    private const string COMMENT = '/^[ \t]*#(?:[ \t]|$)/';

    private const string DRAWER = '/^[ \t]*:[A-Za-z0-9_-]+:$/';

    private const string DRAWER_END = '/^[ \t]*:end:$/i';

    private const string PLANNING = '/^[ \t]*(?:(?:SCHEDULED|DEADLINE|CLOSED):[ \t]*[<\[][^>\]]*[>\]][ \t]*)+$/';

    private const string FIXED_WIDTH = '/^[ \t]*:(?: |$)/';

    private const string TABLE = '/^[ \t]*\|/';

    private const string RULE = '/^[ \t]*-{5,}$/';

    private const string ITEM = '/^([ \t]*)(?:([-+])|(\d+[.)])|(?<=[ \t])(\*))(?:[ \t]+|$)/';

    private const string FOOTNOTE = '/^\[fn:[^\]]+\]/';

    private const string TAGS = '/[ \t]+:(?:[\p{L}\p{N}_@#%]+:)+$/u';

    private const string COOKIE = '/[ \t]*\[\d*(?:\/\d*|%)\]/';

    private BlockBuilder $out;

    /** @var list<string> */
    private array $keywords = ['TODO', 'DONE'];

    private int $shift = 0;

    private bool $titled = false;

    private function __construct()
    {
        $this->out = new BlockBuilder();
    }

    /**
     * @return list<Block>
     */
    public static function parse(string $text): array
    {
        $lines = array_map(
            static fn (string $line): string => rtrim($line, " \t"),
            explode("\n", str_replace(["\r\n", "\r"], "\n", $text)),
        );
        $parser = new self();
        foreach ($lines as $line) {
            if (preg_match('/^#\+(?:seq_|typ_)?todo:[ \t]*(.*)$/i', $line, $match) === 1) {
                foreach (preg_split('/[ \t]+/', $match[1]) ?: [] as $word) {
                    $word = (string) preg_replace('/\(.*\)$/', '', $word);
                    if ($word !== '' && $word !== '|') {
                        $parser->keywords[] = $word;
                    }
                }
            }
            if ($parser->shift === 0 && preg_match('/^#\+title:[ \t]*[^ \t]/i', $line) === 1) {
                $parser->shift = 1;
            }
        }
        $parser->run($lines);

        return $parser->out->blocks();
    }

    /**
     * @param  list<string>  $lines
     */
    private function run(array $lines): void
    {
        $n = count($lines);
        $listBase = 0;
        $listKind = '';
        $caption = '';

        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];

            if ($line === '') {
                if (! $this->out->inList()) {
                    $this->out->close();
                }
                continue;
            }

            $indent = strspn($line, " \t");
            $item = self::item($line);
            $continues = $item !== null && ($indent > $listBase || ($indent === $listBase && $item === $listKind));
            if ($this->out->inList() && ($item !== null ? ! $continues : $indent <= $listBase)) {
                $this->out->close();
            }

            $next = $this->element($lines, $i, $caption);
            if ($next !== null) {
                $i = $next;

                continue;
            }
            $caption = '';

            if ($item !== null) {
                if (! $this->out->inList()) {
                    $listBase = $indent;
                    $listKind = $item;
                }
                $this->out->item(self::itemText($line));
            } elseif ($this->out->inList()) {
                $this->out->extend($line);
            } else {
                if (preg_match(self::FOOTNOTE, $line) === 1) {
                    $this->out->close();
                }
                $this->out->text($line);
            }
        }

        $this->out->close();
    }

    /**
     * Reads an element other than paragraph text or a list item: a heading, block, keyword,
     * comment, drawer, planning line, fixed-width area, table or rule. Returns the last line
     * it used, or null.
     *
     * @param  list<string>  $lines
     */
    private function element(array $lines, int $i, string &$caption): ?int
    {
        $line = $lines[$i];

        if (preg_match(self::HEADING, $line, $match) === 1) {
            $caption = '';

            return $this->heading($lines, $i, strlen($match[1]), $match[2]);
        }
        if (preg_match(self::BLOCK, $line, $match) === 1) {
            $end = $this->blockEnd($lines, $i, $match[1]);
            $this->out->close();
            if ($end !== null) {
                $this->block(mb_strtolower($match[1]), array_slice($lines, $i + 1, $end - $i - 1));
            }

            return $end ?? $i;
        }
        if (preg_match(self::KEYWORD, $line, $match) === 1) {
            $this->keyword(mb_strtolower($match[1]), Text::trim($match[2] ?? ''), $caption);

            return $i;
        }
        if (preg_match(self::COMMENT, $line) === 1) {
            return $i;
        }
        if (preg_match(self::DRAWER, $line) === 1) {
            for ($j = $i + 1, $n = count($lines); $j < $n && preg_match(self::HEADING, $lines[$j]) !== 1; $j++) {
                if (preg_match(self::DRAWER_END, $lines[$j]) === 1) {
                    return $j;
                }
            }
        }
        if (preg_match(self::PLANNING, $line) === 1) {
            return $i;
        }
        if (preg_match(self::FIXED_WIDTH, $line) === 1) {
            return $this->fixedWidth($lines, $i);
        }
        if (preg_match(self::TABLE, $line) === 1) {
            $end = $this->table($lines, $i, $caption);
            $caption = '';

            return $end;
        }
        if (preg_match(self::RULE, $line) === 1) {
            $this->out->close();

            return $i;
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function heading(array $lines, int $i, int $level, string $text): int
    {
        $words = implode('|', array_map(static fn (string $word): string => preg_quote($word, '/'), $this->keywords));
        $text = (string) preg_replace('/^(?:' . $words . ')(?:[ \t]+|$)/', '', $text);
        $text = (string) preg_replace('/^\[#[A-Za-z0-9]\](?:[ \t]+|$)/', '', $text);

        if (preg_match('/^COMMENT(?:[ \t]|$)/', $text) === 1) {
            $this->out->close();
            for ($j = $i + 1, $n = count($lines); $j < $n; $j++) {
                if (preg_match(self::HEADING, $lines[$j], $match) === 1 && strlen($match[1]) <= $level) {
                    return $j - 1;
                }
            }

            return count($lines) - 1;
        }

        $text = (string) preg_replace(self::TAGS, '', $text);
        $this->out->heading(Text::squash((string) preg_replace(self::COOKIE, '', $text)), $level + $this->shift);

        return $i;
    }

    private function keyword(string $name, string $value, string &$caption): void
    {
        if ($name === 'title' && ! $this->titled && $value !== '') {
            $this->titled = true;
            $this->out->heading($value, 1);
        } elseif ($name === 'caption') {
            $caption = $value;
        }
        if ($name !== 'caption' && ! str_starts_with($name, 'attr_') && $name !== 'name') {
            $this->out->close();
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function blockEnd(array $lines, int $i, string $name): ?int
    {
        $end = '/^[ \t]*#\+end_' . preg_quote($name, '/') . '$/i';
        for ($j = $i + 1, $n = count($lines); $j < $n; $j++) {
            if (preg_match($end, $lines[$j]) === 1) {
                return $j;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $content
     */
    private function block(string $name, array $content): void
    {
        $this->out->close();
        if ($name === 'src' || $name === 'example') {
            $this->out->code(array_map(
                static fn (string $line): string => (string) preg_replace('/^([ \t]*),(\*|#\+)/', '$1$2', $line),
                BlockBuilder::dedent($content),
            ));
        } elseif ($name !== 'export' && $name !== 'comment') {
            $this->run(BlockBuilder::dedent($content));
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function fixedWidth(array $lines, int $i): int
    {
        $code = [];
        for ($j = $i, $n = count($lines); $j < $n && preg_match(self::FIXED_WIDTH, $lines[$j]) === 1; $j++) {
            $code[] = (string) preg_replace('/^[ \t]*: ?/', '', $lines[$j]);
        }
        $this->out->code($code);

        return $j - 1;
    }

    /**
     * @param  list<string>  $lines
     */
    private function table(array $lines, int $i, string $caption): int
    {
        $rows = [];
        $headerRows = null;
        for ($j = $i, $n = count($lines); $j < $n && preg_match(self::TABLE, $lines[$j]) === 1; $j++) {
            $line = Text::trim($lines[$j]);
            if (str_starts_with($line, '|-')) {
                $headerRows ??= $rows === [] ? null : count($rows);

                continue;
            }
            $cells = array_map(Text::trim(...), explode('|', substr($line, 1, str_ends_with($line, '|') && strlen($line) > 1 ? -1 : null)));
            if (! self::cookies($cells)) {
                $rows[] = $cells;
            }
        }
        if ($headerRows === null || $headerRows === count($rows)) {
            $headerRows = 0;
        }

        $this->out->table($caption, array_slice($rows, 0, $headerRows), array_slice($rows, $headerRows));

        return $j - 1;
    }

    /**
     * Whether a row holds only column-width cookies (`<10>`, `<l>`, `<r5>`) and empty cells.
     *
     * @param  list<string>  $cells
     */
    private static function cookies(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== '' && preg_match('/^<[lrc]?\d*>$/', $cell) !== 1) {
                return false;
            }
        }

        return implode('', $cells) !== '';
    }

    /**
     * An item's marker, one space and the rest of its line.
     */
    private static function itemText(string $line): string
    {
        preg_match(self::ITEM, $line, $match);

        return Text::trim(Text::trim($match[0] ?? '') . ' ' . substr($line, strlen($match[0] ?? '')));
    }

    /**
     * The kind of list item a line opens, "unordered" or "ordered", or null.
     */
    private static function item(string $line): ?string
    {
        if (preg_match(self::ITEM, $line, $match) !== 1 || preg_match(self::RULE, $line) === 1) {
            return null;
        }

        return ($match[3] ?? '') !== '' ? 'ordered' : 'unordered';
    }
}
