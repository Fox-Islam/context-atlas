<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing\Html;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\BlockType;
use Phox\ContextAtlas\Text;

/**
 * A `table` element as a table block: text `Table: <caption>`, then the header and rows with
 * cells joined by ` | `; meta `{caption, header, rows}`. A cell is repeated across its colspan
 * and carried down its rowspan; tables inside cells are read as text.
 */
final class Table
{
    private const int MAX_SPAN = 100;

    /**
     * @return array{caption: string, header: list<string>, rows: list<list<string>>}|null
     */
    public static function read(Element $table, bool $inArticle): ?array
    {
        $caption = '';
        $rows = [];
        self::rows($table, $inArticle, false, $rows, $caption);

        if ($caption === '' && self::isTitleRow($rows)) {
            $caption = $rows[0]['cells'][0]['text'];
            array_shift($rows);
        }

        $grid = self::grid($rows);
        $header = $grid !== [] && $grid[0]['header'] ? $grid[0]['cells'] : [];
        if ($header !== []) {
            array_shift($grid);
        }
        if (implode('', $header) === '') {
            $header = [];
        }
        $body = [];
        foreach ($grid as $row) {
            if (implode('', $row['cells']) !== '') {
                $body[] = $row['cells'];
            }
        }

        return $header === [] && $body === [] ? null : ['caption' => $caption, 'header' => $header, 'rows' => $body];
    }

    /**
     * @param  array{caption: string, header: list<string>, rows: list<list<string>>}  $table
     */
    public static function block(array $table): Block
    {
        $lines = $table['caption'] !== '' ? ['Table: ' . $table['caption']] : [];
        foreach ($table['header'] !== [] ? [$table['header'], ...$table['rows']] : $table['rows'] as $cells) {
            $lines[] = implode(' | ', $cells);
        }

        return new Block(BlockType::Table, implode("\n", $lines), null, $table);
    }

    /**
     * A first row, followed by others, of one non-empty cell spanning more than one column and
     * at least as many as any other row's cells span (rowspans aside).
     *
     * @param  list<array{cells: list<array{text: string, colspan: int, rowspan: int, th: bool}>, head: bool}>  $rows
     */
    private static function isTitleRow(array $rows): bool
    {
        if (count($rows) < 2 || count($rows[0]['cells']) !== 1 || $rows[0]['cells'][0]['colspan'] < 2 || $rows[0]['cells'][0]['text'] === '') {
            return false;
        }

        foreach (array_slice($rows, 1) as $row) {
            if (array_sum(array_column($row['cells'], 'colspan')) > $rows[0]['cells'][0]['colspan']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The rows of a table, not those of tables inside it, and its first non-empty caption.
     *
     * @param  list<array{cells: list<array{text: string, colspan: int, rowspan: int, th: bool}>, head: bool}>  $rows
     */
    private static function rows(Element $parent, bool $inArticle, bool $head, array &$rows, string &$caption): void
    {
        foreach ($parent->children as $child) {
            if (! $child instanceof Element || $child->name === 'table' || Content::dropped($child, $inArticle)) {
                continue;
            }
            if ($child->name === 'caption') {
                $caption = $caption !== '' ? $caption : Text::squash(Content::line($child, $inArticle));
            } elseif ($child->name === 'tr') {
                $rows[] = ['cells' => self::cells($child, $inArticle), 'head' => $head];
            } else {
                self::rows($child, $inArticle, $head || $child->name === 'thead', $rows, $caption);
            }
        }
    }

    /**
     * @return list<array{text: string, colspan: int, rowspan: int, th: bool}>
     */
    private static function cells(Element $parent, bool $inArticle): array
    {
        $cells = [];

        foreach ($parent->children as $child) {
            if (! $child instanceof Element || in_array($child->name, ['table', 'tr'], true) || Content::dropped($child, $inArticle)) {
                continue;
            }
            if ($child->name === 'td' || $child->name === 'th') {
                $cells[] = [
                    'text' => Text::squash(Content::line($child, $inArticle)),
                    'colspan' => self::span($child->attribute('colspan')),
                    'rowspan' => self::span($child->attribute('rowspan')),
                    'th' => $child->name === 'th',
                ];
            } else {
                array_push($cells, ...self::cells($child, $inArticle));
            }
        }

        return $cells;
    }

    private static function span(?string $value): int
    {
        $value = trim($value ?? '', " \t\n\f\r");

        return preg_match('/^[0-9]{1,9}$/', $value) === 1 ? max(1, min(self::MAX_SPAN, (int) $value)) : 1;
    }

    /**
     * Rows of cell texts. A row is a header row when it sits in a `thead` or all its cells
     * are `th`.
     *
     * @param  list<array{cells: list<array{text: string, colspan: int, rowspan: int, th: bool}>, head: bool}>  $rows
     * @return list<array{cells: list<string>, header: bool}>
     */
    private static function grid(array $rows): array
    {
        $grid = [];
        $carry = [];

        foreach ($rows as $row) {
            $out = [];
            $column = 0;
            foreach ($row['cells'] as $cell) {
                $column = self::carried($carry, $out, $column);
                for ($k = 0; $k < $cell['colspan']; $k++, $column++) {
                    $out[] = $cell['text'];
                    if ($cell['rowspan'] > 1) {
                        $carry[$column] = [$cell['text'], $cell['rowspan'] - 1];
                    }
                }
            }
            self::carried($carry, $out, $column);

            $allTh = $row['cells'] !== [] && array_filter($row['cells'], static fn (array $cell): bool => ! $cell['th']) === [];
            $grid[] = ['cells' => $out, 'header' => $row['head'] || $allTh];
        }

        return $grid;
    }

    /**
     * Places the cells carried down into `$column` and the columns after it, while there are
     * any, and returns the next free column.
     *
     * @param  array<int, array{string, int}>  $carry
     * @param  list<string>  $out
     */
    private static function carried(array &$carry, array &$out, int $column): int
    {
        while (isset($carry[$column])) {
            $out[] = $carry[$column][0];
            if (--$carry[$column][1] === 0) {
                unset($carry[$column]);
            }
            $column++;
        }

        return $column;
    }
}
