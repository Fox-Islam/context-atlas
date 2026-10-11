<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Markup;

use Phox\ContextAtlas\Exceptions\ContextAtlasException;

/**
 * The key tree of a JSON file, printed in one fixed layout so that minified and pretty-printed
 * files cut the same way: two-space indents, `"key": value`, an empty object or array as `{}`
 * or `[]`. Strings and numbers are printed as written in the source, so no language reformats
 * a number.
 */
final class JsonOutline
{
    private int $at = 0;

    /** @var list<string> */
    private array $lines = [];

    /** @var list<array{path: list<string>, start: int, end: int, value: string|null, alias: string|null}> */
    private array $entries = [];

    private function __construct(
        private readonly string $text,
    ) {}

    /**
     * @return array{lines: list<string>, entries: list<array{path: list<string>, start: int, end: int, value: string|null, alias: string|null}>, anchors: array<string, list<string>>}
     *
     * @throws ContextAtlasException
     */
    public static function read(string $text): array
    {
        $outline = new self($text);
        $outline->space();
        $outline->value([], 0, '');
        $outline->space();
        if ($outline->at !== strlen($text)) {
            $outline->fail();
        }

        return ['lines' => $outline->lines, 'entries' => $outline->entries, 'anchors' => []];
    }

    /**
     * Prints one value on a new line after `$lead` and records its entry.
     *
     * @param  list<string>  $path
     *
     * @throws ContextAtlasException
     */
    private function value(array $path, int $depth, string $lead): void
    {
        $entry = count($this->entries);
        $this->entries[] = ['path' => $path, 'start' => count($this->lines), 'end' => 0, 'value' => null, 'alias' => null];
        $pad = str_repeat('  ', $depth);
        $char = $this->text[$this->at] ?? '';

        if ($char === '{' || $char === '[') {
            $close = $char === '{' ? '}' : ']';
            $this->at++;
            $this->space();
            if (($this->text[$this->at] ?? '') === $close) {
                $this->at++;
                $this->lines[] = $pad . $lead . $char . $close;
            } else {
                $this->lines[] = $pad . $lead . $char;
                $this->members($path, $depth + 1, $char === '{');
                $this->lines[] = $pad . $close;
            }
        } else {
            $raw = $this->scalar();
            $this->lines[] = $pad . $lead . $raw;
            $this->entries[$entry]['value'] = $raw[0] === '"' ? (string) json_decode($raw) : $raw;
        }

        $this->entries[$entry]['end'] = count($this->lines);
    }

    /**
     * @param  list<string>  $path
     *
     * @throws ContextAtlasException
     */
    private function members(array $path, int $depth, bool $object): void
    {
        $close = $object ? '}' : ']';
        for ($i = 0; ; $i++) {
            $this->space();
            $lead = '';
            $segment = '[' . $i . ']';
            if ($object) {
                $key = $this->scalar();
                if ($key[0] !== '"') {
                    $this->fail();
                }
                $segment = (string) json_decode($key);
                $lead = $key . ': ';
                $this->space();
                $this->expect(':');
                $this->space();
            }
            $this->value([...$path, $segment], $depth, $lead);
            $this->space();
            if (($this->text[$this->at] ?? '') !== ',') {
                $this->expect($close);

                return;
            }
            $this->lines[] = array_pop($this->lines) . ',';
            $this->at++;
        }
    }

    /**
     * The raw text of a string, number, `true`, `false` or `null`, read byte by byte (a regex
     * would exhaust the PCRE stack on a long string).
     *
     * @throws ContextAtlasException
     */
    private function scalar(): string
    {
        $start = $this->at;
        $end = match ($this->text[$start] ?? '') {
            '"' => $this->stringEnd($start),
            't' => str_starts_with(substr($this->text, $start, 4), 'true') ? $start + 4 : null,
            'f' => str_starts_with(substr($this->text, $start, 5), 'false') ? $start + 5 : null,
            'n' => str_starts_with(substr($this->text, $start, 4), 'null') ? $start + 4 : null,
            default => $this->numberEnd($start),
        };
        if ($end === null) {
            $this->fail();
        }
        $this->at = $end;

        return substr($this->text, $start, $end - $start);
    }

    /**
     * The offset after the closing quote of the string opening at `$at`, or null when it is not
     * an RFC 8259 string.
     */
    private function stringEnd(int $at): ?int
    {
        $length = strlen($this->text);
        $i = $at + 1;
        while ($i < $length) {
            $i += strcspn($this->text, "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f", $i);
            $char = $this->text[$i] ?? '';
            if ($char === '"') {
                return $i + 1;
            }
            if ($char !== '\\') {
                return null;
            }
            $next = $this->text[$i + 1] ?? '';
            if ($next === 'u') {
                if (strspn($this->text, '0123456789abcdefABCDEF', $i + 2, 4) !== 4) {
                    return null;
                }
                $i += 6;
            } elseif ($next !== '' && str_contains('"\\/bfnrt', $next)) {
                $i += 2;
            } else {
                return null;
            }
        }

        return null;
    }

    /**
     * The offset after the number starting at `$at` (`-?(0|[1-9]\d*)(\.\d+)?([eE][+-]?\d+)?`,
     * taking the fraction and exponent only when digits follow), or null when none starts there.
     */
    private function numberEnd(int $at): ?int
    {
        $i = $at + (($this->text[$at] ?? '') === '-' ? 1 : 0);
        $first = $this->text[$i] ?? '';
        if ($first === '0') {
            $i++;
        } elseif ($first >= '1' && $first <= '9' && strlen($first) === 1) {
            $i += strspn($this->text, '0123456789', $i);
        } else {
            return null;
        }
        if (($this->text[$i] ?? '') === '.' && strspn($this->text, '0123456789', $i + 1) > 0) {
            $i += 1 + strspn($this->text, '0123456789', $i + 1);
        }
        if (($this->text[$i] ?? '') === 'e' || ($this->text[$i] ?? '') === 'E') {
            $sign = in_array($this->text[$i + 1] ?? '', ['+', '-'], true) ? 1 : 0;
            $digits = strspn($this->text, '0123456789', $i + 1 + $sign);
            if ($digits > 0) {
                $i += 1 + $sign + $digits;
            }
        }

        return $i;
    }

    private function space(): void
    {
        $this->at += strspn($this->text, " \t\r\n", $this->at);
    }

    /**
     * @throws ContextAtlasException
     */
    private function expect(string $char): void
    {
        if (($this->text[$this->at] ?? '') !== $char) {
            $this->fail();
        }
        $this->at++;
    }

    /**
     * @throws ContextAtlasException
     */
    private function fail(): never
    {
        throw new ContextAtlasException('Invalid JSON at byte ' . $this->at . '.');
    }
}
