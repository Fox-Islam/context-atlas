<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Markup;

use Phox\ContextAtlas\Exceptions\ContextAtlasException;

/**
 * The element tree of an XML file, read by its own tokenizer (no DOM or libxml, so the PHP,
 * JavaScript and Python packages read a file the same way) and printed in one fixed layout so
 * that minified and pretty-printed files cut the same way: one element per line, a text-only
 * element on one line, children indented two spaces, text with its whitespace collapsed.
 *
 * Paths: an element's segment is its tag, or `tag[i]` among same-name siblings; an attribute is
 * a child `@name` whose span is empty. An `id` or `xml:id` attribute anchors its element, and a
 * `ref`, `idref`, `href="#x"` or `xlink:href="#x"` attribute is an alias of that anchor.
 * The prolog, comments, processing instructions and the doctype are not printed.
 */
final class XmlOutline
{
    public const array LABELS = ['@name', 'name', '@id', 'id', '@title', 'title', '@key', 'key'];

    private const string NAME = '/\G[A-Za-z_:\x80-\xff][A-Za-z0-9_:.\x80-\xff-]*/';

    private int $at = 0;

    /** @var list<string> */
    private array $lines = [];

    /** @var list<array{path: list<string>, start: int, end: int, value: string|null, alias: string|null}> */
    private array $entries = [];

    /** @var array<string, list<string>> */
    private array $anchors = [];

    private function __construct(
        private readonly string $text,
    ) {}

    /**
     * @return array{lines: list<string>, entries: list<array{path: list<string>, start: int, end: int, value: string|null, alias: string|null}>, anchors: array<string, list<string>>, labels: list<string>}
     *
     * @throws ContextAtlasException
     */
    public static function read(string $text): array
    {
        $outline = new self($text);
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $outline->at = 3;
        }
        $outline->misc(true);
        if (($text[$outline->at] ?? '') !== '<') {
            $outline->fail();
        }
        $root = $outline->element();
        $outline->misc(false);
        if ($outline->at !== strlen($text)) {
            $outline->fail();
        }

        $outline->entries[] = ['path' => [], 'start' => 0, 'end' => 0, 'value' => null, 'alias' => null];
        $outline->print($root, [$root['tag']], 0);
        $outline->entries[0] = ['path' => [], 'start' => 0, 'end' => count($outline->lines), 'value' => null, 'alias' => null];

        return ['lines' => $outline->lines, 'entries' => $outline->entries, 'anchors' => $outline->anchors, 'labels' => self::LABELS];
    }

    /**
     * Skips whitespace, comments and processing instructions, and a doctype before the root.
     *
     * @throws ContextAtlasException
     */
    private function misc(bool $prolog): void
    {
        while (true) {
            $this->at += strspn($this->text, " \t\r\n", $this->at);
            if (substr_compare($this->text, '<?', $this->at, 2) === 0) {
                $this->skipPast('?>');
            } elseif (substr_compare($this->text, '<!--', $this->at, 4) === 0) {
                $this->skipPast('-->', 4);
            } elseif ($prolog && substr_compare($this->text, '<!DOCTYPE', $this->at, 9) === 0) {
                $this->doctype();
            } else {
                return;
            }
        }
    }

    /**
     * Moves past the first `$end` after the construct starting here.
     *
     * @throws ContextAtlasException
     */
    private function skipPast(string $end, int $from = 2): int
    {
        $close = strpos($this->text, $end, $this->at + $from);
        if ($close === false) {
            $this->fail();
        }
        $start = $this->at + $from;
        $this->at = $close + strlen($end);

        return $start;
    }

    /**
     * Skips a doctype: up to the `>` outside quotes and outside its `[...]` internal subset,
     * where comments are skipped whole.
     *
     * @throws ContextAtlasException
     */
    private function doctype(): void
    {
        $length = strlen($this->text);
        $depth = 0;
        for ($i = $this->at + 9; $i < $length; $i++) {
            $char = $this->text[$i];
            if ($char === '"' || $char === "'") {
                $close = strpos($this->text, $char, $i + 1);
                if ($close === false) {
                    break;
                }
                $i = $close;
            } elseif ($depth > 0 && substr_compare($this->text, '<!--', $i, 4) === 0) {
                $close = strpos($this->text, '-->', $i + 4);
                if ($close === false) {
                    break;
                }
                $i = $close + 2;
            } elseif ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
            } elseif ($char === '>' && $depth <= 0) {
                $this->at = $i + 1;

                return;
            }
        }
        $this->fail();
    }

    /**
     * Reads the element whose `<` is here, and its content.
     *
     * @return array{tag: string, attributes: list<array{string, string}>, content: list<mixed>}
     *
     * @throws ContextAtlasException
     */
    private function element(): array
    {
        $this->at++;
        $tag = $this->name();
        $attributes = [];
        while (true) {
            $space = strspn($this->text, " \t\r\n", $this->at);
            $this->at += $space;
            if (substr_compare($this->text, '/>', $this->at, 2) === 0) {
                $this->at += 2;

                return ['tag' => $tag, 'attributes' => $attributes, 'content' => []];
            }
            if (($this->text[$this->at] ?? '') === '>') {
                $this->at++;

                return ['tag' => $tag, 'attributes' => $attributes, 'content' => $this->content($tag)];
            }
            if ($space === 0) {
                $this->fail();
            }
            $attributes[] = $this->attribute();
        }
    }

    /**
     * @return array{string, string}
     *
     * @throws ContextAtlasException
     */
    private function attribute(): array
    {
        $name = $this->name();
        $this->at += strspn($this->text, " \t\r\n", $this->at);
        $this->expect('=');
        $this->at += strspn($this->text, " \t\r\n", $this->at);
        $quote = $this->text[$this->at] ?? '';
        if ($quote !== '"' && $quote !== "'") {
            $this->fail();
        }
        $close = strpos($this->text, $quote, $this->at + 1);
        if ($close === false) {
            $this->fail();
        }
        $value = substr($this->text, $this->at + 1, $close - $this->at - 1);
        $this->at = $close + 1;

        return [$name, self::collapse(self::decode($value))];
    }

    /**
     * Reads an element's content up to its end tag: child elements and runs of text, where a run
     * is the character data (text with entities decoded, CDATA as written) between two tags.
     *
     * @return list<mixed>
     *
     * @throws ContextAtlasException
     */
    private function content(string $tag): array
    {
        $content = [];
        $run = '';
        while (true) {
            $next = strpos($this->text, '<', $this->at);
            if ($next === false) {
                $this->at = strlen($this->text);
                $this->fail();
            }
            $run .= self::decode(substr($this->text, $this->at, $next - $this->at));
            $this->at = $next;
            if (substr_compare($this->text, '<!--', $this->at, 4) === 0) {
                $this->skipPast('-->', 4);
            } elseif (substr_compare($this->text, '<![CDATA[', $this->at, 9) === 0) {
                $start = $this->skipPast(']]>', 9);
                $run .= substr($this->text, $start, $this->at - 3 - $start);
            } elseif (substr_compare($this->text, '<?', $this->at, 2) === 0) {
                $this->skipPast('?>');
            } elseif (substr_compare($this->text, '<!', $this->at, 2) === 0) {
                $this->fail();
            } else {
                $content = self::flush($content, $run);
                $run = '';
                if (substr_compare($this->text, '</', $this->at, 2) === 0) {
                    $this->endTag($tag);

                    return $content;
                }
                $content[] = $this->element();
            }
        }
    }

    /**
     * @throws ContextAtlasException
     */
    private function endTag(string $tag): void
    {
        $start = $this->at;
        $this->at += 2;
        $name = preg_match(self::NAME, $this->text, $match, 0, $this->at) === 1 ? $match[0] : '';
        $this->at += strlen($name);
        $this->at += strspn($this->text, " \t\r\n", $this->at);
        if ($name !== $tag || ($this->text[$this->at] ?? '') !== '>') {
            $this->at = $start;
            $this->fail();
        }
        $this->at++;
    }

    /**
     * @param  list<mixed>  $content
     * @return list<mixed>
     */
    private static function flush(array $content, string $run): array
    {
        $text = self::collapse($run);
        if ($text !== '') {
            $content[] = $text;
        }

        return $content;
    }

    /**
     * Prints an element and records its entry, its attributes' entries, then its children's.
     *
     * @param  array{tag: string, attributes: list<array{string, string}>, content: list<mixed>}  $node
     * @param  list<string>  $path
     */
    private function print(array $node, array $path, int $depth): void
    {
        $entry = count($this->entries);
        $start = count($this->lines);
        $this->entries[] = ['path' => $path, 'start' => $start, 'end' => 0, 'value' => null, 'alias' => null];
        $pad = str_repeat('  ', $depth);
        $open = '<' . $node['tag'];
        foreach ($node['attributes'] as [$name, $value]) {
            $open .= ' ' . $name . '="' . str_replace('"', '&quot;', $value) . '"';
            $this->attributeEntry($path, $start, $name, $value);
        }

        $texts = array_values(array_filter($node['content'], 'is_string'));
        $children = array_filter($node['content'], 'is_array');
        if ($node['content'] === []) {
            $this->lines[] = $pad . $open . '/>';
        } elseif ($children === []) {
            $this->lines[] = $pad . $open . '>' . implode(' ', $texts) . '</' . $node['tag'] . '>';
        } else {
            $this->lines[] = $pad . $open . '>';
            $this->printContent($node['content'], $path, $depth + 1);
            $this->lines[] = $pad . '</' . $node['tag'] . '>';
        }
        $this->entries[$entry] = ['path' => $path, 'start' => $start, 'end' => count($this->lines), 'value' => $texts === [] ? null : implode(' ', $texts), 'alias' => null];
    }

    /**
     * @param  list<mixed>  $content
     * @param  list<string>  $path
     */
    private function printContent(array $content, array $path, int $depth): void
    {
        $counts = [];
        foreach ($content as $item) {
            if (is_array($item)) {
                $counts[$item['tag']] = ($counts[$item['tag']] ?? 0) + 1;
            }
        }
        $seen = [];
        foreach ($content as $item) {
            if (is_string($item)) {
                $this->lines[] = str_repeat('  ', $depth) . $item;

                continue;
            }
            /** @var array{tag: string, attributes: list<array{string, string}>, content: list<mixed>} $item */
            $tag = $item['tag'];
            $segment = $counts[$tag] > 1 ? $tag . '[' . ($seen[$tag] = ($seen[$tag] ?? -1) + 1) . ']' : $tag;
            $this->print($item, [...$path, $segment], $depth);
        }
    }

    /**
     * @param  list<string>  $path
     */
    private function attributeEntry(array $path, int $line, string $name, string $value): void
    {
        $alias = match (true) {
            $value === '' => null,
            $name === 'ref' || $name === 'idref' => $value,
            ($name === 'href' || $name === 'xlink:href') && strlen($value) > 1 && $value[0] === '#' => substr($value, 1),
            default => null,
        };
        $this->entries[] = ['path' => [...$path, '@' . $name], 'start' => $line, 'end' => $line, 'value' => $value, 'alias' => $alias];
        if (($name === 'id' || $name === 'xml:id') && $value !== '') {
            $this->anchors[$value] = $path;
        }
    }

    /**
     * @throws ContextAtlasException
     */
    private function name(): string
    {
        if (preg_match(self::NAME, $this->text, $match, 0, $this->at) !== 1) {
            $this->fail();
        }
        $this->at += strlen($match[0]);

        return $match[0];
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
     * The five predefined entities and numeric references; any other `&` stays as written, as
     * does a reference to a code point that is not a Unicode scalar value.
     */
    private static function decode(string $text): string
    {
        if (! str_contains($text, '&')) {
            return $text;
        }

        return (string) preg_replace_callback('/&(lt|gt|amp|quot|apos|#[0-9]+|#x[0-9A-Fa-f]+);/', static function (array $m): string {
            $named = ['lt' => '<', 'gt' => '>', 'amp' => '&', 'quot' => '"', 'apos' => "'"];
            if (isset($named[$m[1]])) {
                return $named[$m[1]];
            }
            $digits = ltrim(substr($m[1], $m[1][1] === 'x' ? 2 : 1), '0');
            $code = strlen($digits) > 7 ? -1 : ($m[1][1] === 'x' ? (int) hexdec($digits === '' ? '0' : $digits) : (int) ($digits === '' ? '0' : $digits));

            return $code < 1 || $code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF) ? $m[0] : (string) mb_chr($code, 'UTF-8');
        }, $text);
    }

    private static function collapse(string $text): string
    {
        return trim((string) preg_replace('/[ \t\r\n]+/', ' ', $text), ' ');
    }

    /**
     * @throws ContextAtlasException
     */
    private function fail(): never
    {
        throw new ContextAtlasException('Invalid XML at byte ' . $this->at . '.');
    }
}
