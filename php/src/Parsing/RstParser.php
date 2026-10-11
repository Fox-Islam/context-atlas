<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Text;

/**
 * Reads the block structure of reStructuredText: section titles, paragraphs, lists, field and
 * definition lists, literal blocks, directives, and simple, grid, list and CSV tables. Inline
 * markup is left in the text. The rules are stated in spec/formats/rst.md.
 */
final class RstParser
{
    private const string ADORNMENT = '/^([!-\/:-@\[-`{-~])\1*$/';

    private const string EXPLICIT = '/^ *\.\.(?: |$)/';

    private const string DIRECTIVE = '/^ *\.\. +([A-Za-z0-9]+(?:[-_.:+][A-Za-z0-9]+)*)::(?: +(.*))?$/';

    private const string FOOTNOTE = '/^ *\.\. +\[([^\]]+)\](?: +(.*))?$/';

    private const string BULLET = '/^ *[-*+•‣⁃](?: +|$)/u';

    private const string ENUMERATOR = '/^ *(?:\(?(?:\d+|#|[A-Za-z]|[ivxlcdmIVXLCDM]+)[.)]|\((?:\d+|#|[A-Za-z]|[ivxlcdmIVXLCDM]+)\))(?: +|$)/';

    private const string FIELD = '/^ *:(?:[^: ]|[^: ][^:]*[^: ]):(?: +|$)/';

    private const string LINE_BLOCK = '/^( *)\|(?: +|$)/';

    private const string OPTION = '/^ +:([^: ]|[^: ][^:]*[^: ]):(?: +(.*))?$/';

    private const string GRID_BORDER = '/^\+(?:-+\+)+$/';

    private const string GRID_SEPARATOR = '/^\+(?:[-=]+\+)+$/';

    private const string SIMPLE_BORDER = '/^=+(?: +=+)+$/';

    private const array CODE = ['code', 'code-block', 'sourcecode', 'parsed-literal', 'math', 'doctest', 'testcode', 'testoutput'];

    private const array ADMONITIONS = ['attention', 'caution', 'danger', 'error', 'hint', 'important', 'note', 'tip', 'warning', 'seealso'];

    private const array TITLED = ['admonition', 'rubric', 'topic', 'sidebar'];

    private const array DROPPED = ['toctree', 'index', 'image', 'include', 'literalinclude', 'raw', 'contents', 'highlight', 'sectnum', 'header', 'footer', 'meta', 'title', 'default-role', 'role', 'class', 'target-notes', 'module', 'currentmodule', 'testsetup', 'testcleanup'];

    private const array SIGNATURES = ['function', 'class', 'method', 'staticmethod', 'classmethod', 'attribute', 'property', 'data', 'exception', 'decorator', 'describe', 'object', 'envvar', 'option', 'confval'];

    private BlockBuilder $out;

    /** @var list<string> adornment styles (character, then "o" with an overline or "u") in order of first appearance */
    private array $styles = [];

    private string $caption = '';

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
            static fn (string $line): string => rtrim(self::expandTabs($line), " \t"),
            explode("\n", str_replace(["\r\n", "\r"], "\n", $text)),
        );
        $parser = new self();
        $parser->run($lines);

        return $parser->out->blocks();
    }

    /**
     * @param  list<string>  $lines
     */
    private function run(array $lines): void
    {
        $n = count($lines);
        $blank = true;
        $literal = null;
        $listBase = 0;
        $listKind = '';
        $paraIndent = 0;

        for ($i = 0; $i < $n;) {
            $line = $lines[$i];

            if ($line === '') {
                if ($this->endsWithLiteralMarker()) {
                    $literal = $paraIndent;
                }
                if (! $this->out->inList()) {
                    $this->out->close();
                }
                $blank = true;
                $i++;

                continue;
            }

            $indent = strspn($line, ' ');

            if ($literal !== null && $indent > $literal) {
                $i = $this->literal($lines, $i, $literal);
                $literal = null;

                continue;
            }
            $literal = null;

            $termAllowed = $blank || ! $this->out->isOpen() || ($this->out->inList() && $listKind === 'term' && $indent === $listBase);
            $item = $this->item($line) ?? ($termAllowed && $this->isTerm($lines, $i) ? [$line, 0, 'term'] : null);
            $continues = $item !== null && ($indent > $listBase || ($indent === $listBase && $item[2] === $listKind));
            if ($this->out->inList() && (($item !== null && ! $continues) || ($item === null && $blank && $indent <= $listBase))) {
                $this->out->close();
            }

            $start = $blank || ! $this->out->isOpen();
            $next = $start ? $this->block($lines, $i, $indent) : null;
            if ($next !== null) {
                $i = $next;
                $blank = true;

                continue;
            }

            if ($item !== null && ($start || $this->out->inList())) {
                if (! $this->out->inList()) {
                    $listBase = $indent;
                    $listKind = $item[2];
                }
                if ($item[2] === 'term') {
                    $this->out->item(str_ends_with($line, ':') ? $line : $line . ':');
                    $this->out->extend($lines[++$i]);
                    $paraIndent = strspn($lines[$i], ' ');
                } else {
                    $this->out->item($item[0]);
                    $paraIndent = $item[1];
                }
            } elseif ($this->out->inList()) {
                if ($blank) {
                    $paraIndent = $indent;
                }
                $this->out->extend($line);
            } else {
                if (! $this->out->isOpen()) {
                    $paraIndent = $indent;
                }
                $this->out->text($line);
            }

            $blank = false;
            $i++;
        }

        $this->endsWithLiteralMarker();
        $this->out->close();
    }

    /**
     * Reads a construct that only opens a block: a section title, transition, explicit markup,
     * anonymous target, table or doctest block. Returns the next line to read, or null.
     *
     * @param  list<string>  $lines
     */
    private function block(array $lines, int $i, int $indent): ?int
    {
        $line = $lines[$i];

        if ($indent === 0) {
            $title = $this->title($lines, $i);
            if ($title !== null) {
                return $title;
            }
            if (preg_match(self::ADORNMENT, $line) === 1 && mb_strlen($line) >= 4) {
                $this->out->close();

                return $i + 1;
            }
        }

        $body = substr($line, $indent);

        return match (true) {
            preg_match(self::EXPLICIT, $line) === 1 => $this->explicit($lines, $i, $indent),
            preg_match('/^__(?: |$)/', $body) === 1 => $this->skip($lines, $i, $indent),
            preg_match(self::GRID_BORDER, $body) === 1 => $this->grid($lines, $i, $indent),
            preg_match(self::SIMPLE_BORDER, $body) === 1 => $this->simple($lines, $i, $indent),
            preg_match('/^>>>(?: |$)/', $body) === 1 => $this->doctest($lines, $i),
            default => null,
        };
    }

    /**
     * @param  list<string>  $lines
     */
    private function title(array $lines, int $i): ?int
    {
        $line = $lines[$i];
        $over = preg_match(self::ADORNMENT, $line) === 1;
        $text = trim($lines[$i + 1] ?? '', ' ');

        if ($over && $text !== '' && ($lines[$i + 2] ?? null) === $line && mb_strlen($text) <= mb_strlen($line)) {
            $this->out->heading($text, $this->level($line[0] . 'o'));

            return $i + 3;
        }

        $under = $lines[$i + 1] ?? '';
        if (! $over && preg_match(self::ADORNMENT, $under) === 1 && (mb_strlen($under) >= mb_strlen($line) || mb_strlen($under) >= 4)) {
            $this->out->heading($line, $this->level($under[0] . 'u'));

            return $i + 2;
        }

        return null;
    }

    private function level(string $style): int
    {
        $level = array_search($style, $this->styles, true);
        if ($level === false) {
            $this->styles[] = $style;

            return count($this->styles);
        }

        return $level + 1;
    }

    /**
     * A list item's text (its marker, one space and the rest of the line), the column its
     * content starts at and its kind, or null.
     *
     * @return array{string, int, string}|null
     */
    private function item(string $line): ?array
    {
        foreach (['bullet' => self::BULLET, 'enumerated' => self::ENUMERATOR, 'field' => self::FIELD] as $kind => $pattern) {
            if (preg_match($pattern, $line, $match) === 1) {
                $kind = $kind === 'bullet' ? $kind . ' ' . Text::trim($match[0]) : $kind;

                return [Text::trim(Text::trim($match[0]) . ' ' . substr($line, strlen($match[0]))), mb_strlen($match[0]) + (str_ends_with($match[0], ' ') ? 0 : 1), $kind];
            }
        }
        if (preg_match(self::LINE_BLOCK, $line, $match) === 1) {
            return [substr($line, strlen($match[0])), strlen($match[1]), 'line'];
        }

        return null;
    }

    /**
     * A line opening a definition: not a list item or explicit markup, and followed directly
     * by a more indented line.
     *
     * @param  list<string>  $lines
     */
    private function isTerm(array $lines, int $i): bool
    {
        $next = $lines[$i + 1] ?? '';

        return $next !== ''
            && strspn($next, ' ') > strspn($lines[$i], ' ')
            && $this->item($lines[$i]) === null
            && preg_match(self::EXPLICIT, $lines[$i]) !== 1;
    }

    /**
     * Rewrites a trailing `::` on the open text and returns whether there was one.
     */
    private function endsWithLiteralMarker(): bool
    {
        $last = $this->out->last();
        if ($last === null || ! str_ends_with($last, '::')) {
            return false;
        }

        $this->out->replaceLast(match (true) {
            $last === '::' => '',
            preg_match('/\s::$/u', $last) === 1 => rtrim(substr($last, 0, -2)),
            default => substr($last, 0, -1),
        });

        return true;
    }

    /**
     * The line after the block that line `$i` opens: the lines after it that are blank or
     * indented more than `$indent`, without trailing blank lines.
     *
     * @param  list<string>  $lines
     */
    private function extentEnd(array $lines, int $i, int $indent): int
    {
        $end = $i + 1;
        for ($j = $i + 1, $n = count($lines); $j < $n; $j++) {
            if ($lines[$j] === '') {
                continue;
            }
            if (strspn($lines[$j], ' ') <= $indent) {
                break;
            }
            $end = $j + 1;
        }

        return $end;
    }

    /**
     * @param  list<string>  $lines
     */
    private function literal(array $lines, int $i, int $indent): int
    {
        $end = $this->extentEnd($lines, $i - 1, $indent);
        $this->out->code(BlockBuilder::dedent(array_slice($lines, $i, $end - $i)));

        return $end;
    }

    /**
     * @param  list<string>  $lines
     */
    private function doctest(array $lines, int $i): int
    {
        $end = $i;
        while ($end < count($lines) && $lines[$end] !== '') {
            $end++;
        }
        $this->out->code(BlockBuilder::dedent(array_slice($lines, $i, $end - $i)));

        return $end;
    }

    /**
     * @param  list<string>  $lines
     */
    private function skip(array $lines, int $i, int $indent): int
    {
        return $this->extentEnd($lines, $i, $indent);
    }

    /**
     * @param  list<string>  $lines
     */
    private function explicit(array $lines, int $i, int $indent): int
    {
        $line = $lines[$i];

        if (trim($line, ' ') === '..' && ($lines[$i + 1] ?? '') === '') {
            return $i + 1;
        }

        $end = $this->extentEnd($lines, $i, $indent);
        $body = array_slice($lines, $i + 1, $end - $i - 1);

        if (preg_match(self::DIRECTIVE, $line, $match) === 1) {
            $this->directive(mb_strtolower($match[1]), trim($match[2] ?? '', ' '), $body);
        } elseif (preg_match(self::FOOTNOTE, $line, $match) === 1) {
            $this->nested(['[' . $match[1] . '] ' . ($match[2] ?? ''), ...BlockBuilder::dedent($body)]);
        }

        return $end;
    }

    /**
     * @param  list<string>  $body
     */
    private function directive(string $name, string $argument, array $body): void
    {
        $options = [];
        $optionIndent = null;
        $k = 0;
        for ($n = count($body); $k < $n; $k++) {
            if (preg_match(self::OPTION, $body[$k], $match) === 1) {
                $optionIndent = strspn($body[$k], ' ');
                $options[mb_strtolower($match[1])] = $match[2] ?? '';
            } elseif ($optionIndent === null || $body[$k] === '' || strspn($body[$k], ' ') <= $optionIndent) {
                break;
            }
        }
        $content = BlockBuilder::dedent(array_slice($body, $k));
        $kind = str_contains($name, ':') ? substr($name, (int) strrpos($name, ':') + 1) : $name;

        if (in_array($name, self::CODE, true)) {
            $this->out->code($content);
        } elseif (in_array($name, self::ADMONITIONS, true)) {
            $this->nested([$argument, ...$content]);
        } elseif (in_array($name, self::TITLED, true) || in_array($kind, self::SIGNATURES, true)) {
            $this->out->paragraph($argument);
            $this->nested($content);
        } elseif ($name === 'table') {
            $this->caption = $argument;
            $this->nested($content);
            $this->caption = '';
        } elseif ($name === 'list-table') {
            $this->table($this->listTable($content), (int) ($options['header-rows'] ?? 0), $argument);
        } elseif ($name === 'csv-table') {
            $this->csvTable($argument, $options, $content);
        } elseif (! in_array($name, self::DROPPED, true)) {
            $this->nested($content);
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function nested(array $lines): void
    {
        $this->out->close();
        $this->run($lines);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function table(array $rows, int $headerRows, string $caption = ''): void
    {
        $this->out->table($caption !== '' ? $caption : $this->caption, array_slice($rows, 0, $headerRows), array_slice($rows, $headerRows));
    }

    /**
     * @param  list<string>  $lines
     */
    private function grid(array $lines, int $i, int $indent): int
    {
        $border = substr($lines[$i], $indent);
        $bounds = [];
        for ($c = 0, $w = strlen($border); $c < $w; $c++) {
            if ($border[$c] === '+') {
                $bounds[] = $c;
            }
        }

        $rows = [];
        $cells = [];
        $headerRows = 0;
        $end = $i + 1;
        for ($n = count($lines); $end < $n; $end++) {
            $line = $lines[$end];
            if (strspn($line, ' ') !== $indent || ! in_array($line[$indent] ?? '', ['+', '|'], true)) {
                break;
            }
            $line = substr($line, $indent);
            if (preg_match(self::GRID_SEPARATOR, $line) === 1) {
                if ($cells !== []) {
                    $rows[] = $cells;
                }
                $cells = [];
                if (str_contains($line, '=')) {
                    $headerRows = count($rows);
                }

                continue;
            }
            $cells = self::gridLine($line, $bounds, $cells);
        }
        if ($cells !== []) {
            $rows[] = $cells;
        }

        $this->table($rows, $headerRows);

        return $end;
    }

    /**
     * Adds one line of a grid row to its cells. The line is cut at the border's `+` columns
     * where it has a `|` or `+`, so text spanning columns lands in the first of them. A piece
     * of only `-`, `=` and `+` is a partial row separator and is skipped; a line-block `| `
     * opening a piece is removed.
     *
     * @param  list<int>  $bounds
     * @param  list<string>  $cells
     * @return list<string>
     */
    private static function gridLine(string $line, array $bounds, array $cells): array
    {
        $last = count($bounds) - 1;
        $from = 0;
        for ($b = 1; $b <= $last; $b++) {
            $char = mb_substr($line, $bounds[$b], 1);
            if ($b < $last && $char !== '|' && $char !== '+') {
                continue;
            }
            $segment = (string) preg_replace('/^\| +/', '', trim(mb_substr($line, $bounds[$from] + 1, $bounds[$b] - $bounds[$from] - 1), ' '));
            $cells[$from] ??= '';
            if (preg_match('/^[-=+]+$/', $segment) !== 1) {
                $cells[$from] = trim($cells[$from] . ' ' . $segment, ' ');
            }
            for ($c = $from + 1; $c < $b; $c++) {
                $cells[$c] ??= '';
            }
            $from = $b;
        }
        ksort($cells);

        return array_values($cells);
    }

    /**
     * @param  list<string>  $lines
     */
    private function simple(array $lines, int $i, int $indent): ?int
    {
        $end = $i + 1;
        while (($lines[$end] ?? '') !== '') {
            $end++;
        }
        $borders = [];
        for ($j = $i + 1; $j < $end; $j++) {
            if (strspn($lines[$j], ' ') === $indent && preg_match(self::SIMPLE_BORDER, substr($lines[$j], $indent)) === 1) {
                $borders[] = $j;
            }
        }
        if ($borders === [] || $borders[count($borders) - 1] !== $end - 1) {
            return null;
        }

        preg_match_all('/=+/', substr($lines[$i], $indent), $runs, PREG_OFFSET_CAPTURE);
        $starts = array_map(static fn (array $run): int => $run[1], $runs[0]);
        $rows = [];
        $headerRows = count($borders) > 1 ? null : 0;
        for ($j = $i + 1; $j < $end - 1; $j++) {
            $line = mb_substr($lines[$j], $indent);
            if (in_array($j, $borders, true)) {
                $headerRows ??= count($rows);

                continue;
            }
            if (preg_match('/^[- ]*$/', $line) === 1) {
                continue;
            }
            $cells = [];
            foreach ($starts as $c => $start) {
                $cells[] = trim(isset($starts[$c + 1]) ? mb_substr($line, $start, $starts[$c + 1] - $start) : mb_substr($line, $start), ' ');
            }
            if ($cells[0] === '' && $rows !== []) {
                $previous = array_pop($rows);
                $rows[] = array_map(static fn (string $before, string $cell): string => trim($before . ' ' . $cell, ' '), $previous, $cells);
            } else {
                $rows[] = $cells;
            }
        }

        $this->table($rows, $headerRows ?? 0);

        return $end;
    }

    /**
     * The rows of a list-table: each top-level bullet is a row, and each bullet at the
     * column of the first inner bullet a cell; other lines continue the cell before them.
     *
     * @param  list<string>  $content
     * @return list<list<string>>
     */
    private function listTable(array $content): array
    {
        $rows = [];
        $cellIndent = null;
        foreach ($content as $line) {
            $indent = strspn($line, ' ');
            if ($indent === 0 && preg_match('/^[-*+](?: +|$)/', $line, $match) === 1) {
                $rows[] = [];
                $line = str_repeat(' ', strlen($match[0])) . substr($line, strlen($match[0]));
                $indent = strlen($match[0]);
            }
            $r = count($rows) - 1;
            if ($r < 0 || trim($line, ' ') === '') {
                continue;
            }
            if (preg_match('/^ *[-*+](?: +|$)(.*)$/', $line, $match) === 1 && ($cellIndent ?? $indent) === $indent) {
                $cellIndent = $indent;
                $rows[$r][] = $match[1];
            } elseif ($rows[$r] !== []) {
                $rows[$r][] = trim(array_pop($rows[$r]) . ' ' . trim($line, ' '), ' ');
            }
        }

        return array_values($rows);
    }

    /**
     * @param  array<string, string>  $options
     * @param  list<string>  $content
     */
    private function csvTable(string $caption, array $options, array $content): void
    {
        $delim = $options['delim'] ?? ',';
        $delim = match ($delim) {
            'tab' => "\t",
            'space' => ' ',
            default => strlen($delim) === 1 ? $delim : ',',
        };
        $rows = isset($options['header']) ? [BlockBuilder::delimited($options['header'], ',')] : [];
        foreach ($content as $line) {
            if (trim($line, ' ') !== '') {
                $rows[] = BlockBuilder::delimited($line, $delim);
            }
        }

        $this->table($rows, count($rows) > 0 && isset($options['header']) ? 1 + (int) ($options['header-rows'] ?? 0) : (int) ($options['header-rows'] ?? 0), $caption);
    }

    private static function expandTabs(string $line): string
    {
        if (! str_contains($line, "\t")) {
            return $line;
        }
        $pieces = explode("\t", $line);
        $out = (string) array_shift($pieces);
        foreach ($pieces as $piece) {
            $out .= str_repeat(' ', 8 - mb_strlen($out) % 8) . $piece;
        }

        return $out;
    }
}
