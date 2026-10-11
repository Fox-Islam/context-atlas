<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\BlockType;
use Phox\ContextAtlas\Text;

/**
 * Reads the block structure of Markdown: ATX and setext headings, fenced code,
 * pipe tables, lists, block quotes and paragraphs. Inline markup is left in
 * the text. The rules are stated in spec/behaviour.md, so the JavaScript and
 * Python packages split a document identically.
 */
final class MarkdownParser
{
    private const string FENCE = '/^ {0,3}(`{3,}|~{3,})/';

    private const string ATX = '/^ {0,3}(#{1,6})[ \t]+(.+?)(?:[ \t]+#+)?[ \t]*$/';

    private const string SETEXT = '/^ {0,3}(=+|-+)[ \t]*$/';

    private const string BREAK = '/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/';

    private const string TABLE = '/^ {0,3}\|/';

    private const string LIST_ITEM = '/^ {0,3}(?:[-*+]|\d{1,9}[.)])[ \t]+/';

    private const string QUOTE = '/^ {0,3}>[ \t]?/';

    /** @var list<Block> */
    private array $blocks = [];

    /** @var list<string> */
    private array $lines = [];

    private BlockType $open = BlockType::Paragraph;

    private bool $list = false;

    /**
     * @return list<Block>
     */
    public static function parse(string $markdown): array
    {
        return (new self())->read(self::withoutFrontMatter(explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown))));
    }

    /**
     * @param  list<string>  $lines
     * @return list<Block>
     */
    private function read(array $lines): array
    {
        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];

            if (preg_match(self::FENCE, $line, $fence) === 1) {
                $this->close();
                $i = $this->fenced($lines, $i, $fence[1]);
            } else {
                $this->line($line);
            }
        }

        $this->close();

        return $this->blocks;
    }

    private function line(string $line): void
    {
        $atx = [];
        $setext = [];

        if (Text::trim($line) === '') {
            $this->close();
        } elseif (preg_match(self::ATX, $line, $atx) === 1) {
            $this->close();
            $this->blocks[] = Block::heading(Text::trim($atx[2]), mb_strlen($atx[1]));
        } elseif ($this->open === BlockType::Paragraph && ! $this->list && $this->lines !== [] && preg_match(self::SETEXT, $line, $setext) === 1) {
            $this->blocks[] = Block::heading(implode(' ', $this->lines), str_starts_with(ltrim($setext[1]), '=') ? 1 : 2);
            $this->lines = [];
        } elseif (preg_match(self::BREAK, $line) === 1) {
            $this->close();
        } else {
            $this->content($line);
        }
    }

    private function content(string $line): void
    {
        $isTable = preg_match(self::TABLE, $line) === 1;
        $isItem = preg_match(self::LIST_ITEM, $line) === 1;
        $type = $isTable ? BlockType::Table : BlockType::Paragraph;

        if ($this->lines !== [] && ($type !== $this->open || ($isItem && ! $this->list))) {
            $this->close();
        }

        $this->open = $type;
        $this->list = $this->list || $isItem;
        $this->lines[] = $isTable ? Text::trim($line) : Text::trim(preg_replace(self::QUOTE, '', $line) ?? $line);
    }

    /**
     * @param  list<string>  $lines
     */
    private function fenced(array $lines, int $start, string $fence): int
    {
        $body = [];
        $closer = '/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}[ \t]*$/';

        for ($i = $start + 1, $n = count($lines); $i < $n; $i++) {
            if (preg_match($closer, $lines[$i]) === 1) {
                break;
            }
            $body[] = $lines[$i];
        }

        $text = implode("\n", $body);
        if (Text::trim($text) !== '') {
            $this->blocks[] = new Block(BlockType::Code, $text);
        }

        return $i;
    }

    private function close(): void
    {
        if ($this->lines !== []) {
            $glue = $this->open === BlockType::Table || $this->list ? "\n" : ' ';
            $this->blocks[] = new Block($this->open, implode($glue, $this->lines));
        }

        $this->lines = [];
        $this->open = BlockType::Paragraph;
        $this->list = false;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function withoutFrontMatter(array $lines): array
    {
        if (($lines[0] ?? null) !== '---') {
            return $lines;
        }

        for ($i = 1, $n = count($lines); $i < $n; $i++) {
            if ($lines[$i] === '---' || $lines[$i] === '...') {
                return array_slice($lines, $i + 1);
            }
        }

        return $lines;
    }
}
