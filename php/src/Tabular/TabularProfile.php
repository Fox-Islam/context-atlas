<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tabular;

use Phox\ContextAtlas\Document\Document;
use Phox\ContextAtlas\Document\Unit;
use Phox\ContextAtlas\Index\Lexical;

/**
 * Ingests a spreadsheet. Each table becomes a unit whose text is its card, for routing; the
 * cleaned rows travel in the document's data for the planner to query. Sheets with no table
 * become passages. No Jev request is made at ingestion.
 */
final class TabularProfile
{
    public const string NAME = 'tabular';

    public static function ingest(Grid $grid, string $id, string $title): Document
    {
        ['units' => $units, 'extra' => $extra, 'tables' => $stored] = self::build($grid, $title);

        return new Document($id, self::NAME, $units, [], Lexical::build($units, $extra), ['profile' => self::NAME, 'score' => null, 'by' => 'file type'], ['tables' => $stored]);
    }

    /**
     * The table units of a grid, the extra search text of each (its label, category, id and alias
     * values), and the stored tables.
     *
     * @return array{units: list<Unit>, extra: array<string, string>, tables: list<array<string, mixed>>}
     */
    public static function build(Grid $grid, string $title, int $offset = 0): array
    {
        $visible = array_values(array_filter($grid->sheets, static fn (array $sheet): bool => ! $sheet['hidden']));
        $split = array_map(static fn (array $sheet): array => Tables::split($sheet['rows']), $visible);
        $notes = self::notes($visible, $split);

        $tables = [];
        $units = [];
        foreach ($visible as $s => $sheet) {
            $many = count($split[$s]['tables']) > 1;
            foreach ($split[$s]['tables'] as $i => $raw) {
                $tableId = 't' . ($offset + count($tables) + 1);
                $name = $raw['title'] ?? (count($visible) > 1 ? "{$title}: {$sheet['name']}" : $title) . ($many ? ' (' . ($i + 1) . ')' : '');
                $tables[] = Tables::normalise($tableId, $sheet['name'], $name, $raw, $notes);
            }
            if ($split[$s]['tables'] === [] && $split[$s]['text'] !== []) {
                $units[] = new Unit('s' . ($s + 1), 'passage', implode("\n", $split[$s]['text']), [$sheet['name']]);
            }
        }

        $tables = self::shareNotes(Tables::join($tables));
        $extra = [];
        foreach ($tables as $t => $table) {
            $units[] = new Unit($table['id'], 'table', Card::text($table, 6), [$table['sheet']], ['table' => $t]);
            $extra[$table['id']] = self::values($table);
        }

        $stored = array_map(static fn (array $table): array => [
            'columns' => array_values($table['columns']),
            'rows' => array_map(static fn (array $row): array => ['source' => $row['source'], 'values' => (object) $row['values']], $table['rows']),
        ] + $table, $tables);

        return ['units' => $units, 'extra' => $extra, 'tables' => $stored];
    }

    /**
     * A stored table with its columns keyed by name again. Columns are stored as a list,
     * because a JSON reader that uses plain objects reorders names that look like integers;
     * row values are stored as objects, so a row keyed by a column named "0" is not written
     * as a JSON list.
     *
     * @param  array<string, mixed>  $table
     * @return array<string, mixed>
     */
    public static function table(array $table): array
    {
        $columns = [];
        foreach ($table['columns'] as $column) {
            $columns[(string) $column['name']] = $column;
        }
        $rows = array_map(static fn (array $row): array => ['source' => $row['source'], 'values' => (array) $row['values']], $table['rows']);

        return ['columns' => $columns, 'rows' => $rows] + $table;
    }

    /**
     * Notes from a sheet named "Notes": rows whose first cell is "Note N", the header row included,
     * since a notes sheet often has no header.
     *
     * @param  list<array{name: string, hidden: bool, rows: array<int, array<int, string>>}>  $sheets
     * @param  list<array{text: list<string>, tables: list<array<string, mixed>>}>  $split
     * @return array<int, string>
     */
    private static function notes(array $sheets, array $split): array
    {
        $notes = [];

        foreach ($sheets as $s => $sheet) {
            if (preg_match('/^notes?$/i', trim($sheet['name'])) !== 1) {
                continue;
            }
            foreach ($split[$s]['tables'] as $table) {
                foreach ([$table['header'], ...array_column($table['rows'], 'cells')] as $cells) {
                    if (preg_match('/^Note (\d+)$/i', trim($cells[0] ?? ''), $match) === 1) {
                        $notes[(int) $match[1]] = $cells[1] ?? '';
                    }
                }
            }
        }

        return $notes;
    }

    /**
     * A label marked with a note in one table carries it wherever the same label appears.
     *
     * @param  list<array<string, mixed>>  $tables
     * @return list<array<string, mixed>>
     */
    private static function shareNotes(array $tables): array
    {
        $byLabel = [];
        foreach ($tables as $table) {
            foreach ($table['row_notes'] as $label => $refs) {
                $byLabel[mb_strtolower((string) $label)] = $refs;
            }
        }

        foreach ($tables as $t => $table) {
            foreach ($table['columns'] as $column) {
                foreach ($column['values'] ?? [] as $value) {
                    if (isset($byLabel[mb_strtolower($value)])) {
                        $tables[$t]['row_notes'][$value] = $byLabel[mb_strtolower($value)];
                    }
                }
            }
        }

        return $tables;
    }

    /**
     * Every value of a table's label and category columns, so exact-term search finds a table
     * by a supplier or country name its card does not show.
     *
     * @param  array<string, mixed>  $table
     */
    private static function values(array $table): string
    {
        $values = [];

        foreach ($table['columns'] as $column) {
            if (in_array($column['role'], ['label', 'category', 'id', 'alias'], true)) {
                array_push($values, ...($column['values'] ?? []));
            }
        }

        return implode(' ', $values);
    }
}
