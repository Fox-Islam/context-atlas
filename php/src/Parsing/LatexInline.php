<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use Normalizer;
use Phox\ContextAtlas\Text;

/**
 * Turns LaTeX inline markup into plain text: formatting commands unwrapped, citations and
 * references kept as their keys, accents and symbols as characters, inline maths kept as
 * written. Scans bytes; every character it reads is ASCII, so UTF-8 text passes through.
 * The rules are stated in spec/formats/latex.md.
 */
final class LatexInline
{
    private const array UNWRAP = ['textbf', 'textit', 'emph', 'texttt', 'underline', 'textsc', 'textrm', 'textsf', 'textsl', 'textup', 'textmd', 'textnormal', 'mbox', 'text', 'uline'];

    private const array CITE = ['cite', 'citep', 'citet', 'citealp', 'citealt', 'citeauthor', 'citeyear', 'parencite', 'textcite', 'autocite', 'footcite', 'Cite', 'Citep', 'Citet'];

    private const array REF = ['ref', 'eqref', 'autoref', 'cref', 'Cref', 'pageref', 'nameref', 'vref'];

    private const array DROP = [
        'begin', 'end', 'label', 'nocite', 'documentclass', 'usepackage', 'RequirePackage', 'newcommand', 'renewcommand', 'providecommand',
        'newenvironment', 'renewenvironment', 'newtheorem', 'theoremstyle', 'setlength', 'addtolength', 'setcounter', 'addtocounter',
        'includegraphics', 'input', 'include', 'bibliography', 'bibliographystyle', 'vspace', 'hspace', 'pagestyle', 'thispagestyle',
        'pagenumbering', 'author', 'date', 'thanks', 'affil', 'affiliation', 'address', 'email', 'institute', 'index', 'graphicspath',
        'hypersetup', 'lstset', 'definecolor', 'color', 'geometry', 'newgeometry', 'captionsetup', 'numberwithin', 'lstinputlisting', 'inputminted',
        'fontfamily', 'fontseries', 'fontshape', 'fontsize', 'usetikzlibrary', 'setmainfont', 'setsansfont', 'setmonofont', 'setfloatalignment',
    ];

    private const array SYMBOLS = [
        'LaTeX' => 'LaTeX', 'TeX' => 'TeX', 'LaTeXe' => 'LaTeX2e', 'ldots' => '…', 'dots' => '…', 'textellipsis' => '…',
        'textendash' => '–', 'textemdash' => '—', 'S' => '§', 'P' => '¶', 'copyright' => '©', 'textcopyright' => '©',
        'textregistered' => '®', 'texttrademark' => '™', 'pounds' => '£', 'textsterling' => '£', 'euro' => '€', 'texteuro' => '€',
        'textbackslash' => '\\', 'textasciitilde' => '~', 'textasciicircum' => '^', 'textless' => '<', 'textgreater' => '>',
        'textbar' => '|', 'textunderscore' => '_', 'slash' => '/', 'quad' => ' ', 'qquad' => ' ', 'newline' => ' ', 'linebreak' => ' ',
        'textquoteleft' => "'", 'textquoteright' => "'", 'textquotedblleft' => '"', 'textquotedblright' => '"', 'textbullet' => '•',
        'textdegree' => '°', 'dag' => '†', 'ddag' => '‡', 'i' => 'ı', 'j' => 'ȷ', 'ss' => 'ß', 'o' => 'ø', 'O' => 'Ø', 'ae' => 'æ',
        'AE' => 'Æ', 'oe' => 'œ', 'OE' => 'Œ', 'aa' => 'å', 'AA' => 'Å', 'l' => 'ł', 'L' => 'Ł',
    ];

    private const array ACCENTS = ["'" => "\u{301}", '`' => "\u{300}", '^' => "\u{302}", '"' => "\u{308}", '~' => "\u{303}", '=' => "\u{304}", '.' => "\u{307}"];

    private const array LETTER_ACCENTS = ['c' => "\u{327}", 'v' => "\u{30C}", 'u' => "\u{306}", 'H' => "\u{30B}", 'k' => "\u{328}", 'r' => "\u{30A}"];

    /** @var array<string, int> quantity command => brace arguments */
    private const array QUANTITIES = ['num' => 1, 'numrange' => 2, 'qty' => 2, 'SI' => 2, 'qtyrange' => 3, 'SIrange' => 3, 'unit' => 1, 'si' => 1];

    private const array FRACTIONS = ['frac', 'tfrac', 'dfrac', 'nicefrac', 'sfrac', 'xfrac'];

    private const string FRACTION = "\x03";

    private const array SI_PREFIXES = [
        'yocto' => 'y', 'zepto' => 'z', 'atto' => 'a', 'femto' => 'f', 'pico' => 'p', 'nano' => 'n', 'micro' => 'µ', 'milli' => 'm',
        'centi' => 'c', 'deci' => 'd', 'deca' => 'da', 'deka' => 'da', 'hecto' => 'h', 'kilo' => 'k', 'mega' => 'M', 'giga' => 'G',
        'tera' => 'T', 'peta' => 'P', 'exa' => 'E', 'zetta' => 'Z', 'yotta' => 'Y',
    ];

    private const array SI_UNITS = [
        'metre' => 'm', 'meter' => 'm', 'gram' => 'g', 'kilogram' => 'kg', 'second' => 's', 'ampere' => 'A', 'kelvin' => 'K',
        'mole' => 'mol', 'candela' => 'cd', 'hertz' => 'Hz', 'newton' => 'N', 'pascal' => 'Pa', 'joule' => 'J', 'watt' => 'W',
        'volt' => 'V', 'ohm' => 'Ω', 'coulomb' => 'C', 'farad' => 'F', 'henry' => 'H', 'tesla' => 'T', 'weber' => 'Wb',
        'siemens' => 'S', 'becquerel' => 'Bq', 'gray' => 'Gy', 'sievert' => 'Sv', 'lumen' => 'lm', 'lux' => 'lx',
        'radian' => 'rad', 'steradian' => 'sr', 'litre' => 'L', 'liter' => 'L', 'tonne' => 't', 'hour' => 'h', 'minute' => 'min',
        'day' => 'd', 'hectare' => 'ha', 'electronvolt' => 'eV', 'degreeCelsius' => '°C', 'degree' => '°', 'arcminute' => '′',
        'arcsecond' => '″', 'percent' => '%', 'bel' => 'B', 'decibel' => 'dB', 'neper' => 'Np', 'bit' => 'bit', 'byte' => 'B',
        'angstrom' => 'Å', 'astronomicalunit' => 'au', 'dalton' => 'Da', 'bar' => 'bar',
    ];

    private const array SPACES = [' ' => ' ', "\n" => ' ', "\t" => ' ', ',' => ' ', ';' => ' ', ':' => ' ', '>' => ' ', '!' => '', '-' => '', '/' => '', '@' => ''];

    private const string OPEN = "\x01";

    private const string CLOSE = "\x02";

    private const string DIMENSION = '/^[ \t\n]*[-+]?(?:\d+(?:\.\d+)?|\.\d+)[ \t\n]*(?:pt|em|ex|in|cm|mm|bp|pc|sp|mu|px|\\\\[A-Za-z]+)[ \t\n]*$/';

    public static function convert(string $text): string
    {
        return str_replace([self::OPEN, self::CLOSE], '', self::marked($text));
    }

    /**
     * A paragraph's text, or `''` when it holds nothing but leftover arguments of unknown
     * commands, each one short token of letters, digits, `.`, `_` or `-` (`\pgfsetlayers{main}`).
     */
    public static function paragraph(string $text): string
    {
        $marked = self::marked($text);
        preg_match_all('/\x01([^\x01\x02]*)\x02/', $marked, $spans);
        $outside = Text::squash((string) preg_replace('/\x01[^\x01\x02]*\x02/', '', $marked));
        $words = array_filter($spans[1], static fn (string $span): bool => Text::trim($span) !== '');
        $single = array_filter($words, static fn (string $span): bool => preg_match('/^[A-Za-z0-9._-]{1,12}$/', Text::trim($span)) === 1);

        return $outside === '' && $words !== [] && count($single) === count($words) ? '' : str_replace([self::OPEN, self::CLOSE], '', $marked);
    }

    /**
     * Converts, wrapping each unknown command's leftover argument in OPEN and CLOSE.
     */
    private static function marked(string $text): string
    {
        $out = '';
        $n = strlen($text);
        $i = 0;

        while ($i < $n) {
            $c = $text[$i];

            if ($c === '\\') {
                [$piece, $i] = self::command($text, $i);
                $piece = str_starts_with($piece, self::FRACTION) ? (preg_match('/[0-9]$/', $out) === 1 ? ' ' : '') . substr($piece, 1) : $piece;
                $out .= $piece;
            } elseif ($c === '{') {
                [$inner, $i] = self::group($text, $i);
                $out .= self::convert($inner);
            } elseif ($c === '$') {
                [$piece, $i] = self::math($text, $i);
                $out .= $piece;
            } else {
                [$piece, $i] = self::character($text, $i);
                $out .= $piece;
            }
        }

        return $out;
    }

    /**
     * The offset after spaces and tabs and, when allowed, one line break with the spaces after it.
     */
    public static function space(string $text, int $at, bool $newline = true): int
    {
        $n = strlen($text);
        while ($at < $n && ($text[$at] === ' ' || $text[$at] === "\t")) {
            $at++;
        }
        if ($newline && $at < $n && $text[$at] === "\n") {
            $at++;
            while ($at < $n && ($text[$at] === ' ' || $text[$at] === "\t")) {
                $at++;
            }
        }

        return $at;
    }

    /**
     * The content of the brace group opening at `$at` and the offset after its closing brace; an
     * unclosed group runs to the end.
     *
     * @return array{string, int}
     */
    public static function group(string $text, int $at): array
    {
        $depth = 0;
        for ($i = $at, $n = strlen($text); $i < $n; $i++) {
            if ($text[$i] === '\\') {
                $i++;
            } elseif ($text[$i] === '{') {
                $depth++;
            } elseif ($text[$i] === '}' && --$depth === 0) {
                return [substr($text, $at + 1, $i - $at - 1), $i + 1];
            }
        }

        return [substr($text, $at + 1), strlen($text)];
    }

    /**
     * The content of the `[...]` opening at `$at` and the offset after it: the first `]` outside
     * braces closes it.
     *
     * @return array{string, int}
     */
    public static function optional(string $text, int $at): array
    {
        $depth = 0;
        for ($i = $at + 1, $n = strlen($text); $i < $n; $i++) {
            if ($text[$i] === '\\') {
                $i++;
            } elseif ($text[$i] === '{') {
                $depth++;
            } elseif ($text[$i] === '}') {
                $depth--;
            } elseif ($text[$i] === ']' && $depth <= 0) {
                return [substr($text, $at + 1, $i - $at - 1), $i + 1];
            }
        }

        return [substr($text, $at + 1), strlen($text)];
    }

    /**
     * A command's required argument after any optional ones, each allowed to follow spaces and
     * one line break; null, and `$at` unchanged, when no brace group follows.
     *
     * @return array{?string, int}
     */
    public static function argument(string $text, int $at): array
    {
        $i = self::space($text, $at);
        while (($text[$i] ?? '') === '[') {
            $i = self::space($text, self::optional($text, $i)[1]);
        }

        return ($text[$i] ?? '') === '{' ? self::group($text, $i) : [null, $at];
    }

    /**
     * The `[...]` and `{...}` groups directly after `$at`, with nothing between them; returns the
     * brace groups' contents.
     *
     * @return array{list<string>, int}
     */
    public static function directArguments(string $text, int $at): array
    {
        $groups = [];
        while (true) {
            $c = $text[$at] ?? '';
            if ($c === '[') {
                $at = self::optional($text, $at)[1];
            } elseif ($c === '{') {
                [$groups[], $at] = self::group($text, $at);
            } else {
                return [$groups, $at];
            }
        }
    }

    /**
     * @return array{string, int}
     */
    private static function command(string $text, int $at): array
    {
        $next = $text[$at + 1] ?? '';

        if ($next === '') {
            return ['', $at + 1];
        }
        if (preg_match('/\G[A-Za-z]+/', $text, $match, 0, $at + 1) !== 1) {
            return self::symbol($text, $at, $next);
        }

        $name = $match[0];
        $i = $at + 1 + strlen($name);
        $i = ($text[$i] ?? '') === '*' ? $i + 1 : $i;

        if ($name === 'verb' || $name === 'Verb') {
            return self::verb($text, $at);
        }
        if (isset(self::LETTER_ACCENTS[$name]) && ($text[$i] ?? '') === '{') {
            [$inner, $i] = self::group($text, $i);

            return [self::accent(self::convert($inner), self::LETTER_ACCENTS[$name]), $i];
        }
        if (isset(self::SYMBOLS[$name])) {
            return [self::SYMBOLS[$name], $i];
        }

        return self::named($text, $name, $i);
    }

    /**
     * siunitx quantities and the `units` package: `\qty{9.81}{\metre\per\second\squared}` gives
     * `9.81 m/s²`, `\unit[11]{inches}` gives `11 inches`.
     *
     * @return array{string, int}
     */
    private static function quantity(string $text, string $name, int $i): array
    {
        $value = null;
        $j = self::space($text, $i);
        if ($name === 'unit' && ($text[$j] ?? '') === '[') {
            [$option, $after] = self::optional($text, $j);
            [$value, $i] = str_contains($option, '=') ? [null, $i] : [$option, $after];
        }
        $parts = [];
        for ($k = 0; $k < self::QUANTITIES[$name]; $k++) {
            [$parts[], $i] = self::argument($text, $i);
        }
        $parts = array_map(static fn (?string $part): string => $part ?? '', $parts);
        $numbers = array_map(static fn (string $part): string => Text::trim(self::convert($part)), $name === 'unit' || $name === 'si' ? [] : array_slice($parts, 0, in_array($name, ['num', 'numrange'], true) ? count($parts) : count($parts) - 1));
        $unit = in_array($name, ['num', 'numrange'], true) ? '' : self::units($parts[count($parts) - 1]);
        $number = implode('–', array_merge($value === null ? [] : [Text::trim(self::convert($value))], $numbers));

        return [Text::trim($number . ' ' . $unit), $i];
    }

    /**
     * A siunitx unit: prefixes and units as symbols, `\per` as `/`, `\squared` and `\cubed`
     * (or `\square` and `\cubic` before the unit) as powers; other macros by name, other text
     * converted.
     */
    private static function units(string $raw): string
    {
        preg_match_all('/\\\\([A-Za-z]+)|([^\\\\]+)/', $raw, $tokens, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $out = '';
        $prefix = '';
        $power = '';
        $joiner = '';

        foreach ($tokens as $token) {
            $macro = $token[1];
            if ($macro === null) {
                $out .= $joiner . Text::trim(self::convert((string) $token[2]));
                $joiner = '';
            } elseif (isset(self::SI_PREFIXES[$macro])) {
                $prefix .= self::SI_PREFIXES[$macro];
            } elseif ($macro === 'per') {
                $joiner = '/';
            } elseif ($macro === 'square' || $macro === 'cubic') {
                $power = $macro === 'square' ? '²' : '³';
            } elseif ($macro === 'squared' || $macro === 'cubed') {
                $out .= $macro === 'squared' ? '²' : '³';
            } else {
                $symbol = self::SI_UNITS[$macro] ?? $macro;
                $out .= ($joiner !== '' ? $joiner : ($out === '' ? '' : ' ')) . $prefix . $symbol . $power;
                $prefix = $power = $joiner = '';
            }
        }

        return Text::trim($out);
    }

    /**
     * @return array{string, int}
     */
    private static function named(string $text, string $name, int $i): array
    {
        if (isset(self::QUANTITIES[$name])) {
            return self::quantity($text, $name, $i);
        }
        if (in_array($name, self::FRACTIONS, true)) {
            [$top, $i] = self::argument($text, $i);
            [$bottom, $i] = self::argument($text, $i);

            return [self::FRACTION . Text::trim(self::convert($top ?? '')) . '/' . Text::trim(self::convert($bottom ?? '')), $i];
        }
        if (in_array($name, self::UNWRAP, true)) {
            [$argument, $i] = self::argument($text, $i);

            return [$argument === null ? '' : self::convert($argument), $i];
        }
        if ($name === 'url') {
            [$argument, $i] = self::argument($text, $i);

            return [Text::trim($argument ?? ''), $i];
        }
        if ($name === 'href') {
            [$url, $i] = self::argument($text, $i);
            [$label, $i] = self::argument($text, $i);

            return [self::convert($label ?? '') . ' (' . Text::trim($url ?? '') . ')', $i];
        }
        if ($name === 'footnote') {
            [$argument, $i] = self::argument($text, $i);

            return [$argument === null ? '' : ' (' . self::convert($argument) . ')', $i];
        }
        if (in_array($name, self::CITE, true)) {
            [$keys, $i] = self::argument($text, $i);
            $keys = array_values(array_filter(array_map(Text::trim(...), explode(',', $keys ?? '')), static fn (string $key): bool => $key !== ''));

            return ['[' . implode(', ', $keys) . ']', $i];
        }
        if (in_array($name, self::REF, true)) {
            [$key, $i] = self::argument($text, $i);

            return [Text::trim($key ?? ''), $i];
        }

        [$groups, $i] = self::directArguments($text, $i);
        $last = $groups === [] ? null : $groups[count($groups) - 1];
        if ($last === null || in_array($name, self::DROP, true) || preg_match(self::DIMENSION, $last) === 1) {
            return ['', $i];
        }

        return [self::OPEN . self::convert($last) . self::CLOSE, $i];
    }

    /**
     * @return array{string, int}
     */
    private static function symbol(string $text, int $at, string $next): array
    {
        if ($next === '\\') {
            $i = $at + 2;
            $i = ($text[$i] ?? '') === '*' ? $i + 1 : $i;

            return [' ', ($text[$i] ?? '') === '[' ? self::optional($text, $i)[1] : $i];
        }
        if ($next === '(') {
            $end = strpos($text, '\\)', $at + 2);
            $end = $end === false ? strlen($text) : $end + 2;

            return [substr($text, $at, $end - $at), $end];
        }
        if (isset(self::ACCENTS[$next])) {
            return self::symbolAccent($text, $at + 2, self::ACCENTS[$next]);
        }

        return [self::SPACES[$next] ?? $next, $at + 2];
    }

    /**
     * @return array{string, int}
     */
    private static function symbolAccent(string $text, int $i, string $mark): array
    {
        $c = $text[$i] ?? '';
        if ($c === '{') {
            [$inner, $i] = self::group($text, $i);

            return [self::accent(self::convert($inner), $mark), $i];
        }
        if ($c === '\\') {
            [$base, $i] = self::command($text, $i);

            return [self::accent($base, $mark), $i];
        }
        if (preg_match('/[A-Za-z]/', $c) === 1) {
            return [self::accent($c, $mark), $i + 1];
        }

        return ['', $i];
    }

    private static function accent(string $base, string $mark): string
    {
        if ($base === '') {
            return '';
        }
        $combined = mb_substr($base, 0, 1) . $mark;
        $normal = Normalizer::normalize($combined, Normalizer::FORM_C);

        return ($normal === false ? $combined : $normal) . mb_substr($base, 1);
    }

    /**
     * @return array{string, int}
     */
    private static function verb(string $text, int $at): array
    {
        if (preg_match('/\G\\\\[vV]erb\*?([^A-Za-z \t\n*])/u', $text, $match, 0, $at) !== 1) {
            return ['', $at + 5];
        }
        $start = $at + strlen($match[0]);
        $end = strpos($text, $match[1], $start);
        $end = $end === false ? strlen($text) : $end;

        return [substr($text, $start, $end - $start), min(strlen($text), $end + strlen($match[1]))];
    }

    /**
     * @return array{string, int}
     */
    private static function math(string $text, int $at): array
    {
        $double = ($text[$at + 1] ?? '') === '$';
        $from = $at + ($double ? 2 : 1);

        for ($i = $from, $n = strlen($text); $i < $n; $i++) {
            if ($text[$i] === '\\') {
                $i++;
            } elseif ($text[$i] === '$' && (! $double || ($text[$i + 1] ?? '') === '$')) {
                $end = $i + ($double ? 2 : 1);

                return [substr($text, $at, $end - $at), $end];
            }
        }

        return ['$', $at + 1];
    }

    /**
     * @return array{string, int}
     */
    private static function character(string $text, int $at): array
    {
        $c = $text[$at];

        return match (true) {
            $c === '}' => ['', $at + 1],
            $c === '~' => [' ', $at + 1],
            substr($text, $at, 3) === '---' => ['—', $at + 3],
            substr($text, $at, 2) === '--' => ['–', $at + 2],
            substr($text, $at, 2) === '``', substr($text, $at, 2) === "''" => ['"', $at + 2],
            $c === '`' => ["'", $at + 1],
            default => [$c, $at + 1],
        };
    }
}
