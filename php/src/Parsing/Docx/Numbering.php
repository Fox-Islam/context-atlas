<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing\Docx;

use DOMElement;

/**
 * List markers from word/numbering.xml: each `w:num` points to an abstract numbering whose levels
 * give a format, a start value and a marker template (`%1.`); `w:lvlOverride` replaces a level or
 * its start. Counters are kept per numId. The rules are stated in spec/formats/docx.md.
 */
final class Numbering
{
    /** @var array<string, array{abstract: string, levels: array<int, array{start: ?int, format: ?string, text: ?string}>, starts: array<int, int>}> */
    private array $nums = [];

    /** @var array<string, array<int, array{start: ?int, format: ?string, text: ?string}>> */
    private array $abstracts = [];

    /** @var array<string, array<int, int>> */
    private array $counters = [];

    public function __construct(?DOMElement $root)
    {
        foreach (($root === null ? [] : $root->childNodes) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if (Package::isW($node, 'abstractNum')) {
                $id = Package::attribute($node, 'abstractNumId') ?? '';
                $this->abstracts[$id] ??= self::levels($node);
            } elseif (Package::isW($node, 'num')) {
                $id = Package::attribute($node, 'numId') ?? '';
                $this->nums[$id] ??= self::num($node);
            }
        }
    }

    /**
     * Whether a paragraph with this numId is a list item: the numId names a `w:num` whose
     * abstract numbering exists.
     */
    public function has(?string $numId): bool
    {
        return $numId !== null && isset($this->nums[$numId]) && isset($this->abstracts[$this->nums[$numId]['abstract']]);
    }

    /**
     * Counts one item at `$ilvl` of `$numId` and returns its marker: `-` for a bullet level, else
     * the level's template with `%1`-`%9` replaced by the counters.
     */
    public function marker(string $numId, int $ilvl): string
    {
        $counters = $this->counters[$numId] ?? [];
        $counters[$ilvl] = isset($counters[$ilvl]) ? $counters[$ilvl] + 1 : $this->start($numId, $ilvl);
        foreach (array_keys($counters) as $level) {
            if ($level > $ilvl) {
                unset($counters[$level]);
            }
        }
        $this->counters[$numId] = $counters;

        $level = $this->level($numId, $ilvl);
        if ($level['format'] === 'bullet') {
            return '-';
        }

        return trim((string) preg_replace_callback('/%([1-9])/', function (array $match) use ($numId, $counters): string {
            $k = (int) $match[1] - 1;

            return self::format($counters[$k] ?? $this->start($numId, $k), $this->level($numId, $k)['format']);
        }, $level['text'] ?? '%' . ($ilvl + 1) . '.'), ' ');
    }

    private function start(string $numId, int $ilvl): int
    {
        return $this->nums[$numId]['starts'][$ilvl] ?? $this->level($numId, $ilvl)['start'] ?? 0;
    }

    /**
     * @return array{start: ?int, format: ?string, text: ?string}
     */
    private function level(string $numId, int $ilvl): array
    {
        $num = $this->nums[$numId] ?? null;
        if ($num === null) {
            return ['start' => null, 'format' => null, 'text' => null];
        }

        return $num['levels'][$ilvl] ?? $this->abstracts[$num['abstract']][$ilvl] ?? ['start' => null, 'format' => null, 'text' => null];
    }

    /**
     * @return array<int, array{start: ?int, format: ?string, text: ?string}>
     */
    private static function levels(DOMElement $parent): array
    {
        $levels = [];
        foreach ($parent->childNodes as $lvl) {
            if (Package::isW($lvl, 'lvl') && $lvl instanceof DOMElement) {
                $ilvl = Styles::integer(Package::attribute($lvl, 'ilvl'));
                if ($ilvl !== null && $ilvl >= 0 && $ilvl <= 8 && ! isset($levels[$ilvl])) {
                    $levels[$ilvl] = self::definition($lvl);
                }
            }
        }

        return $levels;
    }

    /**
     * @return array{start: ?int, format: ?string, text: ?string}
     */
    private static function definition(DOMElement $lvl): array
    {
        return [
            'start' => Styles::integer(Package::value($lvl, 'start')),
            'format' => Package::value($lvl, 'numFmt'),
            'text' => Package::value($lvl, 'lvlText'),
        ];
    }

    /**
     * @return array{abstract: string, levels: array<int, array{start: ?int, format: ?string, text: ?string}>, starts: array<int, int>}
     */
    private static function num(DOMElement $num): array
    {
        $levels = [];
        $starts = [];
        foreach ($num->childNodes as $override) {
            if (! Package::isW($override, 'lvlOverride') || ! $override instanceof DOMElement) {
                continue;
            }
            $ilvl = Styles::integer(Package::attribute($override, 'ilvl'));
            if ($ilvl === null || $ilvl < 0 || $ilvl > 8) {
                continue;
            }
            $start = Styles::integer(Package::value($override, 'startOverride'));
            if ($start !== null) {
                $starts[$ilvl] = $start;
            }
            $lvl = Package::child($override, 'lvl');
            if ($lvl !== null) {
                $levels[$ilvl] = self::definition($lvl);
            }
        }

        return ['abstract' => Package::value($num, 'abstractNumId') ?? '', 'levels' => $levels, 'starts' => $starts];
    }

    private static function format(int $n, ?string $format): string
    {
        return match ($format) {
            'bullet', 'none' => '',
            'decimalZero' => ($n >= 0 && $n < 10 ? '0' : '') . $n,
            'lowerLetter', 'upperLetter' => $n < 1 ? (string) $n : self::letters($n, $format === 'upperLetter'),
            'lowerRoman', 'upperRoman' => $n < 1 || $n > 3999 ? (string) $n : self::roman($n, $format === 'upperRoman'),
            default => (string) $n,
        };
    }

    private static function letters(int $n, bool $upper): string
    {
        $letter = chr(($upper ? 65 : 97) + ($n - 1) % 26);

        return str_repeat($letter, intdiv($n - 1, 26) + 1);
    }

    private static function roman(int $n, bool $upper): string
    {
        $out = '';
        foreach (['m' => 1000, 'cm' => 900, 'd' => 500, 'cd' => 400, 'c' => 100, 'xc' => 90, 'l' => 50, 'xl' => 40, 'x' => 10, 'ix' => 9, 'v' => 5, 'iv' => 4, 'i' => 1] as $numeral => $value) {
            while ($n >= $value) {
                $out .= $numeral;
                $n -= $value;
            }
        }

        return $upper ? strtoupper($out) : $out;
    }
}
