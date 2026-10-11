<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Markup;

/**
 * The key tree of a TOML file with the line span of every node, read line by line from the
 * original text, with no TOML library, so the PHP, JavaScript and Python packages read a file
 * the same way.
 *
 * Read: `[a.b]` tables, `[[a]]` array-of-tables items (path `a` then `[i]`), `key = value`
 * with dotted and quoted keys. Only headers and keys are nodes: `[tool.black]` is one node at
 * path `tool > black`, with no node for `tool`, and Subtrees hangs it under the nearest
 * ancestor path that has one. Strings are unescaped; arrays and inline tables are one node with
 * no value and no children, and their lines, like those of a multi-line string, belong to their
 * key. Comment lines directly above a node belong to it; comment and blank lines after it do
 * not.
 */
final class TomlOutline
{
    private const string SEGMENT = '(?:[A-Za-z0-9_-]+|"[^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+"|\'[^\']*\')';

    private const string KEY = self::SEGMENT . '(?:[ \t]*\.[ \t]*' . self::SEGMENT . ')*+';

    /** @var list<string> */
    private array $lines = [];

    /** @var list<array{path: list<string>, start: int, end: int, value: string|null, alias: string|null}> */
    private array $entries = [];

    /** @var list<int> */
    private array $stack = [0];

    /** @var list<string> */
    private array $table = [];

    /** @var array<string, int> */
    private array $arrays = [];

    /**
     * @return array{lines: list<string>, entries: list<array{path: list<string>, start: int, end: int, value: string|null, alias: string|null}>, anchors: array<string, list<string>>}
     */
    public static function read(string $text): array
    {
        $outline = new self();
        $outline->lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $outline->entries[] = ['path' => [], 'start' => 0, 'end' => count($outline->lines), 'value' => null, 'alias' => null];
        $outline->parse();

        return ['lines' => $outline->lines, 'entries' => $outline->entries, 'anchors' => []];
    }

    private function parse(): void
    {
        $count = count($this->lines);
        for ($n = 0; $n < $count; $n++) {
            $content = trim($this->lines[$n], " \t");
            if ($content === '' || $content[0] === '#') {
                continue;
            }
            if (preg_match('/^\[\[[ \t]*(' . self::KEY . ')[ \t]*\]\][ \t]*(?:#.*)?$/', $content, $match) === 1) {
                $this->header($n, self::keys($match[1]), true);
            } elseif (preg_match('/^\[[ \t]*(' . self::KEY . ')[ \t]*\][ \t]*(?:#.*)?$/', $content, $match) === 1) {
                $this->header($n, self::keys($match[1]), false);
            } elseif (preg_match('/^(' . self::KEY . ')[ \t]*=[ \t]*(.*)$/', $content, $match) === 1) {
                $this->open($n, [...$this->table, ...self::keys($match[1])]);
                $n = $this->value($n, strlen($this->lines[$n]) - strlen(ltrim($this->lines[$n], " \t")) + strlen($content) - strlen($match[2]));
            }
        }
        $this->close([], $count, true);
    }

    /**
     * @param  list<string>  $keys
     */
    private function header(int $n, array $keys, bool $item): void
    {
        $path = [];
        $last = count($keys) - 1;
        foreach ($keys as $i => $key) {
            $path[] = $key;
            $index = self::key($path);
            if ($i < $last && isset($this->arrays[$index])) {
                $path[] = '[' . ($this->arrays[$index] - 1) . ']';
            }
        }
        if ($item) {
            $index = self::key($path);
            $this->arrays[$index] = ($this->arrays[$index] ?? 0) + 1;
            $path[] = '[' . ($this->arrays[$index] - 1) . ']';
        }
        $this->table = $path;
        $this->open($n, $path);
    }

    /**
     * Closes the open nodes whose path does not lead to `$path`, then opens its node.
     *
     * @param  list<string>  $path
     */
    private function open(int $n, array $path): void
    {
        $this->close($path, $n);
        $start = $n;
        while ($start > 0 && preg_match('/^\s*#/', $this->lines[$start - 1]) === 1) {
            $start--;
        }
        $this->entries[] = ['path' => $path, 'start' => $start, 'end' => count($this->lines), 'value' => null, 'alias' => null];
        $this->stack[] = count($this->entries) - 1;
    }

    /**
     * Ends the open nodes whose path is not a proper prefix of `$path` (all of them when `$all`),
     * at `$line` moved up over trailing blank and comment lines but never to the node's own
     * first line.
     *
     * @param  list<string>  $path
     */
    private function close(array $path, int $line, bool $all = false): void
    {
        while (count($this->stack) > 1) {
            $top = $this->stack[count($this->stack) - 1];
            $open = $this->entries[$top]['path'];
            if (! $all && count($open) < count($path) && array_slice($path, 0, count($open)) === $open) {
                break;
            }
            array_pop($this->stack);
            $end = $line;
            while ($end > $this->entries[$top]['start'] + 1 && preg_match('/^\s*(#.*)?$/', $this->lines[$end - 1]) === 1) {
                $end--;
            }
            $this->entries[$top]['end'] = $end;
        }
    }

    /**
     * Reads the value starting at byte `$column` of line `$n` into the top node; returns the
     * line it ends on.
     */
    private function value(int $n, int $column): int
    {
        $line = $this->lines[$n];
        $rest = substr($line, $column);
        $entry = $this->stack[count($this->stack) - 1];
        $first = $rest[0] ?? '';

        if ($first === '"' || $first === "'" || $first === '[' || $first === '{') {
            [$endLine, $endColumn] = $this->extent($n, $column);
            if ($first === '[' || $first === '{') {
                return $endLine;
            }
            $raw = $endLine === $n
                ? substr($line, $column, $endColumn - $column)
                : implode("\n", [substr($line, $column), ...array_slice($this->lines, $n + 1, $endLine - $n - 1), substr($this->lines[$endLine] ?? '', 0, $endColumn)]);
            $this->entries[$entry]['value'] = self::string($raw);

            return $endLine;
        }
        $hash = strpos($rest, '#');
        $plain = trim($hash === false ? $rest : substr($rest, 0, $hash), " \t");
        $this->entries[$entry]['value'] = $plain === '' ? null : $plain;

        return $n;
    }

    /**
     * Where the string, array or inline table starting at byte `$column` of line `$n` ends: the
     * line and the byte after its closing delimiter. Brackets count outside strings and
     * comments; a single-line string left open at the end of its line closes there; an
     * unclosed value runs to the end of the text.
     *
     * @return array{int, int}
     */
    private function extent(int $n, int $column): array
    {
        $depth = 0;
        $mode = '';
        $count = count($this->lines);
        for ($l = $n; $l < $count; $l++) {
            $text = $this->lines[$l];
            $length = strlen($text);
            for ($i = $l === $n ? $column : 0; $i < $length; $i++) {
                $char = $text[$i];
                if ($mode === '' && $char === '#') {
                    break;
                }
                if ($mode === '') {
                    if ($char === '"' || $char === "'") {
                        $mode = substr_compare($text, str_repeat($char, 3), $i, 3) === 0 ? str_repeat($char, 3) : $char;
                        $i += strlen($mode) - 1;
                    } elseif ($char === '[' || $char === '{') {
                        $depth++;
                    } elseif ($char === ']' || $char === '}') {
                        $depth--;
                    }
                } elseif ($char === '\\' && ($mode === '"' || $mode === '"""')) {
                    $i++;
                } elseif ($char === $mode[0]) {
                    $run = strspn($text, $char, $i);
                    if (strlen($mode) === 1 || $run >= 3) {
                        $i += (strlen($mode) === 1 ? 1 : min($run, 5)) - 1;
                        $mode = '';
                    } else {
                        $i += $run - 1;
                    }
                }
                if ($mode === '' && $depth <= 0) {
                    return [$l, $i + 1];
                }
            }
            if ($mode === '"' || $mode === "'") {
                $mode = '';
                if ($depth <= 0) {
                    return [$l, $length];
                }
            }
        }

        return [$count - 1, strlen($this->lines[$count - 1])];
    }

    /**
     * A string's text: a literal string as written, a basic string unescaped; a multi-line one
     * without the newline right after its opening delimiter.
     */
    private static function string(string $raw): ?string
    {
        foreach (['"""', "'''"] as $delimiter) {
            if (str_starts_with($raw, $delimiter)) {
                if (strlen($raw) < 6 || ! str_ends_with($raw, $delimiter)) {
                    return null;
                }
                $body = substr($raw, 3, -3);
                $body = str_starts_with($body, "\n") ? substr($body, 1) : $body;

                return $delimiter === "'''" ? $body : self::unescape($body);
            }
        }
        if (preg_match('/^"([^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+)"$/', $raw, $match) === 1) {
            return self::unescape($match[1]);
        }
        if (preg_match('/^\'([^\']*)\'$/', $raw, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * Basic-string escapes: `\b \t \n \f \r \" \\`, `\uXXXX` and `\UXXXXXXXX`, and a backslash
     * at the end of a line, which removes itself and the whitespace and newlines after it. Any
     * other escape, and one naming no Unicode scalar value, stays as written.
     */
    private static function unescape(string $text): string
    {
        return (string) preg_replace_callback('/\\\\(?:[ \t]*\n[ \t\n]*|u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|.)/s', static function (array $m): string {
            $code = $m[0][1];
            if (str_contains($m[0], "\n")) {
                return '';
            }
            if ($code === 'u' || $code === 'U') {
                $point = (int) hexdec(substr($m[0], 2));

                return $point > 0x10FFFF || ($point >= 0xD800 && $point <= 0xDFFF) ? $m[0] : (string) mb_chr($point, 'UTF-8');
            }

            return match ($code) {
                'b' => "\x08",
                't' => "\t",
                'n' => "\n",
                'f' => "\x0C",
                'r' => "\r",
                '"' => '"',
                '\\' => '\\',
                default => $m[0],
            };
        }, $text);
    }

    /**
     * A dotted key's segments: bare, or quoted (basic unescaped, literal as written).
     *
     * @return list<string>
     */
    private static function keys(string $key): array
    {
        preg_match_all('/' . self::SEGMENT . '/', $key, $matches);

        return array_map(static fn (string $segment): string => match ($segment[0]) {
            '"' => self::unescape(substr($segment, 1, -1)),
            "'" => substr($segment, 1, -1),
            default => $segment,
        }, $matches[0]);
    }

    /**
     * @param  list<string>  $path
     */
    private static function key(array $path): string
    {
        return implode("\x1f", $path);
    }
}
