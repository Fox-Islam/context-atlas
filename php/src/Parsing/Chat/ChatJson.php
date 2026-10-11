<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing\Chat;

use Phox\ContextAtlas\Exceptions\ContextAtlasException;

/**
 * JSON for the chat reader, scanned by hand so a long string never meets a regex: objects decode
 * to JsonMap, numbers to JsonNumber (as written), strings to their text with an unpaired
 * surrogate escape as U+FFFD. Errors name the byte offset where reading stopped. The rules are
 * stated in spec/formats/chat.md.
 */
final class ChatJson
{
    public const int MAX_DEPTH = 512;

    private const array DROPPED = ['encrypted_content', 'signature'];

    private const string CONTROL = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f";

    private int $at = 0;

    private function __construct(
        private readonly string $text,
    ) {}

    /**
     * @throws ContextAtlasException
     */
    public static function decode(string $text): mixed
    {
        $json = new self($text);
        $json->space();
        $value = $json->value(1);
        $json->space();
        if ($json->at !== strlen($text)) {
            $json->fail();
        }

        return $value;
    }

    /**
     * Whether the text is one JSON value.
     */
    public static function valid(string $text): bool
    {
        try {
            self::decode($text);

            return true;
        } catch (ContextAtlasException) {
            return false;
        }
    }

    /**
     * Compact JSON: no whitespace, members in order, numbers as written, members named
     * `encrypted_content` or `signature` left out, strings escaping only `"`, `\` and controls.
     */
    public static function encode(mixed $value): string
    {
        if ($value instanceof JsonMap) {
            $members = [];
            foreach ($value->members as $key => $member) {
                if (! in_array((string) $key, self::DROPPED, true)) {
                    $members[] = self::string((string) $key) . ':' . self::encode($member);
                }
            }

            return '{' . implode(',', $members) . '}';
        }
        if (is_array($value)) {
            return '[' . implode(',', array_map(self::encode(...), $value)) . ']';
        }

        return match (true) {
            is_string($value) => self::string($value),
            $value instanceof JsonNumber => $value->raw,
            $value === true => 'true',
            $value === false => 'false',
            default => 'null',
        };
    }

    public static function string(string $text): string
    {
        $out = '"';
        $length = strlen($text);
        for ($i = 0; $i < $length;) {
            $run = strcspn($text, '"\\' . self::CONTROL, $i);
            $out .= substr($text, $i, $run);
            $i += $run;
            if ($i >= $length) {
                break;
            }
            $char = $text[$i];
            $out .= match ($char) {
                '"' => '\\"',
                '\\' => '\\\\',
                "\x08" => '\\b',
                "\x0c" => '\\f',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                default => sprintf('\\u%04x', ord($char)),
            };
            $i++;
        }

        return $out . '"';
    }

    /**
     * @throws ContextAtlasException
     */
    private function value(int $depth): mixed
    {
        $char = $this->text[$this->at] ?? '';
        if ($char === '{' || $char === '[') {
            if ($depth > self::MAX_DEPTH) {
                $this->fail();
            }

            return $char === '{' ? $this->object($depth) : $this->array($depth);
        }
        if ($char === '"') {
            return $this->stringValue();
        }

        return $this->literal();
    }

    /**
     * @throws ContextAtlasException
     */
    private function object(int $depth): JsonMap
    {
        $this->at++;
        $this->space();
        $members = [];
        if (($this->text[$this->at] ?? '') === '}') {
            $this->at++;

            return new JsonMap($members);
        }
        while (true) {
            $this->space();
            if (($this->text[$this->at] ?? '') !== '"') {
                $this->fail();
            }
            $key = $this->stringValue();
            $this->space();
            $this->expect(':');
            $this->space();
            $members[$key] = $this->value($depth + 1);
            $this->space();
            if (($this->text[$this->at] ?? '') !== ',') {
                $this->expect('}');

                return new JsonMap($members);
            }
            $this->at++;
        }
    }

    /**
     * @return list<mixed>
     *
     * @throws ContextAtlasException
     */
    private function array(int $depth): array
    {
        $this->at++;
        $this->space();
        $items = [];
        if (($this->text[$this->at] ?? '') === ']') {
            $this->at++;

            return $items;
        }
        while (true) {
            $this->space();
            $items[] = $this->value($depth + 1);
            $this->space();
            if (($this->text[$this->at] ?? '') !== ',') {
                $this->expect(']');

                return $items;
            }
            $this->at++;
        }
    }

    /**
     * @throws ContextAtlasException
     */
    private function literal(): mixed
    {
        $start = $this->at;
        foreach (['true' => true, 'false' => false, 'null' => null] as $word => $value) {
            if (substr_compare($this->text, $word, $start, strlen($word)) === 0) {
                $this->at += strlen($word);

                return $value;
            }
        }
        $end = $this->numberEnd($start);
        if ($end === null) {
            $this->fail();
        }
        $this->at = $end;

        return new JsonNumber(substr($this->text, $start, $end - $start));
    }

    /**
     * The decoded string opening at the cursor; the cursor moves past its closing quote.
     *
     * @throws ContextAtlasException
     */
    private function stringValue(): string
    {
        $start = $this->at;
        $length = strlen($this->text);
        $i = $start + 1;
        $out = '';
        while ($i < $length) {
            $run = strcspn($this->text, '"\\' . self::CONTROL, $i);
            $out .= substr($this->text, $i, $run);
            $i += $run;
            $char = $this->text[$i] ?? '';
            if ($char === '"') {
                $this->at = $i + 1;

                return $out;
            }
            if ($char !== '\\') {
                break;
            }
            $escape = $this->escape($i);
            if ($escape === null) {
                break;
            }
            [$decoded, $i] = $escape;
            $out .= $decoded;
        }
        $this->at = $start;
        $this->fail();
    }

    /**
     * The text of the escape at `$at` and the offset after it, or null when it is not one.
     *
     * @return array{string, int}|null
     */
    private function escape(int $at): ?array
    {
        $next = $this->text[$at + 1] ?? '';
        $simple = ['"' => '"', '\\' => '\\', '/' => '/', 'b' => "\x08", 'f' => "\x0c", 'n' => "\n", 'r' => "\r", 't' => "\t"];
        if (isset($simple[$next])) {
            return [$simple[$next], $at + 2];
        }
        $code = $this->hex($at);
        if ($code === null) {
            return null;
        }
        if ($code >= 0xD800 && $code <= 0xDBFF) {
            $low = $this->hex($at + 6);
            if ($low !== null && $low >= 0xDC00 && $low <= 0xDFFF) {
                return [(string) mb_chr(0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00), 'UTF-8'), $at + 12];
            }
        }
        $surrogate = $code >= 0xD800 && $code <= 0xDFFF;

        return [$surrogate ? "\u{FFFD}" : (string) mb_chr($code, 'UTF-8'), $at + 6];
    }

    /**
     * The code unit of the `\uXXXX` escape at `$at`, or null when there is none.
     */
    private function hex(int $at): ?int
    {
        if (($this->text[$at] ?? '') !== '\\' || ($this->text[$at + 1] ?? '') !== 'u' || strspn($this->text, '0123456789abcdefABCDEF', $at + 2, 4) !== 4) {
            return null;
        }

        return (int) hexdec(substr($this->text, $at + 2, 4));
    }

    /**
     * The offset after the number starting at `$at` (the JsonOutline grammar), or null.
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
