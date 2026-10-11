<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\BlockType;
use Phox\ContextAtlas\Text;

/**
 * Plain text: each run of lines between blank lines is a paragraph, its lines joined by single
 * spaces. Structure the layout shows is kept:
 *
 * - a one-line paragraph numbered like `2.5.3.  Encryption` or `Appendix A.  Notes` is a
 *   heading, its level the count of numbers (the appendix letter counts as one);
 * - a one-line paragraph at or left of the body's indent, of at most 60 characters, starting with a capital
 *   and not ending in `.`, `,`, `;` or `:`, is a level-1 heading when the paragraph after it is
 *   more than twice as long;
 * - a paragraph whose lines are all indented at least two spaces deeper than the body is code,
 *   its lines kept with their smallest indent removed. The body's indent is the indent of the
 *   paragraphs' first lines that holds the most text (the smallest on a tie), so RFC-style text
 *   indented by three spaces reads like flush text.
 */
final class PlainTextParser
{
    private const string NUMBERED = '/^((?:Appendix|Annex)\s+[A-Z]|\d+)((?:\.\d+)*)\.?\s+(\p{Lu}.{0,78})$/u';

    private const int SHORT = 60;

    /**
     * @return list<Block>
     */
    public static function parse(string $text): array
    {
        $text = (string) preg_replace('/^\x{FEFF}/u', '', str_replace(["\r\n", "\r", "\f"], ["\n", "\n", "\n"], $text));
        $chunks = array_values(array_filter(
            preg_split('/\n[ \t]*\n/', $text) ?: [],
            static fn (string $chunk): bool => Text::squash($chunk) !== '',
        ));
        $body = self::bodyIndent($chunks);
        $blocks = [];

        foreach ($chunks as $i => $chunk) {
            $lines = array_values(array_filter(explode("\n", $chunk), static fn (string $line): bool => trim($line) !== ''));
            $indents = array_map(self::indent(...), $lines);
            $paragraph = Text::squash($chunk);

            if ($indents !== [] && min($indents) >= $body + 2) {
                $dedent = min($indents);
                $blocks[] = new Block(BlockType::Code, implode("\n", array_map(static fn (string $line): string => rtrim(substr($line, $dedent)), $lines)));
            } elseif (count($lines) === 1 && preg_match(self::NUMBERED, $paragraph, $match) === 1 && preg_match('/[.,;:]$/', $match[3]) !== 1) {
                $blocks[] = Block::heading($paragraph, 1 + substr_count($match[2], '.'));
            } elseif (count($lines) === 1 && $indents[0] <= $body && self::isTitle($paragraph) && mb_strlen(Text::squash($chunks[$i + 1] ?? '')) > 2 * mb_strlen($paragraph)) {
                $blocks[] = Block::heading($paragraph, 1);
            } else {
                $blocks[] = Block::paragraph($paragraph);
            }
        }

        return $blocks;
    }

    /**
     * @param  list<string>  $chunks
     */
    private static function bodyIndent(array $chunks): int
    {
        $counts = [];
        foreach ($chunks as $chunk) {
            foreach (explode("\n", $chunk) as $line) {
                if (trim($line) !== '') {
                    $counts[self::indent($line)] = ($counts[self::indent($line)] ?? 0) + mb_strlen(Text::squash($chunk));
                    break;
                }
            }
        }
        ksort($counts);

        return $counts === [] ? 0 : (int) array_search(max($counts), $counts, true);
    }

    private static function indent(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, " \t"));
    }

    private static function isTitle(string $line): bool
    {
        return mb_strlen($line) <= self::SHORT && preg_match('/^\p{Lu}/u', $line) === 1 && preg_match('/[.,;:]$/', $line) !== 1;
    }
}
