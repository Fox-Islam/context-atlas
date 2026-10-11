<?php

declare(strict_types=1);

namespace Phox\ContextAtlas;

use Normalizer;

/**
 * The text rules the PHP, JavaScript and Python packages share. Lengths are in
 * Unicode code points; spec/behaviour.md states each rule for the other
 * implementations.
 */
final class Text
{
    private const string SENTENCE_END = '(?<=[.?!…])\s+';

    /**
     * Characters of Han, Hiragana, Katakana and Hangul count as a token each: those scripts take
     * about one token per character, where `chars_per_token` undercounts them three to five
     * times.
     */
    public static function tokens(string $text, int $charsPerToken): int
    {
        $wide = (int) preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text);

        return (int) ceil((mb_strlen($text) - $wide) / max(1, $charsPerToken) + $wide);
    }

    /**
     * Splits where a sentence ends: after `.`, `?`, `!` or `…`, at whitespace
     * followed by an uppercase letter.
     *
     * @return list<string>
     */
    public static function sentences(string $text): array
    {
        $text = self::trim($text);
        $parts = preg_split('/' . self::SENTENCE_END . '(?=\p{Lu})/u', $text);

        return $parts === false ? [$text] : $parts;
    }

    /**
     * The last `$tokens` tokens of a text, started at the first sentence that
     * begins inside them, or failing that at the first whole word, so an
     * overlap never opens mid-word.
     */
    public static function tail(string $text, int $tokens, int $charsPerToken): string
    {
        $chars = max(0, $tokens * $charsPerToken);

        if (mb_strlen($text) <= $chars) {
            return $text;
        }

        $slice = mb_substr($text, -$chars);

        if (preg_match('/' . self::SENTENCE_END . '(?=[\p{Lu}\p{Lt}])/u', $slice, $match, PREG_OFFSET_CAPTURE) === 1) {
            $start = mb_strlen(substr($slice, 0, $match[0][1])) + mb_strlen($match[0][0]);

            return mb_substr($slice, $start);
        }

        if (preg_match('/\s+/u', $slice, $match, PREG_OFFSET_CAPTURE) === 1) {
            return mb_substr($slice, mb_strlen(substr($slice, 0, $match[0][1])) + mb_strlen($match[0][0]));
        }

        return $slice;
    }

    public static function trim(string $text): string
    {
        return preg_replace('/^\s+|\s+$/u', '', $text) ?? $text;
    }

    public static function squash(string $text): string
    {
        return preg_replace('/\s+/u', ' ', self::trim($text)) ?? $text;
    }

    /**
     * Lowercase ASCII letters and digits joined by hyphens, accents dropped,
     * cut to `$length` characters.
     */
    public static function slug(string $text, int $length): string
    {
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_KD);
        $plain = preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $text : $decomposed) ?? $text;
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($plain)) ?? '', '-');

        return rtrim(mb_substr($slug, 0, $length), '-');
    }

    public static function head(string $text, int $chars): string
    {
        return mb_substr(self::trim($text), 0, $chars);
    }

    public static function end(string $text, int $chars): string
    {
        $text = self::trim($text);

        return mb_strlen($text) <= $chars ? $text : mb_substr($text, -$chars);
    }
}
