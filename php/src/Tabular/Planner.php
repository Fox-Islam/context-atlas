<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tabular;

use Phox\ContextAtlas\Jev\JevCaller;
use Phox\ContextAtlas\Text;
use Phox\TypeSafe\Questions\Choice;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Requests\SystemOne;
use Phox\TypeSafe\Responses\SystemOneResponse;

/**
 * Turns a question into a typed query plan over tables, in up to three Jev requests:
 *
 * 1. route: which table, whether the question asks what the data means, and whether the tables
 *    hold what it needs at all;
 * 2. columns: the measure, the operation, the grouping, which columns the question filters on,
 *    and which blank-category rows (aggregates such as "World") to leave out;
 * 3. values: the filter values, chosen from the column's real values.
 *
 * Every option is built from the schema, so Jev picks and never writes; Executor computes.
 */
final readonly class Planner
{
    public const string QUESTION = 'the question being asked';

    public const array OPERATIONS = [
        'list' => 'show the matching records themselves (one value, a few records, or the records that meet a condition)',
        'sum' => 'add the values up into a total',
        'average' => 'the average (mean) of the values',
        'count' => 'count how many records there are',
        'max' => 'the single highest value, and the record it belongs to',
        'min' => 'the single lowest value, and the record it belongs to',
    ];

    public const array CHANGES = [
        'change' => 'how much the value rose or fell from the start to the end of a span of periods (the difference), or which rose or fell the most',
        'percent' => 'by what percentage the value rose or fell from the start to the end of a span of periods, or which rose or fell the most in percentage terms',
    ];

    private const array NUMBER_WORDS = ['a' => 1, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'twelve' => 12, 'twenty' => 20, 'fifty' => 50, 'hundred' => 100];

    private const int SHORTLIST = 12;

    private const array STOP_WORDS = ['the', 'and', 'for', 'what', 'was', 'were', 'how', 'much', 'many', 'did', 'does', 'which', 'with', 'from', 'that', 'this', 'each', 'all', 'any', 'are', 'who', 'its', 'their', 'than', 'more', 'less', 'over', 'under', 'between', 'total', 'show', 'every', 'list', 'compare'];

    public function __construct(
        private JevCaller $jev,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $tables
     * @return array{table: array<string, mixed>|null, meaning: bool, plan: array{measure: string|null, operation: string, group: string|null, filters: array<string, array<int, mixed>>, drop_blank: list<string>, unmatched: list<string>, relaxed: array{drop: string, column: string, matched: int}|null}|null, trace: array<string, mixed>}|null null when Jev did not answer
     */
    public function plan(string $question, array $tables): ?array
    {
        $route = $this->route($question, $tables);

        if ($route === null) {
            return null;
        }
        if ($route['table'] === null || $route['meaning']) {
            return $route + ['plan' => null];
        }

        $columns = $this->columns($route['table'], $question);
        if ($columns === null) {
            return null;
        }

        $values = $this->values($route['table'], $question, $columns['filter_columns']);
        if ($values === null) {
            return null;
        }

        [$filters, $unmatched] = $values;
        $plan = ['measure' => $columns['measure'], 'operation' => $columns['operation'], 'group' => $columns['group'], 'filters' => $filters, 'drop_blank' => $columns['drop_blank'], 'unmatched' => $unmatched, 'relaxed' => null];
        $plan['relaxed'] = Executor::relax($route['table'], $plan, self::named($question, $filters));

        return ['table' => $route['table'], 'meaning' => false, 'plan' => $plan, 'trace' => $route['trace'] + ['filters' => (object) $filters] + $plan];
    }

    /**
     * @param  list<array<string, mixed>>  $tables
     * @return array{table: array<string, mixed>|null, meaning: bool, trace: array<string, mixed>}|null
     */
    private function route(string $question, array $tables): ?array
    {
        $labels = [];
        foreach ($tables as $i => $table) {
            $labels['t' . ($i + 1)] = $table;
        }

        $response = $this->jev->send(function (SystemOne $request) use ($labels, $question): SystemOne {
            $request->state([self::QUESTION => $question, 'the tables in the workbook' => array_map(fn (array $t): array => self::wire(self::withHints(Card::of($t, 6), $t, $question)), $labels)]);
            $request->ask('table', Choice::between(array_map(static fn (array $t): string => "{$t['title']} (sheet {$t['sheet']})", $labels) + ['none' => 'none of the tables holds the data the question needs'])
                ->instructions('Which table holds the data needed to answer the question?'));
            $request->ask('meaning', Noul::ask('Is the question about what the data means or covers (what a column, label or term means, what is recorded, the unit, the period covered), rather than asking for values from it?')
                ->yes('the question asks what something means, what is recorded, the unit or the coverage')
                ->no('the question asks for one or more values, records, totals, counts or comparisons'));
            $request->ask('answerable', Noul::ask('Do the tables hold the data needed to answer the question?')
                ->yes('the measure, and the things, places and periods the question names, are all within what the tables record')
                ->no('the question needs a measure, period, place or detail that none of the tables records'));

            return $request;
        });

        if (! $response instanceof SystemOneResponse) {
            return null;
        }

        $pick = JevCaller::choice($response, 'table') ?? 'none';
        $answerable = JevCaller::noul($response, 'answerable') ?? 0.0;
        $meaning = JevCaller::noul($response, 'meaning') ?? 0.0;
        $table = $pick !== 'none' && $answerable >= 0.5 ? ($labels[$pick] ?? null) : null;
        $trace = ['table' => $table['id'] ?? null, 'answerable' => $answerable, 'meaning' => $meaning];

        return ['table' => $table, 'meaning' => $table !== null && $meaning >= 0.5, 'trace' => $trace];
    }

    /**
     * @param  array<string, mixed>  $table
     * @return array{measure: string|null, operation: string, group: string|null, filter_columns: list<string>, drop_blank: list<string>}|null
     */
    private function columns(array $table, string $question): ?array
    {
        $measures = self::labelled(array_filter($table['columns'], static fn (array $c): bool => $c['role'] === 'measure'), 'm');
        $groups = self::labelled(array_filter($table['columns'], static fn (array $c): bool => in_array($c['role'], ['category', 'period', 'label'], true)), 'g');
        $filterable = array_values(array_filter($table['columns'], static fn (array $c): bool => ! in_array($c['role'], ['constant', 'text', 'alias'], true)));
        $blanks = array_values(array_filter($table['columns'], static fn (array $c): bool => $c['role'] === 'category' && $c['blank'] > 0));
        $periods = array_filter($table['columns'], static fn (array $c): bool => $c['role'] === 'period') !== [];

        $response = $this->jev->send(function (SystemOne $request) use ($table, $question, $measures, $groups, $filterable, $blanks, $periods): SystemOne {
            $request->state([self::QUESTION => $question, 'the table' => self::wire(Card::of($table, 8))]);
            if ($measures !== []) {
                $request->ask('measure', Choice::between(array_map(static fn (array $c): string => $c['name'] . ($c['unit'] !== null ? " ({$c['unit']})" : ''), $measures) + ['none' => 'no number column is needed: the question counts or lists records'])
                    ->instructions('Which number column does the answer come from?'));
            }
            $request->ask('operation', Choice::between(self::OPERATIONS + ($measures !== [] && $periods ? self::CHANGES : []))->instructions('What does the answer need done with the matching records?'));
            $request->ask('group', Choice::between(['none' => 'one answer overall, or the records themselves'] + array_map(static fn (array $c): string => "a separate figure for each {$c['name']}", $groups))
                ->instructions('Does the question want a separate figure for each value of a column (for example each organisation, each region or each year)? Pick that column, or none.'));
            if ($groups !== []) {
                $request->ask('rank', Choice::between(['none' => 'no: it does not ask which one comes first or last'] + array_map(static fn (array $c): string => "which {$c['name']} comes first or last", $groups))
                    ->instructions('Does the question ask which value of a column comes first or last (has the most or fewest records, the highest or lowest total, or rose or fell the most)? Pick that column, or none.'));
            }
            foreach ($filterable as $i => $column) {
                $what = $column['role'] === 'measure' ? 'a threshold on' : 'particular values or a range of';
                $request->ask("f{$i}", Noul::ask("Does the question limit which records count by `{$column['name']}`?")
                    ->yes("the question names {$what} `{$column['name']}` (directly or in other words)")
                    ->no("the question does not restrict `{$column['name']}`"));
            }
            foreach ($blanks as $i => $column) {
                $request->ask("b{$i}", Noul::ask("Should the records with no `{$column['name']}` be left out? They are: " . implode('; ', self::blankExamples($table, $column['name'])))
                    ->yes('the question is about a kind of thing those records are not (for example individual countries, not groups of countries)')
                    ->no('those records are among what the question asks about, or it makes no difference'));
            }

            return $request;
        });

        if (! $response instanceof SystemOneResponse) {
            return null;
        }

        $measure = JevCaller::choice($response, 'measure');
        $operation = JevCaller::choice($response, 'operation') ?? 'list';
        $group = JevCaller::choice($response, 'group');
        if (($group ?? 'none') === 'none' && self::ranks($operation, $measures[$measure ?? ''] ?? null)) {
            $group = JevCaller::choice($response, 'rank');
        }

        return [
            'measure' => $measures[$measure ?? '']['name'] ?? null,
            'operation' => $operation,
            'group' => $groups[$group ?? '']['name'] ?? null,
            'filter_columns' => array_values(array_map(static fn (array $c): string => $c['name'], array_filter($filterable, static fn (array $c, int $i): bool => (JevCaller::noul($response, "f{$i}") ?? 0.0) >= 0.5, ARRAY_FILTER_USE_BOTH))),
            'drop_blank' => array_values(array_map(static fn (array $c): string => $c['name'], array_filter($blanks, static fn (array $c, int $i): bool => (JevCaller::noul($response, "b{$i}") ?? 0.0) >= 0.5, ARRAY_FILTER_USE_BOTH))),
        ];
    }

    /**
     * @param  array<string, mixed>  $table
     * @param  list<string>  $columns
     * @return array{array<string, array{0: string, 1: mixed, 2?: mixed}>, list<string>}|null the filters, and the value columns where no offered value was chosen
     */
    private function values(array $table, string $question, array $columns): ?array
    {
        $asks = [];
        foreach ($columns as $name) {
            $ask = $this->ask($table['columns'][$name], $question);
            if ($ask !== null) {
                $asks[$name] = $ask;
            }
        }

        if ($asks === []) {
            return [[], []];
        }

        $names = array_map('strval', array_keys($asks));
        $response = $this->jev->send(function (SystemOne $request) use ($asks, $names, $question, $table): SystemOne {
            $request->state([self::QUESTION => $question, 'the table' => ['title' => $table['title'], 'columns' => array_values(array_map(static fn (array $c): string => (string) $c['name'], $table['columns']))], 'the values to choose from' => (object) array_map(static fn (array $a): array => $a['options'], $asks)]);
            foreach ($names as $c => $name) {
                self::askValues($request, "c{$c}", $name, $asks[$name]);
            }

            return $request;
        });

        if (! $response instanceof SystemOneResponse) {
            return null;
        }

        $filters = [];
        $unmatched = [];
        foreach ($names as $c => $name) {
            $filter = self::filter($response, "c{$c}", $asks[$name]);
            if ($filter !== null) {
                $filters[$name] = $filter;
            } elseif ($asks[$name]['kind'] === 'values') {
                $unmatched[] = $name;
            }
        }

        return [$filters, $unmatched];
    }

    /**
     * @param  array<string, mixed>  $column
     * @return array{kind: string, options: list<string>|array<string, string>, numbers?: list<array{float, string}>}|null
     */
    private function ask(array $column, string $question): ?array
    {
        if ($column['role'] === 'period') {
            return ['kind' => 'range', 'options' => array_values(array_map('strval', $column['values'] ?? []))];
        }

        if ($column['role'] === 'measure') {
            $numbers = self::numbers($question);
            $options = [];
            foreach ($numbers as $i => [$value, $written]) {
                $options["n{$i}"] = "{$written} (= " . Number::format($value) . ')';
            }

            return $numbers === [] ? null : ['kind' => 'threshold', 'options' => $options, 'numbers' => $numbers];
        }

        $values = $column['role'] === 'category' ? $column['values'] : self::shortlist($question, $column['values'] ?? []);
        $options = [];
        foreach (array_values($values) as $i => $value) {
            $options["v{$i}"] = (string) $value;
        }

        return ['kind' => 'values', 'options' => $options];
    }

    /**
     * @param  array{kind: string, options: list<string>|array<string, string>}  $ask
     */
    private static function askValues(SystemOne $request, string $key, string $name, array $ask): void
    {
        if ($ask['kind'] === 'range') {
            $values = array_values($ask['options']);
            $request->ask("{$key}_kind", Choice::between(['one' => "a single `{$name}`", 'span' => "a span of `{$name}` values, from one to another", 'all' => "every `{$name}`, no limit"])->instructions("How much of `{$name}` does the question cover?"));
            $request->ask("{$key}_one", Choice::between($values)->instructions("If the question covers a single `{$name}`, which one?"));
            $request->ask("{$key}_from", Choice::between($values)->instructions("If the question covers a span of `{$name}`, where does the span start?"));
            $request->ask("{$key}_to", Choice::between($values)->instructions("If the question covers a span of `{$name}`, where does the span end?"));

            return;
        }

        if ($ask['kind'] === 'threshold') {
            $request->ask("{$key}_cmp", Choice::between(['above' => 'more than', 'atleast' => 'at least', 'below' => 'less than', 'atmost' => 'at most', 'none' => 'no threshold'])->instructions("What kind of limit does the question put on `{$name}`?"));
            $request->ask("{$key}_num", Choice::between($ask['options'])->instructions("Which number in the question is the limit on `{$name}`?"));

            return;
        }

        foreach ($ask['options'] as $label => $value) {
            $request->ask("{$key}_{$label}", Noul::ask("Is `{$value}` one of the `{$name}` values the question is about?")
                ->yes("the question names `{$value}`, or the same thing in other words or as an abbreviation")
                ->no("the question is about some other `{$name}`, or does not name one"));
        }
    }

    /**
     * @param  array{kind: string, options: list<string>|array<string, string>, numbers?: list<array{float, string}>}  $ask
     * @return array{0: string, 1: mixed, 2?: mixed}|null
     */
    private static function filter(SystemOneResponse $response, string $key, array $ask): ?array
    {
        if ($ask['kind'] === 'range') {
            $kind = JevCaller::choice($response, "{$key}_kind") ?? 'all';
            $one = JevCaller::choice($response, "{$key}_one");
            $from = JevCaller::choice($response, "{$key}_from");
            $to = JevCaller::choice($response, "{$key}_to");

            return match (true) {
                $kind === 'one' && $one !== null => ['range', $one, $one],
                $kind === 'span' && ($from !== null || $to !== null) => ['range', $from, $to],
                default => null,
            };
        }

        if ($ask['kind'] === 'threshold') {
            $comparison = JevCaller::choice($response, "{$key}_cmp") ?? 'none';
            $number = $ask['numbers'][(int) substr((string) JevCaller::choice($response, "{$key}_num"), 1)] ?? null;

            return $comparison !== 'none' && $number !== null && JevCaller::choice($response, "{$key}_num") !== null ? ['threshold', $comparison, $number[0]] : null;
        }

        $chosen = [];
        foreach ($ask['options'] as $label => $value) {
            if ((JevCaller::noul($response, "{$key}_{$label}") ?? 0.0) >= 0.5) {
                $chosen[] = $value;
            }
        }

        return $chosen === [] ? null : ['in', $chosen];
    }

    /**
     * Adds to each label and id column the values that share a word with the question, so Jev
     * sees that a named supplier or country is in the table though the card shows few examples.
     *
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $table
     * @return array<string, mixed>
     */
    private static function withHints(array $card, array $table, string $question): array
    {
        $words = self::words($question);

        foreach ($table['columns'] as $column) {
            if (! in_array($column['role'], ['label', 'id'], true)) {
                continue;
            }
            $hits = [];
            foreach ($column['values'] ?? [] as $value) {
                $overlap = self::overlap($words, self::words($value));
                if ($overlap > 0) {
                    $hits[$value] = $overlap;
                }
            }
            if ($hits !== []) {
                arsort($hits);
                $card['columns'][$column['name']] .= '; values sharing a word with the question: ' . implode('; ', array_map(static fn (string|int $v): string => mb_substr((string) $v, 0, 60), array_slice(array_keys($hits), 0, 5)));
            }
        }

        return $card;
    }

    /**
     * A card as sent: its columns as an object, so one named "0" is not written as a list, in
     * the card's own key order.
     *
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    private static function wire(array $card): array
    {
        $card['columns'] = (object) $card['columns'];

        return $card;
    }

    /**
     * The values of a large column most worth offering. Each question word a value shares
     * scores 1 / the number of the column's values sharing that word, so a rare name
     * ("Blackpool") outranks a word half the column has ("council"); highest score first,
     * column order among equals.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function shortlist(string $question, array $values): array
    {
        $words = self::words($question);
        $valueWords = array_map(static fn (string|int|float $value): array => self::words((string) $value), $values);
        $hits = [];
        foreach ($words as $w => $word) {
            foreach ($valueWords as $v => $candidate) {
                if (self::overlap([$word], $candidate) > 0) {
                    $hits[$w][] = $v;
                }
            }
        }

        $scores = array_fill(0, count($values), 0.0);
        foreach ($hits as $matched) {
            foreach ($matched as $v) {
                $scores[$v] += 1 / count($matched);
            }
        }
        arsort($scores);

        return array_map(static fn (int $v): string => (string) $values[$v], array_slice(array_keys($scores), 0, self::SHORTLIST));
    }

    /**
     * Two words match when equal, or when both have four letters or more and one starts with
     * the other ("councils" and "council").
     *
     * @param  list<string>  $words
     * @param  list<string>  $candidate
     */
    private static function overlap(array $words, array $candidate): int
    {
        $hits = 0;

        foreach ($words as $word) {
            foreach ($candidate as $other) {
                if ($word === $other || (mb_strlen($word) >= 4 && mb_strlen($other) >= 4 && (str_starts_with($other, $word) || str_starts_with($word, $other)))) {
                    $hits++;
                    break;
                }
            }
        }

        return $hits;
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $match);

        return array_values(array_filter($match[0], static fn (string $w): bool => mb_strlen($w) >= 3 && ! in_array($w, self::STOP_WORDS, true)));
    }

    /**
     * Numbers written in the question, with million, billion, thousand, bn, m and k expanded,
     * and "zero".
     *
     * @return list<array{float, string}>
     */
    public static function numbers(string $question): array
    {
        preg_match_all('/(?:[£$€]\s?)?(\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?)\s*(million|billion|thousand|bn|m|k)?\b/iu', $question, $matches, PREG_SET_ORDER);
        $out = preg_match('/\bzero\b/i', $question) === 1 ? [[0.0, 'zero']] : [];

        foreach ($matches as $match) {
            $out[] = [(float) str_replace(',', '', $match[1]) * self::scale($match[2] ?? ''), Text::trim($match[0])];
        }

        preg_match_all('/\\b(' . implode('|', array_keys(self::NUMBER_WORDS)) . ')\\s+(hundred|thousand|million|billion)\\b/iu', $question, $words, PREG_SET_ORDER);
        foreach ($words as $match) {
            $out[] = [self::NUMBER_WORDS[mb_strtolower($match[1])] * self::scale($match[2]), $match[0]];
        }

        return $out;
    }

    private static function scale(string $word): float
    {
        return match (mb_strtolower($word)) {
            'million', 'm' => 1e6,
            'billion', 'bn' => 1e9,
            'thousand', 'k' => 1e3,
            'hundred' => 1e2,
            default => 1.0,
        };
    }

    /**
     * Whether a ranked column becomes the grouping: for totals, averages, counts and changes,
     * and for a highest or lowest with no measure (most records); a highest or lowest of a
     * measure keeps its single record.
     *
     * @param  array<string, mixed>|null  $measure
     */
    private static function ranks(string $operation, ?array $measure): bool
    {
        return in_array($operation, ['sum', 'average', 'count', 'change', 'percent'], true) || (in_array($operation, ['max', 'min'], true) && $measure === null);
    }

    /**
     * How fully the question names each value filter: of its chosen values, the highest share
     * of a value's words that match a question word.
     *
     * @param  array<string, array<int, mixed>>  $filters
     * @return array<string, float>
     */
    private static function named(string $question, array $filters): array
    {
        $words = self::words($question);
        $scores = [];
        foreach ($filters as $column => $filter) {
            if ($filter[0] !== 'in') {
                continue;
            }
            $scores[(string) $column] = 0.0;
            foreach ($filter[1] as $value) {
                $own = self::words((string) $value);
                if ($own !== []) {
                    $scores[(string) $column] = max($scores[(string) $column], count(array_filter($own, static fn (string $w): bool => self::overlap([$w], $words) > 0)) / count($own));
                }
            }
        }

        return $scores;
    }

    /**
     * @param  array<string, array<string, mixed>>  $columns
     * @return array<string, array<string, mixed>>
     */
    private static function labelled(array $columns, string $prefix): array
    {
        $out = [];
        foreach (array_values($columns) as $i => $column) {
            $out[$prefix . ($i + 1)] = $column;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $table
     * @return list<string>
     */
    private static function blankExamples(array $table, string $name): array
    {
        $label = null;
        foreach ($table['columns'] as $column) {
            if ($column['role'] === 'label' || ($column['role'] === 'category' && $column['blank'] === 0)) {
                $label = $column['name'];
                break;
            }
        }

        $seen = [];
        foreach ($table['rows'] as $row) {
            if ($label !== null && $row['values'][$name] === null) {
                $seen[Number::key($row['values'][$label])] = true;
            }
            if (count($seen) >= 12) {
                break;
            }
        }

        return array_map('strval', array_keys($seen));
    }
}
