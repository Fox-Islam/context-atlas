<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tabular;

/**
 * A table described for Jev and for the reader: title, caption, record count and each column's
 * role, unit and values. Every category value is listed, because a card that shows only some
 * leads Jev to judge the others absent.
 */
final class Card
{
    /**
     * @param  array<string, mixed>  $table
     * @return array<string, mixed>
     */
    public static function of(array $table, int $examples): array
    {
        $columns = [];

        foreach ($table['columns'] as $column) {
            $description = match ($column['role']) {
                'measure' => sprintf('number%s, %s to %s', $column['unit'] !== null ? " ({$column['unit']})" : '', Number::format($column['min'] ?? null), Number::format($column['max'] ?? null)),
                'period' => sprintf('%s, %d values from %s to %s', $column['type'] === 'date' ? 'date' : 'period', $column['distinct'], $column['values'][0] ?? '', end($column['values']) ?: ''),
                'constant' => 'same in every record: ' . ($column['top'][0] ?? ''),
                'alias' => sprintf('another name for each %s, e.g. %s', $column['alias_of'], implode('; ', array_slice($column['top'], 0, 3))),
                'category' => sprintf('one of %d values: %s', $column['distinct'], implode('; ', $column['values'])),
                default => sprintf('%s, %d distinct, e.g. %s', $column['role'] === 'id' ? 'identifier' : 'text', $column['distinct'], implode('; ', array_map(static fn (string $v): string => mb_substr($v, 0, 60), array_slice($column['top'], 0, $examples)))),
            };
            if ($column['blank'] > 0 && $column['role'] !== 'constant') {
                $description .= "; blank in {$column['blank']} records";
            }
            $columns[$column['name']] = $description;
        }

        $card = ['title' => $table['title'], 'sheet' => $table['sheet'], 'records' => count($table['rows']), 'columns' => $columns];
        if ($table['caption'] !== []) {
            $card['about'] = implode(' ', array_slice($table['caption'], 0, 4));
        }
        if ($table['melted'] !== null) {
            $card['layout'] = $table['melted'];
        }
        if ($table['joined'] !== []) {
            $card['joined'] = implode('; ', $table['joined']);
        }

        return $card;
    }

    /**
     * The card as plain text, for embedding and for meaning questions.
     *
     * @param  array<string, mixed>  $table
     */
    public static function text(array $table, int $examples): string
    {
        $lines = [];

        foreach (self::of($table, $examples) as $key => $value) {
            $lines[] = is_array($value)
                ? "{$key}:\n" . implode("\n", array_map(static fn (string|int $name, string $d): string => "  - {$name}: {$d}", array_keys($value), $value))
                : "{$key}: {$value}";
        }

        return implode("\n", $lines);
    }
}
