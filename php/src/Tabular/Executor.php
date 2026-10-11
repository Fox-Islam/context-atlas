<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tabular;

/**
 * Runs a plan over a table and writes the context an LLM reads: the source, the plan in words,
 * the computed result, the records behind it with their sheet and row, and the notes they cite.
 * The arithmetic happens here, never in a model.
 */
final class Executor
{
    private const int MAX_RECORDS = 60;

    private const int SAMPLE_RECORDS = 40;

    private const array CHANGES = ['change', 'percent'];

    private const int MAX_GROUPS = 60;

    /**
     * @param  array<string, mixed>  $table
     * @param  array{measure: string|null, operation: string, group: string|null, filters: array<string, array<int, mixed>>, drop_blank: list<string>, unmatched?: list<string>, relaxed?: array{drop: string, column: string, matched: int}|null}  $plan
     * @return array{text: string, sources: list<string>}
     */
    public static function run(array $table, array $plan): array
    {
        $lines = [
            sprintf('Source: %s [%s], %d records. Columns: %s', $table['title'], $table['id'], count($table['rows']), self::columnList($table)),
        ];
        $rows = self::select($table, $plan);
        $relaxed = $plan['relaxed'] ?? null;
        $lowest = $plan['operation'] === 'min';
        [$measure, $operation] = self::effective($plan);

        if ($rows === [] && $relaxed !== null) {
            $lines[] = 'Query: ' . self::describe(self::shaped($plan, $operation), $measure);
            $lines[] = 'Matched 0 records.';
            $lines[] = sprintf('No record matches every filter, so this one is left out: %s.', self::constraint($plan, $relaxed['drop'], $relaxed['column']));
            $plan = self::without($plan, $relaxed['drop'], $relaxed['column']);
            $rows = self::select($table, $plan);
        }

        $span = in_array($operation, self::CHANGES, true) ? self::span($table, $plan) : null;
        $noSpan = in_array($operation, self::CHANGES, true) && $span === null;
        if ($noSpan) {
            $operation = 'sum';
        }
        $plan = self::shaped($plan, $operation);
        $unit = $measure !== null ? (string) ($table['columns'][$measure]['unit'] ?? '') : '';

        $lines[] = 'Query: ' . self::describe($plan, $measure);
        if ($noSpan) {
            $lines[] = 'A change needs a span of a period column with a start and an end, and none was chosen, so this is the sum.';
        }
        foreach ($plan['unmatched'] ?? [] as $column) {
            $lines[] = "No {$column} value matched the question, so the records are not filtered by {$column}.";
        }
        $lines[] = sprintf('Matched %d records.', count($rows));
        if ($rows === [] && self::constraints($plan) !== []) {
            $lines[] = self::unmatchedFilters($table, $plan);
        }
        if ($measure !== null && $operation !== 'count' && $rows !== [] && ! self::hasNumber($rows, $measure)) {
            $lines[] = self::noData($table, $plan, $measure, count($rows));
        }
        $shown = $rows;

        if ($span !== null && $measure !== null) {
            array_push($lines, ...self::changes($rows, $plan['group'], $operation, $measure, $unit, $span));
            $shown = $plan['group'] === null ? array_values(array_filter($rows, static fn (array $row): bool => in_array(Number::key($row['values'][$span[0]] ?? null), [$span[1], $span[2]], true))) : [];
        } elseif ($plan['group'] !== null && $operation !== 'list') {
            array_push($lines, ...self::grouped($rows, $plan['group'], $operation, $measure, $unit, $lowest));
            $shown = [];
        } elseif ($operation !== 'list') {
            $result = self::aggregate($operation, $rows, $measure);
            $lines[] = sprintf('Result: %s%s = %s (over %d records%s)', $operation, $measure !== null ? " of {$measure}" : '', self::amount($result['value'], $unit), $result['records'], $result['blank'] > 0 ? ", {$result['blank']} blank skipped" : '');
            $shown = match (true) {
                in_array($operation, ['max', 'min'], true) && count($rows) <= self::MAX_RECORDS => self::sortBy($rows, $measure),
                $result['row'] !== null => [$result['row']],
                default => array_slice(self::sortBy($rows, $measure), 0, 5),
            };
        } elseif ($measure !== null && count($rows) > self::MAX_RECORDS) {
            $shown = self::sortBy($rows, $measure);
        }

        $limit = count($shown) <= self::MAX_RECORDS ? count($shown) : self::SAMPLE_RECORDS;
        if ($shown !== []) {
            $lines[] = self::records($table, array_slice($shown, 0, $limit), count($shown));
        }
        if (count($shown) > $limit) {
            $lines[] = self::rest(array_slice($shown, $limit), $measure, $unit);
        }
        array_push($lines, ...self::notes($table, $shown, $plan));

        return ['text' => implode("\n", $lines), 'sources' => array_values(array_unique(array_map(static fn (array $row): string => $row['source'], array_slice($shown, 0, $limit))))];
    }

    /**
     * The records a plan's filters and blank-row columns select.
     *
     * @param  array<string, mixed>  $table
     * @param  array{filters: array<string, array<int, mixed>>, drop_blank: list<string>}  $plan
     * @return list<array{source: string, values: array<string, mixed>}>
     */
    public static function select(array $table, array $plan): array
    {
        return array_values(array_filter($table['rows'], static fn (array $row): bool => self::matches($row['values'], $plan)));
    }

    /**
     * When a plan with two or more constraints selects no record, the one constraint to leave
     * out. Of those whose removal lets some record through: the one the question names least
     * (lowest score; a constraint missing from the scores scores 1), then the one that lets
     * the fewest records through, then the first in order. Null when the plan selects records,
     * or no single removal helps.
     *
     * @param  array<string, mixed>  $table
     * @param  array{measure: string|null, operation: string, group: string|null, filters: array<string, array<int, mixed>>, drop_blank: list<string>, unmatched?: list<string>, relaxed?: array{drop: string, column: string, matched: int}|null}  $plan
     * @param  array<string, float>  $scores  by filter column
     * @return array{drop: string, column: string, matched: int}|null
     */
    public static function relax(array $table, array $plan, array $scores = []): ?array
    {
        $constraints = self::constraints($plan);
        if (count($constraints) < 2 || self::select($table, $plan) !== []) {
            return null;
        }

        $best = null;
        $bestKey = null;
        foreach ($constraints as [$drop, $column]) {
            $matched = count(self::select($table, self::without($plan, $drop, $column)));
            $key = [$drop === 'filter' ? ($scores[$column] ?? 1.0) : 1.0, $matched];
            if ($matched > 0 && ($bestKey === null || $key < $bestKey)) {
                $best = ['drop' => $drop, 'column' => $column, 'matched' => $matched];
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * What a reader needs for a question about what the data means: the card, every record when
     * there are few, and the notes.
     *
     * @param  array<string, mixed>  $table
     * @return array{text: string, sources: list<string>}
     */
    public static function meaning(array $table): array
    {
        $lines = ["Table: {$table['title']} [{$table['id']}]", Card::text($table, 8)];
        $rows = count($table['rows']) <= 60 ? $table['rows'] : [];

        if ($rows !== []) {
            $lines[] = self::records($table, $rows, count($rows));
        }

        $refs = [];
        foreach ($table['row_notes'] as $label => $numbers) {
            foreach ($numbers as $n) {
                $refs[$n][] = (string) $label;
            }
        }
        foreach ($table['column_notes'] as $column => $numbers) {
            foreach ($numbers as $n) {
                $refs[$n][] = "column {$column}";
            }
        }
        ksort($refs);
        foreach ($refs as $n => $where) {
            if (isset($table['notes'][$n])) {
                $lines[] = "Note {$n} (" . implode(', ', array_unique($where)) . "): {$table['notes'][$n]}";
            }
        }

        return ['text' => implode("\n", $lines), 'sources' => array_values(array_map(static fn (array $row): string => $row['source'], $rows))];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array{filters: array<string, array<int, mixed>>, drop_blank: list<string>}  $plan
     */
    private static function matches(array $values, array $plan): bool
    {
        foreach ($plan['drop_blank'] as $column) {
            if (($values[$column] ?? null) === null) {
                return false;
            }
        }

        foreach ($plan['filters'] as $column => $filter) {
            $value = $values[$column] ?? null;
            $ok = $value !== null && match ($filter[0]) {
                'in' => in_array(Number::key($value), $filter[1], true),
                'range' => self::inRange($value, ...self::ends($filter)),
                'threshold' => is_float($value) && match ($filter[1]) {
                    'above' => $value > $filter[2],
                    'atleast' => $value >= $filter[2],
                    'below' => $value < $filter[2],
                    default => $value <= $filter[2],
                },
                default => true,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(mixed $value, mixed $from, mixed $to): bool
    {
        return ($from === null || self::compare($value, $from) >= 0) && ($to === null || self::compare($value, $to) <= 0);
    }

    /**
     * A range's ends, swapped when the start is after the end.
     *
     * @param  array<int, mixed>  $filter
     * @return array{mixed, mixed}
     */
    private static function ends(array $filter): array
    {
        $from = $filter[1] ?? null;
        $to = $filter[2] ?? null;

        return $from !== null && $to !== null && self::compare($from, $to) > 0 ? [$to, $from] : [$from, $to];
    }

    /**
     * The plan's constraints in order: each filter, then each blank-row column.
     *
     * @param  array{filters: array<string, array<int, mixed>>, drop_blank: list<string>}  $plan
     * @return list<array{string, string}>
     */
    private static function constraints(array $plan): array
    {
        $out = [];
        foreach (array_keys($plan['filters']) as $column) {
            $out[] = ['filter', (string) $column];
        }
        foreach ($plan['drop_blank'] as $column) {
            $out[] = ['blank', $column];
        }

        return $out;
    }

    /**
     * @param  array{measure: string|null, operation: string, group: string|null, filters: array<string, array<int, mixed>>, drop_blank: list<string>, unmatched?: list<string>, relaxed?: array{drop: string, column: string, matched: int}|null}  $plan
     * @return array{measure: string|null, operation: string, group: string|null, filters: array<string, array<int, mixed>>, drop_blank: list<string>, unmatched?: list<string>, relaxed?: array{drop: string, column: string, matched: int}|null}
     */
    private static function without(array $plan, string $drop, string $column): array
    {
        if ($drop === 'filter') {
            unset($plan['filters'][$column]);
        } else {
            $plan['drop_blank'] = array_values(array_filter($plan['drop_blank'], static fn (string $c): bool => $c !== $column));
        }

        return $plan;
    }

    /**
     * @param  array{filters: array<string, array<int, mixed>>, drop_blank: list<string>}  $plan
     */
    private static function constraint(array $plan, string $drop, string $column): string
    {
        return $drop === 'filter' ? self::filterText($column, $plan['filters'][$column]) : "{$column} not blank";
    }

    /**
     * How many records each constraint would let through on its own, so a reader can say that
     * none match instead of abstaining.
     *
     * @param  array<string, mixed>  $table
     * @param  array{filters: array<string, array<int, mixed>>, drop_blank: list<string>}  $plan
     */
    private static function unmatchedFilters(array $table, array $plan): string
    {
        $parts = [];
        foreach (self::constraints($plan) as [$drop, $column]) {
            $alone = ['filters' => $drop === 'filter' ? [$column => $plan['filters'][$column]] : [], 'drop_blank' => $drop === 'blank' ? [$column] : []];
            $parts[] = sprintf('%s (%d records)', self::constraint($plan, $drop, $column), count(self::select($table, $alone)));
        }

        return 'No record matches the filters. Each on its own matches: ' . implode('; ', $parts) . '.';
    }

    /**
     * @param  list<array{source: string, values: array<string, mixed>}>  $rows
     */
    private static function hasNumber(array $rows, string $measure): bool
    {
        foreach ($rows as $row) {
            if (is_float($row['values'][$measure] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every matched record is blank in the measure: states that, and for each period the plan
     * filters on, the periods that do hold values, so a year the source left empty reads as
     * missing instead of as zero.
     *
     * @param  array<string, mixed>  $table
     * @param  array{filters: array<string, array<int, mixed>>}  $plan
     */
    private static function noData(array $table, array $plan, string $measure, int $matched): string
    {
        $line = "{$measure} is blank in all {$matched} matched records.";

        foreach ($plan['filters'] as $column => $filter) {
            $column = (string) $column;
            if ($filter[0] !== 'range' || ($table['columns'][$column]['role'] ?? null) !== 'period') {
                continue;
            }
            $held = [];
            foreach ($table['rows'] as $row) {
                if (is_float($row['values'][$measure] ?? null) && ($row['values'][$column] ?? null) !== null) {
                    $held[Number::key($row['values'][$column])] = true;
                }
            }
            $periods = array_values(array_filter(array_map('strval', $table['columns'][$column]['values'] ?? []), static fn (string $v): bool => isset($held[$v])));
            $line .= $periods === []
                ? " The table holds no {$measure} value for any {$column}."
                : sprintf(' The table holds %s values for %s from %s to %s only.', $measure, $column, $periods[0], end($periods));
        }

        return $line;
    }

    private static function amount(?float $value, string $unit): string
    {
        return $value === null ? 'no data' : Number::format($value) . ($unit !== '' ? " {$unit}" : '');
    }

    /**
     * @param  list<array{source: string, values: array<string, mixed>}>  $rows
     */
    private static function rest(array $rows, ?string $measure, string $unit): string
    {
        $values = $measure === null ? [] : array_values(array_filter(array_map(static fn (array $row): mixed => $row['values'][$measure] ?? null, $rows), 'is_float'));
        if ($values === []) {
            return sprintf('Not shown: %d more records.', count($rows));
        }
        $suffix = $unit !== '' ? " {$unit}" : '';

        return sprintf('Not shown: %d more records, %s from %s to %s%s, sum %s%s.', count($rows), $measure, Number::format(min($values)), Number::format(max($values)), $suffix, Number::format((float) array_sum($values)), $suffix);
    }

    /**
     * A total, average, highest or lowest with no measure chosen is a count of records.
     *
     * @param  array{measure: string|null, operation: string}  $plan
     * @return array{string|null, string}
     */
    private static function effective(array $plan): array
    {
        if ($plan['measure'] === null && in_array($plan['operation'], ['sum', 'average', 'max', 'min', ...self::CHANGES], true)) {
            return [null, 'count'];
        }

        return [$plan['measure'], $plan['operation']];
    }

    /**
     * @param  array{measure: string|null, operation: string, group: string|null, filters: array<string, array<int, mixed>>, drop_blank: list<string>, unmatched?: list<string>, relaxed?: array{drop: string, column: string, matched: int}|null}  $plan
     * @return array{measure: string|null, operation: string, group: string|null, filters: array<string, array<int, mixed>>, drop_blank: list<string>, unmatched?: list<string>, relaxed?: array{drop: string, column: string, matched: int}|null}
     */
    private static function shaped(array $plan, string $operation): array
    {
        $plan['operation'] = $operation;
        $plan['group'] ??= self::implicitGroup($plan, $operation);

        return $plan;
    }

    /**
     * A total, average or count over several named values of one column, with no grouping
     * chosen, is shown per value, so a comparison sees each side.
     *
     * @param  array{filters: array<string, array<int, mixed>>}  $plan
     */
    private static function implicitGroup(array $plan, string $operation): ?string
    {
        if (! in_array($operation, ['sum', 'average', 'count', ...self::CHANGES], true)) {
            return null;
        }

        foreach ($plan['filters'] as $column => $filter) {
            if ($filter[0] === 'in' && count($filter[1]) > 1) {
                return (string) $column;
            }
        }

        return null;
    }

    /**
     * @param  list<array{source: string, values: array<string, mixed>}>  $rows
     * @return list<string>
     */
    private static function grouped(array $rows, string $group, string $operation, ?string $measure, string $unit, bool $lowest): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $value = $row['values'][$group] ?? null;
            $groups[$value === null ? '(blank)' : Number::key($value)][] = $row;
        }

        $results = array_map(static fn (array $members): array => self::aggregate($operation, $members, $measure), $groups);
        uasort($results, static fn (array $a, array $b): int => self::rank($a['value'], $b['value'], $lowest));
        $lines = [sprintf('Result: %s%s by %s (%d groups):', $operation, $measure !== null ? " of {$measure}" : '', $group, count($results))];

        foreach (array_slice($results, 0, self::MAX_GROUPS, true) as $name => $result) {
            $lines[] = sprintf('  %s: %s (%d records%s)', $name, self::amount($result['value'], $unit), $result['records'], $result['blank'] > 0 ? ", {$result['blank']} blank" : '');
        }

        return $lines;
    }

    /**
     * Groups highest first, or lowest first for min; no value last either way.
     */
    private static function rank(?float $a, ?float $b, bool $lowest): int
    {
        if ($a === null || $b === null) {
            return ($a === null) <=> ($b === null);
        }

        return $lowest ? $a <=> $b : $b <=> $a;
    }

    /**
     * The span a change is measured over: the first range filter on a period column with both
     * ends, as [column, start key, end key].
     *
     * @param  array<string, mixed>  $table
     * @param  array{filters: array<string, array<int, mixed>>}  $plan
     * @return array{string, string, string}|null
     */
    private static function span(array $table, array $plan): ?array
    {
        foreach ($plan['filters'] as $column => $filter) {
            [$from, $to] = $filter[0] === 'range' ? self::ends($filter) : [null, null];
            if ($from !== null && $to !== null && ($table['columns'][$column]['role'] ?? null) === 'period') {
                return [(string) $column, Number::key($from), Number::key($to)];
            }
        }

        return null;
    }

    /**
     * The change in the measure from the span's start to its end, overall or per group, highest
     * first. Each end is the sum of the measure's numbers in the records at that period.
     *
     * @param  list<array{source: string, values: array<string, mixed>}>  $rows
     * @param  array{string, string, string}  $span
     * @return list<string>
     */
    private static function changes(array $rows, ?string $group, string $operation, string $measure, string $unit, array $span): array
    {
        [$column, $from, $to] = $span;
        $groups = [];
        foreach ($rows as $row) {
            $value = $group === null ? '' : ($row['values'][$group] ?? null);
            $groups[$value === null ? '(blank)' : Number::key($value)][] = $row;
        }

        $results = [];
        foreach ($groups as $name => $members) {
            $start = self::at($members, $measure, $column, $from);
            $end = self::at($members, $measure, $column, $to);
            $change = $start !== null && $end !== null ? $end - $start : null;
            $value = $operation === 'change' ? $change : ($change !== null && $start != 0.0 ? $change / $start * 100 : null);
            $results[(string) $name] = ['value' => $value, 'start' => $start, 'end' => $end];
        }
        uasort($results, static fn (array $a, array $b): int => ($b['value'] ?? -INF) <=> ($a['value'] ?? -INF));

        $what = sprintf('%s of %s from %s to %s', $operation === 'change' ? 'change' : 'percent change', $measure, $from, $to);
        $figure = static fn (array $r): string => sprintf('%s (%s: %s; %s: %s)', self::amount($r['value'], $operation === 'change' ? $unit : '%'), $from, self::amount($r['start'], $unit), $to, self::amount($r['end'], $unit));
        if ($group === null) {
            return ["Result: {$what} = " . $figure($results[''] ?? ['value' => null, 'start' => null, 'end' => null])];
        }

        $lines = [sprintf('Result: %s by %s (%d groups):', $what, $group, count($results))];
        foreach (array_slice($results, 0, self::MAX_GROUPS, true) as $name => $result) {
            $lines[] = "  {$name}: " . $figure($result);
        }

        return $lines;
    }

    /**
     * @param  list<array{source: string, values: array<string, mixed>}>  $rows
     */
    private static function at(array $rows, string $measure, string $column, string $period): ?float
    {
        $values = [];
        foreach ($rows as $row) {
            if (Number::key($row['values'][$column] ?? null) === $period && is_float($row['values'][$measure] ?? null)) {
                $values[] = $row['values'][$measure];
            }
        }

        return $values === [] ? null : (float) array_sum($values);
    }

    /**
     * A count is of distinct source rows, so a melted table counts countries, not country-years.
     *
     * @param  list<array{source: string, values: array<string, mixed>}>  $rows
     * @return array{value: float|null, records: int, blank: int, row: array{source: string, values: array<string, mixed>}|null}
     */
    private static function aggregate(string $operation, array $rows, ?string $measure): array
    {
        if ($operation === 'count' || $measure === null) {
            return ['value' => (float) count(array_unique(array_column($rows, 'source'))), 'records' => count($rows), 'blank' => 0, 'row' => null];
        }

        $values = [];
        $best = null;
        foreach ($rows as $row) {
            $value = $row['values'][$measure] ?? null;
            if (! is_float($value)) {
                continue;
            }
            $values[] = $value;
            if ($best === null || ($operation === 'max' && $value > $best['values'][$measure]) || ($operation === 'min' && $value < $best['values'][$measure])) {
                $best = $row;
            }
        }

        $value = match ($operation) {
            'sum' => $values === [] ? null : (float) array_sum($values),
            'average' => $values === [] ? null : array_sum($values) / count($values),
            'max' => $values === [] ? null : max($values),
            'min' => $values === [] ? null : min($values),
            default => null,
        };

        return ['value' => $value, 'records' => count($values), 'blank' => count($rows) - count($values), 'row' => in_array($operation, ['max', 'min'], true) ? $best : null];
    }

    private static function compare(mixed $a, mixed $b): int
    {
        return is_numeric($a) && is_numeric($b) ? (float) $a <=> (float) $b : strcmp(Number::key($a), (string) $b);
    }

    /**
     * @param  list<array{source: string, values: array<string, mixed>}>  $rows
     * @return list<array{source: string, values: array<string, mixed>}>
     */
    private static function sortBy(array $rows, ?string $measure): array
    {
        if ($measure !== null) {
            usort($rows, static fn (array $a, array $b): int => ($b['values'][$measure] ?? -INF) <=> ($a['values'][$measure] ?? -INF));
        }

        return $rows;
    }

    /**
     * @param  array{measure: string|null, operation: string, group: string|null, filters: array<string, array<int, mixed>>, drop_blank: list<string>}  $plan
     */
    private static function describe(array $plan, ?string $measure): string
    {
        $parts = [($plan['operation'] === 'percent' ? 'percent change' : $plan['operation']) . ($measure !== null ? " of {$measure}" : '')];

        foreach ($plan['filters'] as $column => $filter) {
            $parts[] = self::filterText((string) $column, $filter);
        }
        foreach ($plan['drop_blank'] as $column) {
            $parts[] = "{$column} not blank";
        }
        if ($plan['group'] !== null) {
            $parts[] = "grouped by {$plan['group']}";
        }

        return implode('; ', $parts);
    }

    /**
     * @param  array<int, mixed>  $filter
     */
    private static function filterText(string $column, array $filter): string
    {
        return match ($filter[0]) {
            'in' => "{$column} is " . implode(' or ', array_map(static fn (string $v): string => "\"{$v}\"", $filter[1])),
            'range' => "{$column} from " . (self::ends($filter)[0] ?? 'start') . ' to ' . (self::ends($filter)[1] ?? 'end'),
            default => "{$column} {$filter[1]} " . Number::format($filter[2]),
        };
    }

    /**
     * @param  array<string, mixed>  $table
     */
    private static function columnList(array $table): string
    {
        $names = [];
        foreach ($table['columns'] as $column) {
            if ($column['role'] !== 'constant') {
                $names[] = $column['name'] . ($column['unit'] !== null && ! str_contains($column['name'], "({$column['unit']})") ? " ({$column['unit']})" : '');
            }
        }

        return implode(', ', $names);
    }

    /**
     * @param  array<string, mixed>  $table
     * @param  list<array{source: string, values: array<string, mixed>}>  $rows
     */
    private static function records(array $table, array $rows, int $total): string
    {
        $columns = array_values(array_map(static fn (array $c): string => (string) $c['name'], array_filter($table['columns'], static fn (array $c): bool => $c['role'] !== 'constant')));
        $lines = [sprintf('Records (%d of %d):', count($rows), $total), 'source | ' . implode(' | ', $columns)];

        foreach ($rows as $row) {
            $lines[] = $row['source'] . ' | ' . implode(' | ', array_map(static fn (string $c): string => ($row['values'][$c] ?? null) === null ? '' : Number::key($row['values'][$c]), $columns));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $table
     * @param  list<array{source: string, values: array<string, mixed>}>  $rows
     * @param  array{filters: array<string, array<int, mixed>>}  $plan
     * @return list<string>
     */
    private static function notes(array $table, array $rows, array $plan): array
    {
        $refs = [];
        foreach ($rows as $row) {
            foreach ($row['values'] as $value) {
                foreach ($table['row_notes'][Number::key($value)] ?? [] as $n) {
                    $refs[$n] = true;
                }
            }
        }
        foreach ($plan['filters'] as $column => $filter) {
            foreach ($table['column_notes'][$column] ?? [] as $n) {
                $refs[$n] = true;
            }
            foreach ($filter[0] === 'in' ? $filter[1] : [] as $value) {
                foreach ($table['row_notes'][$value] ?? [] as $n) {
                    $refs[$n] = true;
                }
            }
        }
        ksort($refs);

        $lines = [];
        foreach (array_keys($refs) as $n) {
            if (isset($table['notes'][$n])) {
                $lines[] = "Note {$n}: {$table['notes'][$n]}";
            }
        }

        return $lines;
    }
}
