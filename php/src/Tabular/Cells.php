<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tabular;

/**
 * Turns cell text into values: blanks and placeholders into null, currency, percentages and
 * thousands separators into numbers, dates into ISO form. Without this a money column exported
 * as "£487,671,000.00" is text and nothing can be summed.
 */
final class Cells
{
    public const array BLANKS = ['', '#', '[x]', '[c]', '[z]', '[u]', '-', '–', '..', 'n/a', 'N/A', 'NA', ':'];

    private const string DATE = '#^(\d{1,2})/(\d{1,2})/(\d{4})$#';

    /**
     * @return array{float|string|null, string|null} the value, and a unit the cell carried
     */
    public static function parse(string $raw, string $dateOrder): array
    {
        $text = trim($raw);

        if (in_array($text, self::BLANKS, true)) {
            return [null, null];
        }

        $unit = null;
        if (preg_match('/^(-?)\s*([£$€])\s*(.*)$/u', $text, $match) === 1) {
            $unit = $match[2];
            $text = $match[1] . $match[3];
        }
        if (str_ends_with($text, '%')) {
            $unit = '%';
            $text = rtrim($text, '% ');
        }

        $number = str_replace([',', ' '], '', $text);
        if (preg_match('/^-?\d+(\.\d+)?([eE][-+]?\d+)?$/', $number) === 1) {
            return [(float) $number, $unit];
        }

        if (preg_match(self::DATE, $text, $match) === 1) {
            [$day, $month] = $dateOrder === 'mdy' ? [$match[2], $match[1]] : [$match[1], $match[2]];

            return [sprintf('%s-%02d-%02d', $match[3], (int) $month, (int) $day), 'date'];
        }

        return [$raw, null];
    }

    /**
     * Day-first unless the cells prove otherwise: a first field above 12 is a day, a second
     * field above 12 means the first is the month.
     *
     * @param  iterable<string>  $cells
     */
    public static function dateOrder(iterable $cells): string
    {
        foreach ($cells as $cell) {
            if (preg_match(self::DATE, trim($cell), $match) !== 1) {
                continue;
            }
            if ((int) $match[1] > 12) {
                return 'dmy';
            }
            if ((int) $match[2] > 12) {
                return 'mdy';
            }
        }

        return 'dmy';
    }

    /**
     * Splits "[note 3]" markers out of a label.
     *
     * @return array{string, list<int>} the label without markers, and the note numbers
     */
    public static function notes(string $text): array
    {
        preg_match_all('/\[note (\d+)\]/i', $text, $match);

        return [self::squash((string) preg_replace('/\[note \d+\]|\[provisional\]|\[revised\]/i', '', $text)), array_map('intval', $match[1])];
    }

    public static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return array{string, int|null, int|null}|null label, year and quarter of a period header
     */
    public static function period(string $header): ?array
    {
        [$label] = self::notes($header);

        if (preg_match('/^(\d{4})$/', $label, $match) === 1) {
            return [$label, (int) $match[1], null];
        }
        if (preg_match('/^(\d{4}) (1st|2nd|3rd|4th) quarter$/i', $label, $match) === 1) {
            return [$label, (int) $match[1], (int) $match[2][0]];
        }
        if (preg_match('/^(\d{4}) ?Q([1-4])$/i', $label, $match) === 1) {
            return [$label, (int) $match[1], (int) $match[2]];
        }

        return null;
    }
}
