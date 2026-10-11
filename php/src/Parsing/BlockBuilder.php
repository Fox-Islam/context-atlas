<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\BlockType;
use Phox\ContextAtlas\Text;

/**
 * Collects the blocks of a line-based markup reader (reST, AsciiDoc, Org): an open paragraph
 * or list gathers lines until it is closed, and headings, code and tables close it first. The
 * shared rules are stated in spec/formats/rst.md under "Output".
 */
final class BlockBuilder
{
    /** @var list<Block> */
    private array $blocks = [];

    /** @var list<string> */
    private array $lines = [];

    private bool $list = false;

    /**
     * Adds a paragraph line, closing an open list first.
     */
    public function text(string $line): void
    {
        if ($this->list) {
            $this->close();
        }
        $this->lines[] = Text::trim($line);
    }

    /**
     * Adds a list item, closing an open paragraph first.
     */
    public function item(string $line): void
    {
        if (! $this->list) {
            $this->close();
        }
        $this->list = true;
        $this->lines[] = Text::trim($line);
    }

    /**
     * Continues the last line or item, joined with a space.
     */
    public function extend(string $line): void
    {
        $line = Text::trim($line);
        if ($this->lines === []) {
            $this->lines[] = $line;

            return;
        }
        $last = array_pop($this->lines);
        $this->lines[] = $last === '' ? $line : ($line === '' ? $last : $last . ' ' . $line);
    }

    public function isOpen(): bool
    {
        return $this->lines !== [];
    }

    /**
     * Whether nothing has been emitted and no line is open.
     */
    public function isEmpty(): bool
    {
        return $this->blocks === [] && $this->lines === [];
    }

    public function inList(): bool
    {
        return $this->list && $this->lines !== [];
    }

    public function count(): int
    {
        return count($this->lines);
    }

    public function last(): ?string
    {
        return $this->lines === [] ? null : $this->lines[count($this->lines) - 1];
    }

    /**
     * Replaces the last line or item; an empty replacement removes it.
     */
    public function replaceLast(string $line): void
    {
        array_pop($this->lines);
        if ($line !== '') {
            $this->lines[] = $line;
        }
    }

    public function paragraph(string $text): void
    {
        $this->close();
        $text = Text::trim($text);
        if ($text !== '') {
            $this->blocks[] = Block::paragraph($text);
        }
    }

    public function heading(string $text, int $level): void
    {
        $this->close();
        $text = Text::trim($text);
        if ($text !== '') {
            $this->blocks[] = Block::heading($text, $level);
        }
    }

    /**
     * @param  list<string>  $lines
     */
    public function code(array $lines): void
    {
        $this->close();
        while ($lines !== [] && Text::trim($lines[0]) === '') {
            array_shift($lines);
        }
        while ($lines !== [] && Text::trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }
        if ($lines !== []) {
            $this->blocks[] = new Block(BlockType::Code, implode("\n", $lines));
        }
    }

    /**
     * @param  list<list<string>>  $headerRows  merged column by column into one header
     * @param  list<list<string>>  $rows
     */
    public function table(string $caption, array $headerRows, array $rows): void
    {
        $this->close();
        $caption = Text::squash($caption);
        $header = self::merge($headerRows);
        $rows = array_values(array_filter(
            array_map(static fn (array $row): array => array_map(Text::squash(...), $row), $rows),
            static fn (array $row): bool => implode('', $row) !== '',
        ));
        if ($header === [] && $rows === []) {
            return;
        }

        $lines = $caption !== '' ? ['Table: ' . $caption] : [];
        if ($header !== []) {
            $lines[] = implode(' | ', $header);
        }
        foreach ($rows as $row) {
            $lines[] = implode(' | ', $row);
        }

        $this->blocks[] = new Block(BlockType::Table, implode("\n", array_map(static fn (string $line): string => rtrim($line, ' '), $lines)), null, ['caption' => $caption, 'header' => $header, 'rows' => $rows]);
    }

    public function close(): void
    {
        $lines = array_values(array_filter($this->lines, static fn (string $line): bool => $line !== ''));
        if ($lines !== []) {
            $this->blocks[] = Block::paragraph(implode($this->list ? "\n" : ' ', $lines));
        }
        $this->lines = [];
        $this->list = false;
    }

    /**
     * @return list<Block>
     */
    public function blocks(): array
    {
        $this->close();

        return $this->blocks;
    }

    /**
     * Lines with the indentation they all share removed; blank lines do not count.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    public static function dedent(array $lines): array
    {
        $min = null;
        foreach ($lines as $line) {
            if (trim($line, " \t") !== '') {
                $min = min($min ?? PHP_INT_MAX, strspn($line, " \t"));
            }
        }

        return array_map(static fn (string $line): string => (string) substr($line, min($min ?? 0, strspn($line, " \t"))), $lines);
    }

    /**
     * Splits one line of delimited values: a field opening with `"` (after spaces) runs to the
     * next `"` not doubled, `""` inside it standing for `"`; other fields are trimmed.
     *
     * @return list<string>
     */
    public static function delimited(string $line, string $separator): array
    {
        $fields = [];
        $length = strlen($line);
        $i = 0;
        while (true) {
            while ($i < $length && ($line[$i] === ' ' || $line[$i] === "\t") && $line[$i] !== $separator) {
                $i++;
            }
            $field = '';
            if ($i < $length && $line[$i] === '"') {
                for ($i++; $i < $length; $i++) {
                    if ($line[$i] === '"' && ($line[$i + 1] ?? '') === '"') {
                        $field .= '"';
                        $i++;
                    } elseif ($line[$i] === '"') {
                        $i++;
                        break;
                    } else {
                        $field .= $line[$i];
                    }
                }
                $end = strpos($line, $separator, $i);
                $i = $end === false ? $length : $end;
            } else {
                $end = strpos($line, $separator, $i);
                $field = Text::trim(substr($line, $i, ($end === false ? $length : $end) - $i));
                $i = $end === false ? $length : $end;
            }
            $fields[] = $field;
            if ($i >= $length) {
                return $fields;
            }
            $i += strlen($separator);
        }
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<string>
     */
    private static function merge(array $rows): array
    {
        $header = [];
        foreach ($rows as $row) {
            foreach ($row as $c => $cell) {
                $cell = Text::squash($cell);
                $header[$c] = ($header[$c] ?? '') === '' ? $cell : ($cell === '' ? $header[$c] : $header[$c] . ' ' . $cell);
            }
        }

        return array_values($header);
    }
}
