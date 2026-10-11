<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Markup;

/**
 * The key tree of a block-style YAML file with the line span of every node, read from
 * indentation alone: enough for OpenAPI documents, Compose files and CI workflows, with no YAML
 * library, so the PHP, JavaScript and Python packages read a file the same way.
 *
 * Read: `key: value` and `- item` lines (an item may open with a key), quoted keys, `&anchor`
 * (after a key or an item, or alone on the line below one), `*alias` and `<<: *alias`. A block
 * scalar (`|`, `>`) and an unclosed flow collection (`[`, `{`) belong to their key, and their
 * lines are not parsed. Comment lines directly above a node belong to it; comment and blank
 * lines after it do not. Lines that are none of these, such as a plain scalar continued on the
 * next line, belong to the node above them. `---` and `...` are skipped, so the documents of a
 * stream read as one.
 */
final class YamlOutline
{
    private const string KEY = '/^(?:"([^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+)"|\'([^\']*+(?:\'\'[^\']*+)*+)\'|([^\s#\'"\[\]{},&*!|>%@`-][^#]*?|-[^\s#][^#]*?))\s*:(?:\s+(.*))?$/';

    /** @var list<array{path: list<string>, start: int, end: int, value: string|null, alias: string|null}> */
    private array $entries = [];

    /** @var list<array{indent: int, entry: int, kind: string, items: int}> */
    private array $stack = [];

    /** @var array<string, list<string>> */
    private array $anchors = [];

    /** @var list<string> */
    private array $lines = [];

    /**
     * @return array{lines: list<string>, entries: list<array{path: list<string>, start: int, end: int, value: string|null, alias: string|null}>, anchors: array<string, list<string>>}
     */
    public static function read(string $text): array
    {
        $outline = new self();
        $outline->lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $outline->entries[] = ['path' => [], 'start' => 0, 'end' => count($outline->lines), 'value' => null, 'alias' => null];
        $outline->stack[] = ['indent' => -1, 'entry' => 0, 'kind' => 'root', 'items' => 0];
        $outline->parse();

        return ['lines' => $outline->lines, 'entries' => $outline->entries, 'anchors' => $outline->anchors];
    }

    private function parse(): void
    {
        $block = null;
        $flow = 0;
        foreach ($this->lines as $n => $line) {
            $content = ltrim($line, ' ');
            $indent = strlen($line) - strlen($content);
            $content = rtrim($content);
            if ($content === '' || $content[0] === '#') {
                continue;
            }
            if ($block !== null && $indent > $block) {
                continue;
            }
            $block = null;
            if ($flow > 0) {
                $flow += self::depth($content);

                continue;
            }
            if (preg_match('/^(?:---|\.\.\.)(?:\s|$)/', $content) === 1) {
                continue;
            }
            [$block, $flow] = $this->node($n, $indent, $content);
        }
        $this->close(-2, count($this->lines));
    }

    /**
     * Reads a line that opens a node; returns the indent past which following lines belong to a
     * block scalar, and the depth of an unclosed flow collection.
     *
     * @return array{int|null, int}
     */
    private function node(int $n, int $indent, string $content): array
    {
        if ($content === '-' || str_starts_with($content, '- ')) {
            $this->close($indent, $n, true);
            $parent = count($this->stack) - 1;
            $path = [...$this->entries[$this->stack[$parent]['entry']]['path'], '[' . $this->stack[$parent]['items']++ . ']'];
            $this->open($n, $indent, 'item', $path);
            $rest = ltrim(substr($content, 1), ' ');
            $column = $indent + strlen($content) - strlen($rest);
            [$anchor, $rest] = self::splitAnchor($rest);
            $this->remember($anchor);

            return match (true) {
                $rest === '' => [null, 0],
                preg_match(self::KEY, $rest) === 1 && ! self::isFlow($rest) => $this->node($n, $column, $rest),
                default => $this->value($rest, $indent),
            };
        }

        if ($content[0] === '&' && count($this->stack) > 1) {
            [$anchor, $rest] = self::splitAnchor($content);
            $this->remember($anchor);

            return $rest === '' ? [null, 0] : $this->value($rest, $this->stack[count($this->stack) - 1]['indent']);
        }
        if (preg_match(self::KEY, $content, $match) !== 1) {
            return [null, 0];
        }
        $key = match (true) {
            ($match[1] ?? '') !== '' => self::unescape($match[1]),
            ($match[2] ?? '') !== '' => str_replace("''", "'", $match[2]),
            default => $match[3] ?? '',
        };
        $this->close($indent, $n);
        $path = [...$this->entries[$this->stack[count($this->stack) - 1]['entry']]['path'], $key];
        $this->open($n, $indent, 'key', $path);
        [$anchor, $rest] = self::splitAnchor(trim($match[4] ?? ''));
        $this->remember($anchor);

        return $this->value($rest, $indent);
    }

    /**
     * @return array{int|null, int}
     */
    private function value(string $rest, int $indent): array
    {
        $entry = $this->stack[count($this->stack) - 1]['entry'];
        if (preg_match('/^\*([^\s,\[\]{}]+)/', $rest, $match) === 1) {
            $this->entries[$entry]['alias'] = $match[1];

            return [null, 0];
        }
        if (preg_match('/^[|>][0-9+-]*(\s+#.*)?$/', $rest) === 1) {
            return [$indent, 0];
        }
        if (self::isFlow($rest)) {
            return [null, max(0, self::depth($rest))];
        }
        if ($rest !== '' && $rest[0] !== '#') {
            $this->entries[$entry]['value'] = self::scalar($rest);
        }

        return [null, 0];
    }

    /**
     * @param  list<string>  $path
     */
    private function open(int $n, int $indent, string $kind, array $path): void
    {
        $start = $n;
        while ($start > 0 && preg_match('/^\s*#/', $this->lines[$start - 1]) === 1) {
            $start--;
        }
        $this->entries[] = ['path' => $path, 'start' => $start, 'end' => count($this->lines), 'value' => null, 'alias' => null];
        $this->stack[] = ['indent' => $indent, 'entry' => count($this->entries) - 1, 'kind' => $kind, 'items' => 0];
    }

    /**
     * Ends the open nodes a line at this indent closes: deeper ones, and those at the same
     * indent, except that a key keeps the sequence written under it at its own indent.
     */
    private function close(int $indent, int $line, bool $item = false): void
    {
        while (count($this->stack) > 1) {
            $top = $this->stack[count($this->stack) - 1];
            if ($top['indent'] < $indent || ($top['indent'] === $indent && $item && $top['kind'] === 'key')) {
                break;
            }
            array_pop($this->stack);
            $end = $line;
            while ($end > $this->entries[$top['entry']]['start'] + 1 && preg_match('/^\s*(#.*)?$/', $this->lines[$end - 1]) === 1) {
                $end--;
            }
            $this->entries[$top['entry']]['end'] = $end;
        }
    }

    private function remember(?string $name): void
    {
        if ($name !== null) {
            $this->anchors[$name] = $this->entries[$this->stack[count($this->stack) - 1]['entry']]['path'];
        }
    }

    /**
     * @return array{string|null, string}
     */
    private static function splitAnchor(string $text): array
    {
        return preg_match('/^&([^\s,\[\]{}]+)\s*(.*)$/', $text, $match) === 1 ? [$match[1], $match[2]] : [null, $text];
    }

    private static function isFlow(string $text): bool
    {
        return $text !== '' && ($text[0] === '[' || $text[0] === '{');
    }

    /**
     * Opening minus closing brackets outside quotes.
     */
    private static function depth(string $text): int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === '#' && ($i === 0 || $text[$i - 1] === ' ')) {
                break;
            }
        }

        return $depth;
    }

    /**
     * A scalar's text: quotes removed, or a plain scalar without its trailing comment.
     */
    private static function scalar(string $text): string
    {
        if (preg_match('/^"([^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+)"/', $text, $match) === 1) {
            return self::unescape($match[1]);
        }
        if (preg_match('/^\'([^\']*+(?:\'\'[^\']*+)*+)\'/', $text, $match) === 1) {
            return str_replace("''", "'", $match[1]);
        }

        return trim((string) preg_replace('/\s+#.*$/', '', $text));
    }

    /**
     * A double-quoted string's escapes: `\n` and `\t`, and a backslash before any other
     * character stands for that character.
     */
    private static function unescape(string $text): string
    {
        return (string) preg_replace_callback('/\\\\(.)/s', static fn (array $m): string => match ($m[1]) {
            'n' => "\n",
            't' => "\t",
            default => $m[1],
        }, $text);
    }
}
