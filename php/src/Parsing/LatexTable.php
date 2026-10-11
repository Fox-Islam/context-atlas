<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use Phox\ContextAtlas\Text;

/**
 * Splits the content of a LaTeX tabular into rows and cells: rows at `\\` and
 * `\tabularnewline`, cells at `&`, both outside braces; rule commands dropped. In a longtable
 * the repeated header and the footers are dropped and a `\caption` row gives the caption.
 * The rules are stated in spec/formats/latex.md.
 */
final class LatexTable
{
    private const string RULES = '/\\\\(?:(?:toprule|midrule|bottomrule)(?:\[[^\]]*\])?|hline|hdashline|cline[ \t\n]*\{[^}]*\}|hhline[ \t\n]*\{[^}]*\}|cmidrule[ \t\n]*(?:\[[^\]]*\])?[ \t\n]*(?:\([^)]*\))?[ \t\n]*\{[^}]*\}|addlinespace(?:[ \t\n]*\[[^\]]*\])?|specialrule[ \t\n]*\{[^}]*\}[ \t\n]*\{[^}]*\}[ \t\n]*\{[^}]*\}|rowcolor[ \t\n]*(?:\[[^\]]*\])?[ \t\n]*\{[^}]*\})(?![A-Za-z])/';

    private const array LONGTABLE_MARKS = ['endfirsthead', 'endhead', 'endfoot', 'endlastfoot'];

    /**
     * @return array{?string, list<list<string>>} the caption a longtable gives, and the rows
     */
    public static function read(string $content, bool $long): array
    {
        $caption = null;
        $rows = [];

        foreach (self::kept(self::split($content, $long)) as $raw) {
            $raw = (string) preg_replace(self::RULES, '', $raw);
            if ($long && preg_match('/(?<!\\\\)\\\\caption(?![A-Za-z])\*?/', $raw, $match, PREG_OFFSET_CAPTURE) === 1) {
                [$argument] = LatexInline::argument($raw, $match[0][1] + strlen($match[0][0]));
                $caption = Text::squash(LatexInline::convert($argument ?? ''));

                continue;
            }
            $cells = array_map(static fn (string $cell): string => Text::squash(LatexInline::convert($cell)), self::cells($raw));
            if (array_filter($cells, static fn (string $cell): bool => $cell !== '') !== []) {
                $rows[] = $cells;
            }
        }

        return [$caption, $rows];
    }

    /**
     * @return list<array{string, ?string}> each row's source and the longtable mark that ended it
     */
    private static function split(string $content, bool $long): array
    {
        $rows = [];
        $row = '';
        $depth = 0;
        $n = strlen($content);

        for ($i = 0; $i < $n; $i++) {
            $c = $content[$i];
            if ($c !== '\\') {
                $depth += $c === '{' ? 1 : ($c === '}' ? -1 : 0);
                $row .= $c;

                continue;
            }
            $name = preg_match('/\G[A-Za-z]+/', $content, $match, 0, $i + 1) === 1 ? $match[0] : '';
            $mark = $long && in_array($name, self::LONGTABLE_MARKS, true) ? $name : null;
            if ($depth <= 0 && (($content[$i + 1] ?? '') === '\\' || $name === 'tabularnewline' || $mark !== null)) {
                $rows[] = [$row, $mark];
                $row = '';
                $i = self::afterBreak($content, $i, $name);

                continue;
            }
            $piece = $name !== '' ? '\\' . $name : substr($content, $i, 2);
            $row .= $piece;
            $i += strlen($piece) - 1;
        }
        $rows[] = [$row, null];

        return $rows;
    }

    private static function afterBreak(string $content, int $i, string $name): int
    {
        if ($name !== '') {
            return $i + strlen($name);
        }
        $i += 2;
        $i = ($content[$i] ?? '') === '*' ? $i + 1 : $i;
        $j = LatexInline::space($content, $i, false);

        return (($content[$j] ?? '') === '[' ? LatexInline::optional($content, $j)[1] : $i) - 1;
    }

    /**
     * Drops a longtable's repeated header (the rows ended by `\endhead` when `\endfirsthead` is
     * used) and its footers (the rows ended by `\endfoot` or `\endlastfoot`).
     *
     * @param  list<array{string, ?string}>  $rows
     * @return list<string>
     */
    private static function kept(array $rows): array
    {
        $first = in_array('endfirsthead', array_column($rows, 1), true);
        $kept = [];
        $segment = [];

        foreach ($rows as [$raw, $mark]) {
            $segment[] = $raw;
            if ($mark === null) {
                continue;
            }
            if ($mark === 'endfirsthead' || ($mark === 'endhead' && ! $first)) {
                array_push($kept, ...$segment);
            }
            $segment = [];
        }

        return [...$kept, ...$segment];
    }

    /**
     * @return list<string>
     */
    private static function cells(string $row): array
    {
        $cells = [];
        $cell = '';
        $depth = 0;

        for ($i = 0, $n = strlen($row); $i < $n; $i++) {
            $c = $row[$i];
            if ($c === '\\') {
                $cell .= substr($row, $i, 2);
                $i++;
            } elseif ($c === '&' && $depth <= 0) {
                $cells[] = $cell;
                $cell = '';
            } else {
                $depth += $c === '{' ? 1 : ($c === '}' ? -1 : 0);
                $cell .= $c;
            }
        }
        $cells[] = $cell;

        return $cells;
    }
}
