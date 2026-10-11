<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Exceptions\ContextAtlasException;
use Phox\ContextAtlas\Text;

/**
 * Reads WebVTT captions: one paragraph per utterance of each cue, with the speaker from a
 * `<v Name>` voice span or a leading `Name:` and the cue's start time in meta. NOTE, STYLE and
 * REGION blocks are dropped. The rules are stated in spec/formats/transcripts.md.
 */
final class VttParser
{
    private const string TIMING = '/^[ \t]*(?:(\d+):)?(\d{2}):(\d{2})\.\d{3}[ \t]+-->[ \t]+/';

    private const string VOICE = '/<v(?:\.[^ \t\n\f.>]+)*(?:[ \t\n\f]+([^>]*))?>|<\/v>/';

    private const string ENTITY = '/&(amp|lt|gt|nbsp|lrm|rlm|quot|apos|#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6});/';

    private const array ENTITIES = ['amp' => '&', 'lt' => '<', 'gt' => '>', 'nbsp' => "\u{A0}", 'lrm' => '', 'rlm' => '', 'quot' => '"', 'apos' => "'"];

    /**
     * @return list<Block>
     *
     * @throws ContextAtlasException
     */
    public static function parse(string $text): array
    {
        $lines = explode("\n", Captions::normalise($text));
        if (preg_match('/^WEBVTT(?:[ \t].*)?$/', $lines[0]) !== 1) {
            throw new ContextAtlasException('A WebVTT file must start with a "WEBVTT" line.');
        }

        $captions = new Captions();
        $blocks = [];
        foreach (array_slice(self::groups($lines), 1) as $group) {
            array_push($blocks, ...self::cue($captions, $group));
        }

        return $blocks;
    }

    /**
     * @param  list<string>  $lines
     * @return list<list<string>> runs of lines between blank lines, also split before a timing line
     *                             that cannot belong to the run before it
     */
    private static function groups(array $lines): array
    {
        $groups = [];
        $group = [];
        foreach ($lines as $line) {
            if (Text::trim($line) !== '') {
                if (str_contains($line, '-->') && self::startsCue($group, $groups === [])) {
                    $groups[] = $group;
                    $group = [];
                }
                $group[] = $line;
            } elseif ($group !== []) {
                $groups[] = $group;
                $group = [];
            }
        }
        if ($group !== []) {
            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * Whether a line holding `-->` ends the open run: always in the header, otherwise unless the
     * run is a single line without `-->` (a cue identifier).
     *
     * @param  list<string>  $group
     */
    private static function startsCue(array $group, bool $header): bool
    {
        return $group !== [] && ($header || count($group) > 1 || str_contains($group[0], '-->'));
    }

    /**
     * @param  list<string>  $group
     * @return list<Block>
     */
    private static function cue(Captions $captions, array $group): array
    {
        if (preg_match('/^(?:NOTE|STYLE|REGION)(?:[ \t]|$)/', $group[0]) === 1) {
            return [];
        }
        $at = str_contains($group[0], '-->') ? 0 : (str_contains($group[1] ?? '', '-->') ? 1 : null);
        if ($at === null || preg_match(self::TIMING, $group[$at], $match) !== 1) {
            return [];
        }

        $time = Captions::time($match[1] === '' ? null : $match[1], $match[2], $match[3]);
        $blocks = [];
        foreach (self::voices(implode("\n", array_slice($group, $at + 1))) as [$speaker, $raw]) {
            $lines = explode("\n", self::decode((string) preg_replace('/<[^>]*>/', '', $raw)));
            if ($speaker === null) {
                array_push($blocks, ...$captions->blocks($lines, $time));
            } else {
                $block = $captions->utterance(Text::squash(implode(' ', $lines)), $time, $speaker);
                $blocks = $block === null ? $blocks : [...$blocks, $block];
            }
        }

        return $blocks;
    }

    /**
     * Splits cue text at `<v>` voice spans: text before the first and after a `</v>` has no voice.
     *
     * @return list<array{?string, string}>
     */
    private static function voices(string $text): array
    {
        preg_match_all(self::VOICE, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $segments = [];
        $speaker = null;
        $at = 0;

        foreach ($matches as $match) {
            $segments[] = [$speaker, substr($text, $at, (int) $match[0][1] - $at)];
            $at = (int) $match[0][1] + strlen((string) $match[0][0]);
            $speaker = $match[0][0] === '</v>' ? null : Text::squash(self::decode((string) ($match[1][0] ?? '')));
            $speaker = $speaker === '' ? null : $speaker;
        }
        $segments[] = [$speaker, substr($text, $at)];

        return $segments;
    }

    private static function decode(string $text): string
    {
        return (string) preg_replace_callback(self::ENTITY, static function (array $match): string {
            $name = $match[1];
            if ($name[0] !== '#') {
                return self::ENTITIES[$name];
            }
            $code = $name[1] === 'x' || $name[1] === 'X' ? (int) hexdec(substr($name, 2)) : (int) substr($name, 1);
            $valid = $code > 0 && $code <= 0x10FFFF && ($code < 0xD800 || $code > 0xDFFF);

            return $valid ? (string) mb_chr($code, 'UTF-8') : "\u{FFFD}";
        }, $text);
    }
}
