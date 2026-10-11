<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Text;

/**
 * Reads the block structure of AsciiDoc: section titles, paragraphs, lists, listing and literal
 * blocks, compound blocks read for their content, and tables. Attribute entries, block
 * attributes, comments and block macros are dropped. Inline markup is left in the text. The
 * rules are stated in spec/formats/asciidoc.md.
 */
final class AsciidocParser
{
    private const string HEADING = '/^(={1,6}|#{1,6})[ \t]+(.+?)(?:[ \t]+\1)?$/';

    private const string SETEXT = '/^(=+|-+|~+|\^+|\++)$/';

    private const string DELIMITER = '/^(?:-{4,}|\.{4,}|\+{4,}|_{4,}|={4,}|\*{4,}|--|[|,:]={3,})$/';

    private const string ATTRIBUTE_ENTRY = '/^:!?[A-Za-z0-9_][A-Za-z0-9_-]*!?:(?:[ \t].*)?$/';

    private const string BLOCK_ATTRIBUTES = '/^\[(|[#.%A-Za-z0-9_{,"\'\[].*)\]$/';

    private const string BLOCK_TITLE = '/^\.([^. \t].*)$/';

    private const string PREPROCESSOR = '/^(?:ifdef|ifndef|ifeval|endif)::[^ \t]*\[.*\]$/';

    private const string INCLUDE = '/^include(?:-code)?::([^\[]*)\[.*\]$/';

    private const string ATTRIBUTE_DEFINITION = '/^:(!?)([A-Za-z0-9_][A-Za-z0-9_-]*)(!?):(?:[ \t]+(.*))?$/';

    private const string BLOCK_MACRO = '/^[a-z][A-Za-z0-9_-]*::[^ \t]*\[.*\]$/';

    private const string LIST_ITEM = '/^[ \t]*(\*{1,5}|-|\.{1,5}|\d+\.|[A-Za-z]\.|[ivxIVX]+\)|<(?:\d+|\.)>)[ \t]+(?=[^ \t])/';

    private const string DESCRIPTION = '/^[ \t]*([^ \t].*?)(?::{2,4}|;;)(?:[ \t]+(.*))?$/';

    private const string CELL_SPEC = '/^(?:(\d+)\*)?(?:(\d+)?(?:\.\d+)?\+)?[<^>]?(?:\.[<^>])?[adehlmsv]?$/';

    private const string CELL_SPEC_END = '/[ \t\n]((?:\d+\*)?(?:\d*(?:\.\d+)?\+)?[<^>]?(?:\.[<^>])?[adehlmsv]?)\z/';

    private const array SETEXT_LEVELS = ['=' => 1, '-' => 2, '~' => 3, '^' => 4, '+' => 5];

    private BlockBuilder $out;

    /** @var array<string, string> the built-in character attributes */
    private const array BUILT_IN = [
        'asterisk' => '*', 'nbsp' => "\u{00A0}", 'sp' => ' ', 'empty' => '', 'blank' => '', 'zwsp' => "\u{200B}",
        'amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"', 'apos' => "'", 'plus' => '+', 'caret' => '^',
        'tilde' => '~', 'backtick' => '`', 'startsb' => '[', 'endsb' => ']', 'vbar' => '|', 'pp' => '++',
        'cpp' => 'C++', 'deg' => "\u{00B0}", 'two-colons' => '::', 'two-semicolons' => ';;', 'brvbar' => "\u{00A6}",
        'ldquo' => "\u{201C}", 'rdquo' => "\u{201D}", 'lsquo' => "\u{2018}", 'rsquo' => "\u{2019}", 'wj' => "\u{2060}",
    ];

    /** @var array<string, string> attributes defined so far, by lowercased name, starting from the built-ins */
    private array $attributes = self::BUILT_IN;

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
        $parser->run($lines, true);

        return $parser->out->blocks();
    }

    /**
     * @param  list<string>  $lines
     */
    private function run(array $lines, bool $document): void
    {
        $n = count($lines);
        $blank = true;
        $attached = false;
        $attributes = '';
        $title = '';
        $listKind = null;

        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];

            if ($line === '') {
                if (! $this->out->inList()) {
                    $this->out->close();
                }
                $blank = true;
                $attached = false;

                continue;
            }
            if (preg_match('/^\/{4,}$/', $line) === 1) {
                $i = $this->closing($lines, $i);

                continue;
            }
            if (str_starts_with($line, '//') || preg_match(self::PREPROCESSOR, $line) === 1) {
                continue;
            }
            if (preg_match(self::INCLUDE, $line, $match) === 1) {
                $this->out->paragraph('[include: ' . $this->inline(Text::trim($match[1])) . ']');
                $blank = true;

                continue;
            }

            $kind = self::kind($line);
            $isItem = $kind !== null;
            if ($this->out->inList() && $blank && $kind !== $listKind) {
                $this->out->close();
            }

            $setext = $this->setext($line);
            if ($setext !== null) {
                if ($document && $setext[1] === 1 && $this->out->isEmpty()) {
                    $i = $this->header($lines, $i);
                }
                $this->out->heading($this->inline($setext[0]), $setext[1]);
                $blank = true;

                continue;
            }

            $start = $blank || $attached || ! $this->out->isOpen();
            $blank = false;
            $attached = false;

            if (preg_match(self::DELIMITER, $line) === 1) {
                $end = $this->closing($lines, $i);
                $this->delimited($line, array_slice($lines, $i + 1, $end - $i - 1), $attributes, $title);
                [$i, $attributes, $title, $blank] = [$end, '', '', true];

                continue;
            }
            if (preg_match(self::BLOCK_ATTRIBUTES, $line, $match) === 1) {
                $this->out->close();
                $attributes = $match[1];
                $blank = true;

                continue;
            }
            if ($line === '+') {
                $attached = true;

                continue;
            }

            if ($start) {
                if (preg_match(self::ATTRIBUTE_ENTRY, $line) === 1) {
                    $i = $this->define($lines, $i);
                    $blank = true;

                    continue;
                }
                if (preg_match(self::BLOCK_TITLE, $line, $match) === 1) {
                    $title = $match[1];
                    $blank = true;

                    continue;
                }
                if (preg_match(self::HEADING, $line, $match) === 1) {
                    $level = strlen($match[1]);
                    if ($document && $level === 1 && $match[1] === '=' && $this->out->isEmpty()) {
                        $i = $this->header($lines, $i);
                    }
                    $this->out->heading($this->inline($match[2]), $level);
                    [$attributes, $title, $blank] = ['', '', true];

                    continue;
                }
                if (preg_match(self::BLOCK_MACRO, $line) === 1 || preg_match('/^(?:\'{3,}|<{3,})$/', $line) === 1) {
                    $this->out->close();
                    $blank = true;

                    continue;
                }

                if ($title !== '') {
                    $this->out->paragraph($this->inline($title));
                }
                $title = '';
                $style = self::style($attributes);
                $attributes = '';
                if (! $isItem && (in_array($style, ['source', 'listing', 'literal'], true) || $line[0] === ' ' || $line[0] === "\t")) {
                    $end = $i;
                    while ($end < $n && $lines[$end] !== '') {
                        $end++;
                    }
                    $this->out->code(BlockBuilder::dedent(array_slice($lines, $i, $end - $i)));
                    $i = $end - 1;
                    $blank = true;

                    continue;
                }
            }

            if ($isItem && ($start || $this->out->inList())) {
                $listKind = $this->out->inList() ? $listKind : $kind;
                $this->out->item($this->inline($kind === 'description' ? self::description($line) : self::itemText($line)));
            } elseif ($this->out->inList()) {
                $this->out->extend($this->inline($line));
            } else {
                $this->out->text($this->inline($line));
            }
        }

        $this->out->close();
    }

    /**
     * A two-line section title: an open paragraph of one line starting with a letter, digit
     * or `_`, underlined by one repeated character whose length is within one of the title's.
     *
     * @return array{string, int}|null
     */
    private function setext(string $line): ?array
    {
        $title = $this->out->inList() ? null : $this->out->last();
        if ($title === null || $this->out->count() !== 1 || preg_match(self::SETEXT, $line) !== 1 || preg_match('/^[\p{L}\p{N}_]/u', $title) !== 1) {
            return null;
        }
        if (abs(mb_strlen($line) - mb_strlen($title)) > 1) {
            return null;
        }
        $this->out->replaceLast('');

        return [$title, self::SETEXT_LEVELS[$line[0]]];
    }

    /**
     * Skips the document header after the document title: the lines up to the first blank one.
     *
     * @param  list<string>  $lines
     */
    private function header(array $lines, int $i): int
    {
        $end = $i;
        while (($lines[$end + 1] ?? '') !== '') {
            $end++;
            if (preg_match(self::ATTRIBUTE_ENTRY, $lines[$end]) === 1) {
                $end = $this->define($lines, $end);
            }
        }
        $this->out->close();

        return $end;
    }

    /**
     * Records the attribute entry at line `$i` (a value continued by lines ending ` \\` is joined
     * with spaces; `:name!:` or `:!name:` removes it) and returns its last line.
     *
     * @param  list<string>  $lines
     */
    private function define(array $lines, int $i): int
    {
        preg_match(self::ATTRIBUTE_DEFINITION, $lines[$i], $match);
        $value = $match[4] ?? '';
        while (str_ends_with($value, ' \\') && $i + 1 < count($lines)) {
            $value = substr($value, 0, -2) . ' ' . Text::trim($lines[++$i]);
        }
        $name = mb_strtolower($match[2] ?? '');
        if (($match[1] ?? '') !== '' || ($match[3] ?? '') !== '') {
            unset($this->attributes[$name]);
        } else {
            $this->attributes[$name] = Text::squash($this->inline($value));
        }

        return $i;
    }

    /**
     * Inline substitutions on text: defined attribute references, then cross references and
     * links reduced to their text.
     */
    private function inline(string $text): string
    {
        $text = (string) preg_replace_callback(
            '/\{([A-Za-z0-9_][A-Za-z0-9_-]*)\}/',
            fn (array $m): string => $this->attributes[mb_strtolower($m[1])] ?? $m[0],
            $text,
        );
        $text = (string) preg_replace_callback(
            '/xref:([^ \t\[]+)\[([^\]]*)\]/',
            static fn (array $m): string => Text::trim($m[2]) !== '' ? Text::trim($m[2]) : $m[1],
            $text,
        );
        $text = (string) preg_replace('/<<[^,>]+,[ \t]*([^>]+?)[ \t]*>>/', '$1', $text);

        return (string) preg_replace_callback(
            '/(?:link:([^ \t\[]+)|\b((?:https?|ftp|irc):\/\/[^ \t\[]+|mailto:[^ \t\[]+))\[([^\]]*)\]/',
            static function (array $m): string {
                $url = $m[1] !== '' ? $m[1] : $m[2];
                $label = Text::trim($m[3]);

                return $label !== '' ? $label . ' (' . $url . ')' : $url;
            },
            $text,
        );
    }

    /**
     * The index of the line closing the delimited block opened at `$i`: the next line equal
     * to it, or the last line.
     *
     * @param  list<string>  $lines
     */
    private function closing(array $lines, int $i): int
    {
        for ($j = $i + 1, $n = count($lines); $j < $n; $j++) {
            if ($lines[$j] === $lines[$i]) {
                return $j;
            }
        }

        return count($lines);
    }

    /**
     * @param  list<string>  $content
     */
    private function delimited(string $delimiter, array $content, string $attributes, string $title): void
    {
        $this->out->close();
        $char = $delimiter[0];

        if (preg_match('/^[|,:]=/', $delimiter) === 1) {
            $this->table($char, $content, $attributes, $title);

            return;
        }
        if ($char === '+') {
            return;
        }

        $this->out->paragraph($title);
        if ($char === '-' && $delimiter !== '--' || $char === '.') {
            $this->out->code($content);

            return;
        }
        $this->run($content, false);
    }

    /**
     * @param  list<string>  $content
     */
    private function table(string $char, array $content, string $attributes, string $title): void
    {
        $format = preg_match('/\bformat="?(csv|dsv|tsv)\b/', $attributes, $match) === 1 ? $match[1] : ['|' => 'psv', ',' => 'csv', ':' => 'dsv'][$char];
        $implicit = ($content[0] ?? '') !== '' && ($content[1] ?? null) === '';
        $header = preg_match('/%header\b|\b(?:options|opts)="?[^"\]]*\bheader\b/', $attributes) === 1
            || ($implicit && preg_match('/%noheader\b|\b(?:options|opts)="?[^"\]]*\bnoheader\b/', $attributes) !== 1);

        if ($format === 'psv') {
            $rows = $this->psvRows($content, $attributes);
        } else {
            $separator = ['csv' => ',', 'dsv' => ':', 'tsv' => "\t"][$format];
            $rows = [];
            foreach ($content as $line) {
                if (trim($line, " \t") !== '') {
                    $rows[] = $format === 'csv' ? BlockBuilder::delimited($line, ',') : array_map(Text::trim(...), explode($separator, $line));
                }
            }
        }

        $rows = array_map(fn (array $row): array => array_map($this->inline(...), $row), $rows);
        $this->out->table($this->inline($title), $header ? array_slice($rows, 0, 1) : [], $header ? array_slice($rows, 1) : $rows);
    }

    /**
     * @param  list<string>  $content
     * @return list<list<string>>
     */
    private function psvRows(array $content, string $attributes): array
    {
        $first = '';
        foreach ($content as $line) {
            if (trim($line, " \t") !== '') {
                $first = $line;
                break;
            }
        }

        return array_chunk(self::cells(implode("\n", $content)), max(1, self::columns($attributes) ?? count(self::cells($first))));
    }

    /**
     * The cells of PSV text: each unescaped `|` opens a cell, and a cell spec just before it
     * (after whitespace or at the start) applies to that cell; text before the first `|` is dropped.
     *
     * @return list<string>
     */
    private static function cells(string $text): array
    {
        $pieces = preg_split('/(?<!\\\\)\|/', $text) ?: [];
        $last = count($pieces) - 1;
        $spec = Text::trim($pieces[0] ?? '');
        $spec = preg_match(self::CELL_SPEC, $spec) === 1 ? $spec : '';
        $cells = [];
        for ($k = 1; $k <= $last; $k++) {
            [$cell, $next] = $k < $last ? self::cellSpec($pieces[$k]) : [$pieces[$k], ''];
            array_push($cells, ...self::expand(str_replace('\\|', '|', $cell), $spec));
            $spec = $next;
        }

        return $cells;
    }

    /**
     * Splits a cell spec (`2+`, `3*`, `a`, `^.^`...) off the end of a cell's text.
     *
     * @return array{string, string}
     */
    private static function cellSpec(string $text): array
    {
        if (preg_match(self::CELL_SPEC_END, $text, $match) === 1 && $match[1] !== '') {
            return [substr($text, 0, -strlen($match[1])), $match[1]];
        }

        return [$text, ''];
    }

    /**
     * A cell repeated by a `n*` spec, each copy followed by empty cells for the columns a `n+`
     * spec spans.
     *
     * @return list<string>
     */
    private static function expand(string $text, string $spec): array
    {
        preg_match(self::CELL_SPEC, $spec, $match);
        $copies = max(1, (int) (($match[1] ?? '') !== '' ? $match[1] : 1));
        $span = max(1, (int) (($match[2] ?? '') !== '' ? $match[2] : 1));
        $cell = [Text::squash($text), ...array_fill(0, $span - 1, '')];

        return array_merge(...array_fill(0, $copies, $cell));
    }

    /**
     * The column count a `cols` attribute gives: a number, or a list of specs each counting
     * one column or its `n*` multiplier.
     */
    private static function columns(string $attributes): ?int
    {
        if (preg_match('/\bcols="([^"]*)"|\bcols=([^,\]]*)/', $attributes, $match) !== 1) {
            return null;
        }
        $value = Text::trim($match[1] !== '' ? $match[1] : ($match[2] ?? ''));
        if (preg_match('/^\d+$/', $value) === 1) {
            return max(1, (int) $value);
        }
        $count = 0;
        foreach (preg_split('/[,;]/', $value) ?: [] as $entry) {
            $count += preg_match('/^\s*(\d+)\*/', $entry, $multiplier) === 1 ? (int) $multiplier[1] : 1;
        }

        return max(1, $count);
    }

    /**
     * The block style: the first positional attribute, up to a `#`, `.`, `%` shorthand or comma.
     */
    private static function style(string $attributes): string
    {
        return preg_match('/^([A-Za-z][\w-]*)/', $attributes, $match) === 1 ? mb_strtolower($match[1]) : '';
    }

    /**
     * The kind of list a line is an item of: unordered, ordered, callout or description; or null.
     */
    private static function kind(string $line): ?string
    {
        if (preg_match(self::LIST_ITEM, $line, $match) === 1) {
            return match (true) {
                $match[1][0] === '*' || $match[1] === '-' => 'unordered',
                $match[1][0] === '<' => 'callout',
                default => 'ordered',
            };
        }

        return preg_match(self::DESCRIPTION, $line) === 1 ? 'description' : null;
    }

    /**
     * An item's marker, one space and the rest of its line.
     */
    private static function itemText(string $line): string
    {
        preg_match(self::LIST_ITEM, $line, $match);

        return Text::trim(Text::trim($match[0] ?? '') . ' ' . substr($line, strlen($match[0] ?? '')));
    }

    private static function description(string $line): string
    {
        preg_match(self::DESCRIPTION, $line, $match);
        $definition = Text::trim($match[2] ?? '');

        return Text::trim($match[1] ?? $line) . ':' . ($definition !== '' ? ' ' . $definition : '');
    }
}
