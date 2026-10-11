<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tabular;

/**
 * How numbers are written: whole numbers plain below 10,000 (so years read as years) and with
 * thousands separators from 10,000, others to at most four decimal places with trailing zeros
 * dropped.
 */
final class Number
{
    public static function format(float|int|string|null $value): string
    {
        if ($value === null) {
            return '(none)';
        }
        if (is_string($value)) {
            return $value;
        }
        $separator = abs((float) $value) >= 10000 ? ',' : '';

        if (floor((float) $value) === (float) $value && abs((float) $value) < 1e15) {
            return number_format((float) $value, 0, '.', $separator);
        }

        return rtrim(rtrim(number_format((float) $value, 4, '.', $separator), '0'), '.');
    }

    /**
     * The string a cell value is matched by in filters and grouping: numbers without separators,
     * so an identifier such as 2000034784 matches as the question writes it.
     */
    public static function key(mixed $value): string
    {
        if (! is_float($value) && ! is_int($value)) {
            return (string) $value;
        }
        if (floor((float) $value) === (float) $value && abs((float) $value) < 1e15) {
            return number_format((float) $value, 0, '.', '');
        }

        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }
}
