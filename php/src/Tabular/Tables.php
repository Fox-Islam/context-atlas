<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tabular;

/**
 * Turns a sheet's grid into clean tables: finds where each table starts, folds title rows into
 * its caption, types its cells, melts period columns into rows and profiles each column.
 *
 * A table is plain data (arrays), because it travels inside the stored Document.
 */
final class Tables
{
    private const int CATEGORY_LIMIT = 60;

    private const float JOIN_OVERLAP = 0.9;

    /**
     * Splits a sheet into the text lines around its tables and the tables themselves. A table
     * starts at a row of two or more cells and runs while consecutive rows keep two or more;
     * one-cell rows before it are its caption, and a "Table X" line among them is its title. A
     * header with no rows under it is folded into the next table's caption.
     *
     * @param  array<int, array<int, string>>  $rows
     * @return array{text: list<string>, tables: list<array{title: string|null, caption: list<string>, header: list<string>, rows: list<array{source: int, cells: list<string>}>}>}
     */
    public static function split(array $rows): array
    {
        $text = [];
        $tables = [];
        $current = null;
        $pending = [];
        $previous = null;

        foreach ($rows as $number => $cells) {
            $wide = count($cells) >= 2;

            if ($current !== null && $wide && $number === $previous + 1) {
                $current['rows'][] = ['source' => $number, 'cells' => self::row($cells, count($current['header']))];
            } elseif ($wide) {
                if ($current !== null) {
                    $tables[] = $current;
                }
                $current = ['title' => self::title($pending), 'caption' => $pending, 'header' => self::row($cells, max(array_keys($cells)) + 1), 'rows' => []];
                $pending = [];
            } else {
                if ($current !== null) {
                    $tables[] = $current;
                    $current = null;
                }
                $line = Cells::squash((string) array_values($cells)[0]);
                $pending[] = $line;
                $text[] = $line;
            }
            $previous = $number;
        }

        if ($current !== null) {
            $tables[] = $current;
        }

        return ['text' => $text, 'tables' => self::foldEmpty($tables)];
    }

    /**
     * The normalised table: header cleaned, note markers split out, cells typed, and period
     * columns (five or more, and at least half the header) melted into Year/Quarter/Period rows.
     *
     * @param  array{title: string|null, caption: list<string>, header: list<string>, rows: list<array{source: int, cells: list<string>}>}  $raw
     * @param  array<int, string>  $notes  note number => text, from the workbook's notes sheet
     * @return array<string, mixed>
     */
    public static function normalise(string $id, string $sheet, string $title, array $raw, array $notes): array
    {
        $header = [];
        $columnNotes = [];
        foreach ($raw['header'] as $i => $cell) {
            [$name, $refs] = Cells::notes($cell);
            $name = match (true) {
                $name === '' => 'Column ' . ($i + 1),
                in_array($name, ['£', '$', '€'], true) => "Amount ({$name})",
                default => $name,
            };
            $header[$i] = $name;
            if ($refs !== []) {
                $columnNotes[$name] = $refs;
            }
        }

        $periods = [];
        foreach ($raw['header'] as $i => $cell) {
            if ($i > 0 && ($period = Cells::period($cell)) !== null) {
                $periods[$i] = $period;
            }
        }
        $melt = count($periods) >= 5 && count($periods) >= 0.5 * count($header);
        $quarters = array_filter($periods, static fn (array $period): bool => $period[2] !== null) !== [];
        $valueName = self::valueName($raw);
        $dateOrder = Cells::dateOrder(self::allCells($raw['rows']));

        $rows = [];
        $rowNotes = [];
        $units = [];
        foreach ($raw['rows'] as $row) {
            $base = [];
            foreach ($header as $i => $name) {
                if ($melt && isset($periods[$i])) {
                    continue;
                }
                [$label, $refs] = Cells::notes($row['cells'][$i]);
                if ($refs !== []) {
                    $rowNotes[$label] = $refs;
                }
                [$value, $unit] = Cells::parse($label, $dateOrder);
                if ($unit !== null && $unit !== 'date') {
                    $units[$name][$unit] = true;
                }
                $base[$name] = $value;
            }

            if (! $melt) {
                $rows[] = ['source' => "{$sheet}!{$row['source']}", 'values' => $base];

                continue;
            }

            foreach ($periods as $i => [$label, $year, $quarter]) {
                $values = $base + ['Year' => (float) $year];
                if ($quarters) {
                    $values['Quarter'] = $quarter === null ? null : (float) $quarter;
                    $values['Period'] = $label;
                }
                $values[$valueName] = Cells::parse($row['cells'][$i], $dateOrder)[0];
                $rows[] = ['source' => "{$sheet}!{$row['source']}", 'values' => $values];
            }
        }

        if ($melt) {
            [$rows, $valueName] = self::nameValueByIndicator($rows, $valueName);
        }

        $table = [
            'id' => $id,
            'sheet' => $sheet,
            'title' => $title,
            'caption' => $raw['caption'],
            'melted' => $melt ? sprintf('%d period columns (%s to %s) turned into rows', count($periods), reset($periods)[0], end($periods)[0]) : null,
            'joined' => [],
            'rows' => $rows,
            'row_notes' => $rowNotes,
            'column_notes' => $columnNotes,
            'notes' => $notes,
            'units' => array_map('array_keys', $units),
            'columns' => [],
        ];

        return self::profile($table);
    }

    /**
     * Gives each column a type and a role: constant, period, id, measure, category (at most
     * CATEGORY_LIMIT values), label (more), text (long), or alias (another name for a label
     * column, never offered as a filter).
     *
     * @param  array<string, mixed>  $table
     * @return array<string, mixed>
     */
    public static function profile(array $table): array
    {
        $values = [];
        foreach ($table['rows'] as $row) {
            foreach ($row['values'] as $name => $value) {
                $values[(string) $name][] = $value;
            }
        }

        $count = count($table['rows']);
        $table['columns'] = [];
        foreach ($values as $name => $column) {
            $table['columns'][$name] = self::column((string) $name, $column, $count, $table['units'][$name][0] ?? null);
        }

        return self::markAliases($table);
    }

    /**
     * Copies the category columns of a lookup table (a table whose label column is unique) onto
     * tables of the same workbook that share that column, when a JOIN_OVERLAP share of their
     * values match. Only columns with fewer values than keys are copied: one with as many is
     * another name for each key, not a grouping.
     *
     * @param  list<array<string, mixed>>  $tables
     * @return list<array<string, mixed>>
     */
    public static function join(array $tables): array
    {
        foreach ($tables as $l => $lookup) {
            foreach ($lookup['columns'] as $column) {
                $key = (string) $column['name'];
                if (! in_array($column['role'], ['label', 'id', 'alias'], true) || $column['distinct'] !== count($lookup['rows'])) {
                    continue;
                }
                $index = [];
                foreach ($lookup['rows'] as $row) {
                    $index[(string) $row['values'][$key]] = $row['values'];
                }
                $copy = array_values(array_map(static fn (array $c): string => (string) $c['name'], array_filter($lookup['columns'], static fn (array $c): bool => $c['role'] === 'category' && $c['distinct'] < $column['distinct'])));
                foreach ($tables as $t => $table) {
                    if ($t !== $l && $copy !== [] && isset($table['columns'][$key]) && self::overlap($table, $key, $index) >= self::JOIN_OVERLAP) {
                        $tables[$t] = self::copyColumns($table, $key, $index, $copy, $lookup['title']);
                    }
                }
            }
        }

        return $tables;
    }

    /**
     * @param  array<int, string>  $cells
     * @return list<string>
     */
    private static function row(array $cells, int $width): array
    {
        $row = [];
        for ($i = 0; $i < $width; $i++) {
            $row[] = Cells::squash((string) ($cells[$i] ?? ''));
        }

        return $row;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function title(array $lines): ?string
    {
        $title = null;
        foreach ($lines as $line) {
            if (preg_match('/^Table \S+/i', $line) === 1) {
                $title = $line;
            }
        }

        return $title;
    }

    /**
     * @param  list<array{title: string|null, caption: list<string>, header: list<string>, rows: list<array{source: int, cells: list<string>}>}>  $tables
     * @return list<array{title: string|null, caption: list<string>, header: list<string>, rows: list<array{source: int, cells: list<string>}>}>
     */
    private static function foldEmpty(array $tables): array
    {
        $kept = [];
        $carry = [];

        foreach ($tables as $table) {
            if ($table['rows'] === []) {
                array_push($carry, ...$table['caption'], ...[implode(': ', array_filter($table['header']))]);

                continue;
            }
            $table['caption'] = [...$carry, ...$table['caption']];
            $carry = [];
            $kept[] = self::dropEmptyColumns($table);
        }

        return $kept;
    }

    /**
     * @param  array{title: string|null, caption: list<string>, header: list<string>, rows: list<array{source: int, cells: list<string>}>}  $table
     * @return array{title: string|null, caption: list<string>, header: list<string>, rows: list<array{source: int, cells: list<string>}>}
     */
    private static function dropEmptyColumns(array $table): array
    {
        $keep = [];
        foreach ($table['header'] as $i => $name) {
            $used = $name !== '';
            foreach ($table['rows'] as $row) {
                $used = $used || ! in_array($row['cells'][$i], Cells::BLANKS, true);
            }
            if ($used) {
                $keep[] = $i;
            }
        }

        $table['header'] = array_map(static fn (int $i): string => $table['header'][$i], $keep);
        foreach ($table['rows'] as $r => $row) {
            $table['rows'][$r]['cells'] = array_map(static fn (int $i): string => $row['cells'][$i], $keep);
        }

        return $table;
    }

    /**
     * The name for melted values: a unit in brackets from the title or caption, such as "(GWh)",
     * or "Value".
     *
     * @param  array{title: string|null, caption: list<string>}  $raw
     */
    private static function valueName(array $raw): string
    {
        $text = ($raw['title'] ?? '') . ' ' . implode(' ', $raw['caption']);

        return preg_match('/\(([A-Za-z%£$€]{1,10})\)/u', $text, $match) === 1 ? $match[1] : 'Value';
    }

    /**
     * World Bank style tables carry the measure's name in a constant "Indicator Name" column;
     * the melted value column takes that name.
     *
     * @param  list<array{source: string, values: array<string, mixed>}>  $rows
     * @return array{list<array{source: string, values: array<string, mixed>}>, string}
     */
    private static function nameValueByIndicator(array $rows, string $valueName): array
    {
        $indicator = null;
        foreach (array_keys($rows[0]['values'] ?? []) as $name) {
            if (preg_match('/indicator|series/i', (string) $name) === 1 && is_string($rows[0]['values'][$name]) && count(array_unique(array_column(array_column($rows, 'values'), $name))) === 1) {
                $indicator = $rows[0]['values'][$name];
                break;
            }
        }

        if ($indicator === null) {
            return [$rows, $valueName];
        }

        foreach ($rows as $r => $row) {
            $rows[$r]['values'][$indicator] = $row['values'][$valueName];
            unset($rows[$r]['values'][$valueName]);
        }

        return [$rows, $indicator];
    }

    /**
     * @param  list<array{source: int, cells: list<string>}>  $rows
     * @return iterable<string>
     */
    private static function allCells(array $rows): iterable
    {
        foreach ($rows as $row) {
            yield from $row['cells'];
        }
    }

    /**
     * @param  list<float|string|null>  $values
     * @return array<string, mixed>
     */
    private static function column(string $name, array $values, int $count, ?string $unit): array
    {
        $present = array_values(array_filter($values, static fn (mixed $v): bool => $v !== null));
        $distinct = array_count_values(array_map(Number::key(...), $present));
        arsort($distinct);
        $numbers = count(array_filter($present, 'is_float'));
        $dates = count(array_filter($present, static fn (mixed $v): bool => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1));
        $type = match (true) {
            $present !== [] && $numbers >= 0.9 * count($present) => 'number',
            $present !== [] && $dates >= 0.9 * count($present) => 'date',
            default => 'text',
        };
        $average = array_sum(array_map('mb_strlen', array_map('strval', array_keys($distinct)))) / max(1, count($distinct));
        $role = match (true) {
            count($distinct) === 1 && $count > 1 && count($present) === $count => 'constant',
            in_array($name, ['Year', 'Quarter', 'Period'], true), $type === 'date' => 'period',
            count($distinct) > 0.6 * count($present) && preg_match('/\b(no|number|id|code)\b/i', $name) === 1 => 'id',
            $type === 'text' && $average > 80 => 'text',
            $type === 'number' => 'measure',
            count($distinct) <= self::CATEGORY_LIMIT => 'category',
            default => 'label',
        };

        $column = [
            'name' => $name,
            'type' => $type,
            'role' => $role,
            'blank' => $count - count($present),
            'distinct' => count($distinct),
            'top' => array_map('strval', array_slice(array_keys($distinct), 0, 6)),
            'unit' => $unit ?? self::unitFromName($name),
        ];

        if ($type === 'number' || $type === 'date') {
            $column['min'] = min($present);
            $column['max'] = max($present);
        }
        if (in_array($role, ['category', 'period', 'label', 'id'], true)) {
            $column['values'] = array_map('strval', array_keys($distinct));
            if ($role === 'period') {
                sort($column['values'], SORT_NATURAL);
            }
        }

        return $column;
    }

    private static function unitFromName(string $name): ?string
    {
        return match (true) {
            preg_match('/\((GWh|MWh|kWh|tonnes|km|kg|%|£|\$|€)\)/u', $name, $match) === 1 => $match[1],
            stripos($name, 'per cent') !== false => '%',
            default => null,
        };
    }

    /**
     * A label column that maps one to one onto another label column with longer values (a
     * country code onto the country name) is an alias of it.
     *
     * @param  array<string, mixed>  $table
     * @return array<string, mixed>
     */
    private static function markAliases(array $table): array
    {
        $labels = array_filter($table['columns'], static fn (array $c): bool => in_array($c['role'], ['label', 'category', 'id'], true));

        foreach ($labels as $a) {
            foreach ($labels as $b) {
                if ($a['name'] === $b['name'] || $a['distinct'] !== $b['distinct'] || $table['columns'][$b['name']]['role'] === 'alias') {
                    continue;
                }
                $map = [];
                foreach ($table['rows'] as $row) {
                    $map[(string) $row['values'][$a['name']]][(string) $row['values'][$b['name']]] = true;
                }
                $oneToOne = array_filter($map, static fn (array $targets): bool => count($targets) > 1) === [];
                if ($oneToOne && array_sum(array_map('mb_strlen', $a['top'])) < array_sum(array_map('mb_strlen', $b['top']))) {
                    $table['columns'][$a['name']]['role'] = 'alias';
                    $table['columns'][$a['name']]['alias_of'] = $b['name'];
                    break;
                }
            }
        }

        return $table;
    }

    /**
     * @param  array<string, mixed>  $table
     * @param  array<string, array<string, mixed>>  $index
     */
    private static function overlap(array $table, string $key, array $index): float
    {
        $values = array_unique(array_map(static fn (array $row): string => (string) $row['values'][$key], $table['rows']));
        $hits = count(array_filter($values, static fn (string $value): bool => isset($index[$value])));

        return $values === [] ? 0.0 : $hits / count($values);
    }

    /**
     * @param  array<string, mixed>  $table
     * @param  array<string, array<string, mixed>>  $index
     * @param  list<string>  $copy
     * @return array<string, mixed>
     */
    private static function copyColumns(array $table, string $key, array $index, array $copy, string $from): array
    {
        foreach ($table['rows'] as $r => $row) {
            foreach ($copy as $name) {
                $table['rows'][$r]['values'][$name] = $index[(string) $row['values'][$key]][$name] ?? null;
            }
        }
        $table['joined'][] = sprintf('%s joined from "%s" on %s', implode(', ', $copy), $from, $key);

        return self::profile($table);
    }
}
