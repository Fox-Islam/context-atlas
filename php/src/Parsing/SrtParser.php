<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use Phox\ContextAtlas\Block;

/**
 * Reads SubRip captions: one paragraph per utterance of each cue, with the speaker from a
 * leading `Name:` and the cue's start time in meta. A cue starts at each timing line, so a
 * missing index or a blank line inside a cue's text is read through. The rules are stated in
 * spec/formats/transcripts.md.
 */
final class SrtParser
{
    private const string TIMING = '/^[ \t]*(\d+):(\d{2}):(\d{2})[,.]\d{1,3}[ \t]*-->/';

    private const string INDEX = '/^[ \t]*\d+[ \t]*$/';

    /**
     * @return list<Block>
     */
    public static function parse(string $text): array
    {
        $lines = explode("\n", Captions::normalise($text));
        $captions = new Captions();
        $blocks = [];
        $time = null;
        $cue = [];

        foreach ($lines as $line) {
            if (preg_match(self::TIMING, $line, $match) !== 1) {
                $cue[] = $line;

                continue;
            }
            if ($cue !== [] && preg_match(self::INDEX, $cue[count($cue) - 1]) === 1) {
                array_pop($cue);
            }
            if ($time !== null) {
                array_push($blocks, ...self::cue($captions, $cue, $time));
            }
            $time = Captions::time($match[1], $match[2], $match[3]);
            $cue = [];
        }
        if ($time !== null) {
            array_push($blocks, ...self::cue($captions, $cue, $time));
        }

        return $blocks;
    }

    /**
     * @param  list<string>  $lines  the lines after a timing line, up to the next cue's index or timing line
     * @return list<Block>
     */
    private static function cue(Captions $captions, array $lines, string $time): array
    {
        $lines = array_map(static fn (string $line): string => (string) preg_replace('/<[^>]*>|\{\\\\[^}]*\}/', '', $line), $lines);

        return $captions->blocks($lines, $time);
    }
}
