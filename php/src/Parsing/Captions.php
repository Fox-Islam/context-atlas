<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Prose\Speakers;
use Phox\ContextAtlas\Text;

/**
 * What WebVTT and SRT share: a cue's lines split into utterances at `- ` lines and `Name:`
 * lines, the speaker read from a leading `Name:` or carried over from the last named cue, and
 * the cue start written as hh:mm:ss. One instance reads one file. The rules are stated in
 * spec/formats/transcripts.md.
 */
final class Captions
{
    private const string NAME = '\p{Lu}[\p{L}\p{M}\'’.\-]*(?: \p{Lu}[\p{L}\p{M}\'’.\-]*){0,3}';

    private const string SPEAKER = '/^(' . self::NAME . ')(?: \(([^()]+)\))?:\s+(\S.*)$/u';

    private const string SPEAKER_LINE = '/^(' . self::NAME . ')(?: \([^()]+\))?:(?:\s|$)/u';

    /** @var array{speaker: string, speaker_full?: string}|null the speaker unnamed utterances take */
    private ?array $current = null;

    /**
     * Splits a cue's text lines (tags already removed) into utterances: a line starting `- `
     * starts one (without the dash), as does a line starting `Name:` after the first line.
     *
     * @param  list<string>  $lines
     * @return list<Block>
     */
    public function blocks(array $lines, string $time): array
    {
        $groups = [];
        $dashes = [];
        foreach ($lines as $line) {
            $line = Text::trim($line);
            if ($line === '') {
                continue;
            }
            $dash = preg_match('/^-\s+/u', $line) === 1;
            $line = $dash ? Text::trim((string) preg_replace('/^-\s+/u', '', $line)) : $line;
            if ($groups === [] || $dash || (preg_match(self::SPEAKER_LINE, $line, $match) === 1 && self::isName($match[1]))) {
                $groups[] = [];
                $dashes[] = $dash;
            }
            $groups[count($groups) - 1][] = $line;
        }

        $blocks = [];
        foreach ($groups as $g => $group) {
            $block = $this->utterance(Text::squash(implode(' ', $group)), $time, null, $dashes[$g]);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * A block for one utterance, meta {speaker?, speaker_full?, time}. The speaker is the voice
     * when given, else a leading `Name:` or `Name (role):`, else the current speaker; a dash
     * utterance without a name has no speaker and clears the current one.
     */
    public function utterance(string $text, string $time, ?string $voice = null, bool $dash = false): ?Block
    {
        if ($voice !== null && $voice !== '') {
            $this->current = ['speaker' => $voice];
        } elseif (preg_match(self::SPEAKER, $text, $match, PREG_UNMATCHED_AS_NULL) === 1 && self::isName((string) $match[1])) {
            $this->current = ['speaker' => (string) $match[1]];
            if ($match[2] !== null) {
                $this->current['speaker_full'] = $match[1] . ' (' . $match[2] . ')';
            }
            $text = (string) $match[3];
        } elseif ($dash) {
            $this->current = null;
        }

        return $text === '' ? null : Block::paragraph($text, [...($this->current ?? []), 'time' => $time]);
    }

    /**
     * hh:mm:ss from a timestamp's parts; the hours padded to two digits.
     */
    public static function time(?string $hours, string $minutes, string $seconds): string
    {
        return str_pad($hours ?? '0', 2, '0', STR_PAD_LEFT) . ':' . $minutes . ':' . $seconds;
    }

    /**
     * Line endings as `\n`, a leading byte order mark removed.
     */
    public static function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text;
    }

    /**
     * False for a name in Speakers::NOT_NAMES or starting with a word in it, compared in lower
     * case, and for a name with a digit.
     */
    private static function isName(string $name): bool
    {
        $name = mb_strtolower($name);
        $first = explode(' ', $name)[0];

        return ! in_array($name, Speakers::NOT_NAMES, true) && ! in_array($first, Speakers::NOT_NAMES, true) && preg_match('/\d/u', $name) !== 1;
    }
}
