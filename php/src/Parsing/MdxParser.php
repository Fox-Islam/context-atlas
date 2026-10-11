<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Text;

/**
 * Reads MDX as Markdown after blanking what is not prose: import and export statements, lines
 * that hold only a component tag, and `{/* ... *\/}` comments. Fenced code is left alone, except
 * that an `mdx-code-block` fence is unwrapped and its content read as MDX. The rules are stated
 * in spec/formats/mdx.md.
 */
final class MdxParser
{
    private const string FENCE = '/^ {0,3}(`{3,}|~{3,})[ \t]*([^ \t]*)/';

    private const string ESM = '/^(?:import|export)(?=[ \t{*])/';

    private const string TAG = '/^(?:<\/?[A-Z][A-Za-z0-9_.]*(?:[ \t][^<>]*)?\/?>|<\/?>)$/';

    private const string WRAPPED = '/^<([A-Z][A-Za-z0-9_.]*)(?:[ \t][^<>]*)?>(.*)<\/\1[ \t]*>$/';

    private const string ADMONITION = '/^:{3,}([A-Za-z][A-Za-z0-9_-]*)?(?:\[([^\]]*)\]|[ \t]+(.*))?$/';

    private const string HTML = '/<\/?[a-z][a-z0-9-]*(?:[ \t][^<>]*)?\/?>|<!--.*?-->/';

    private const string TAG_START = '/^<[A-Z][A-Za-z0-9_.]*(?:[ \t][^<>]*)?$/';

    /**
     * @return list<Block>
     */
    public static function parse(string $text): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $start = self::frontMatterEnd($lines);
        $out = array_slice($lines, 0, $start);
        $fence = null;
        $unwrap = null;
        $depth = 0;
        $tag = false;
        $comment = false;

        for ($i = $start, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];

            if ($fence !== null) {
                $fence = self::closes($line, $fence) ? null : $fence;
                $out[] = $line;

                continue;
            }
            if ($unwrap !== null && self::closes($line, $unwrap)) {
                $unwrap = null;
                $out[] = '';

                continue;
            }
            if ($depth > 0) {
                $depth = Text::trim($line) === '' ? 0 : $depth + self::balance($line);
                $out[] = '';

                continue;
            }
            if ($tag) {
                $tag = ! str_ends_with(Text::trim($line), '>');
                $out[] = '';

                continue;
            }

            if (! $comment && preg_match(self::FENCE, $line, $match) === 1) {
                if ($match[2] === 'mdx-code-block' && $unwrap === null) {
                    $unwrap = $match[1];
                    $out[] = '';
                } else {
                    $fence = $match[1];
                    $out[] = $line;
                }

                continue;
            }
            if (! $comment && preg_match(self::ESM, $line) === 1) {
                $depth = max(0, self::balance($line));
                $out[] = '';

                continue;
            }

            $kept = self::withoutComments($line, $comment);
            $trimmed = Text::trim($kept);
            if (preg_match(self::ADMONITION, $trimmed, $admonition, PREG_UNMATCHED_AS_NULL) === 1) {
                $title = Text::trim((string) ($admonition[2] ?? $admonition[3] ?? ''));
                array_push($out, ...($title === '' ? [''] : ['', $title, '']));
            } elseif (preg_match(self::WRAPPED, $trimmed, $wrapped) === 1) {
                array_push($out, '', self::withoutHtml($wrapped[2]), '');
            } elseif (preg_match(self::TAG, $trimmed) === 1 || $trimmed === '') {
                $out[] = '';
            } elseif (preg_match(self::TAG_START, $trimmed) === 1) {
                $tag = true;
                $out[] = '';
            } else {
                $kept = self::withoutHtml($kept);
                $out[] = Text::trim($kept) === '' ? '' : $kept;
            }
        }

        return MarkdownParser::parse(implode("\n", $out));
    }

    /**
     * @param  list<string>  $lines
     */
    private static function frontMatterEnd(array $lines): int
    {
        if ($lines[0] !== '---') {
            return 0;
        }
        for ($i = 1, $n = count($lines); $i < $n; $i++) {
            if ($lines[$i] === '---' || $lines[$i] === '...') {
                return $i + 1;
            }
        }

        return 0;
    }

    /**
     * The line without lowercase HTML tags and `<!-- -->` comments, outside backtick code spans.
     */
    private static function withoutHtml(string $line): string
    {
        $parts = preg_split('/(`[^`]*`)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$line];
        foreach ($parts as $i => $part) {
            $parts[$i] = $i % 2 === 0 ? (string) preg_replace(self::HTML, '', $part) : $part;
        }

        return implode('', $parts);
    }

    private static function closes(string $line, string $fence): bool
    {
        return preg_match('/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}[ \t]*$/', $line) === 1;
    }

    /**
     * Opening minus closing brackets of all three kinds.
     */
    private static function balance(string $line): int
    {
        return strlen((string) preg_replace('/[^{[(]/', '', $line)) - strlen((string) preg_replace('/[^}\])]/', '', $line));
    }

    /**
     * The line without its `{/* ... *\/}` comments; `$comment` carries an unclosed one to the
     * next line.
     */
    private static function withoutComments(string $line, bool &$comment): string
    {
        if ($comment) {
            if (preg_match('/\*\/[ \t]*\}/', $line, $end, PREG_OFFSET_CAPTURE) !== 1) {
                return '';
            }
            $line = substr($line, $end[0][1] + strlen($end[0][0]));
            $comment = false;
        }

        while (preg_match('/\{[ \t]*\/\*/', $line, $open, PREG_OFFSET_CAPTURE) === 1) {
            $from = $open[0][1];
            if (preg_match('/\*\/[ \t]*\}/', $line, $end, PREG_OFFSET_CAPTURE, $from + strlen($open[0][0])) !== 1) {
                $comment = true;

                return substr($line, 0, $from);
            }
            $line = substr($line, 0, $from) . substr($line, $end[0][1] + strlen($end[0][0]));
        }

        return $line;
    }
}
