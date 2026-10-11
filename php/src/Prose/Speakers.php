<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Prose;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\BlockType;
use Phox\ContextAtlas\Chunking\Breadcrumbs;
use Phox\ContextAtlas\Text;

/**
 * Finds conversations in documents whose reader cannot know who spoke: Markdown or HTML
 * minutes, transcripts and chat logs written as `Name: text`. Within a run of paragraphs under
 * one heading, when enough of them open with a speaker, each gets `meta.speaker`, so the section
 * becomes conversational.
 *
 * A prefix is an optional time (`[10:02]` or `10:02`), a name of one to four capitalised words
 * (lowercase JOINERS such as `of`, `the`, `de` and `van` may join them), an optional `(role)`,
 * then `:` and a space. A run qualifies with at least MIN_PARAGRAPHS paragraphs, MIN_SHARE of
 * them prefixed, and 2 different names. A paragraph without a prefix continues the speaker
 * before it. Words that label text instead of naming a speaker ("Note", "Example", "Step 2")
 * are not names.
 */
final class Speakers
{
    private const string JOINERS = 'of|for|the|and|de|du|des|del|della|di|da|do|dos|das|la|le|les|van|von|der|den|zu|y|e';

    /** a capitalised name (Latin, Greek, Cyrillic...): `Name:`, `Name (role):`, `Name :`, `Name：`, `M. Name. – ` */
    private const string CASED = '/^(?:\[?(\d{1,2}:\d{2}(?::\d{2})?)\]?\s+)?(\p{Lu}[\p{L}\p{M}\'’.-]*(?:\s+(?:(?:JOINERS)\s+)*\p{Lu}[\p{L}\p{M}\'’.-]*){0,3}(?:\s+(?:JOINERS)\s+\p{Ll}[\p{L}\p{M}\'’.-]*)?)(?:\s*[(（]([^()（）\n]{1,80})[)）])?(?:\s*:\s+|\s*：\s*|\.\s+[–—-]\s+)(\S.*)$/su';

    /** a name in a script without letter case, before `:` or `：`, or after a `○` marker before a space */
    private const string UNCASED = '/^(?:\[?(\d{1,2}:\d{2}(?::\d{2})?)\]?\s+)?(?:[○◯◎●]\s*)?(\p{Lo}{1,16})(?:\s*[(（]([^()（）\n]{1,80})[)）])?(?:\s*[:：]\s*|(?<=\p{Lo}|[)）])[\x{3000} ]+)(\S.*)$/su';

    public const array NOT_NAMES = ['note', 'notes', 'tip', 'warning', 'caution', 'important', 'example', 'examples', 'answer', 'question', 'summary', 'result', 'results', 'source', 'sources', 'see', 'definition', 'update', 'edit', 'info', 'hint', 'step', 'action', 'actions', 'decision', 'resolved', 'agreed', 'outcome', 'status', 'date', 'time', 'location', 'attendees', 'present', 'apologies', 'subject', 're', 'to', 'from', 'cc', 'title', 'author', 'version', 'q', 'a', 'remarque', 'nota', 'hinweis', 'anmerkung', 'beispiel', 'exemple', 'ejemplo', 'esempio', 'réponse', 'antwort', 'respuesta', 'risposta', 'résumé', 'zusammenfassung', 'resumen', 'quelle', 'fuente', 'fonte', 'attention', 'achtung', 'atención', 'attenzione', '注', '例', '注意', '備考'];

    private const int MIN_PARAGRAPHS = 4;

    private const float MIN_SHARE = 0.6;

    /**
     * @param  list<Block>  $blocks
     * @return list<Block>
     */
    public static function infer(array $blocks): array
    {
        $crumbs = Breadcrumbs::of($blocks);
        $runs = [];
        $current = null;
        foreach ($blocks as $i => $block) {
            if ($block->isHeading()) {
                $current = null;

                continue;
            }
            $key = implode("\x1f", $crumbs[$i]['path']);
            if ($current === null || $runs[$current]['key'] !== $key) {
                $runs[] = ['key' => $key, 'blocks' => []];
                $current = count($runs) - 1;
            }
            $runs[$current]['blocks'][] = $i;
        }

        $out = $blocks;
        foreach ($runs as $run) {
            foreach (self::run($blocks, $run['blocks']) as $i => $block) {
                $out[$i] = $block;
            }
        }

        return array_values($out);
    }

    /**
     * @param  list<Block>  $blocks
     * @param  list<int>  $indices
     * @return array<int, Block>
     */
    private static function run(array $blocks, array $indices): array
    {
        $paragraphs = array_values(array_filter($indices, static fn (int $i): bool => $blocks[$i]->type === BlockType::Paragraph && Text::trim($blocks[$i]->text) !== ''));
        foreach ($paragraphs as $i) {
            if (isset($blocks[$i]->meta['speaker'])) {
                return [];
            }
        }
        $prefixes = [];
        foreach ($paragraphs as $i) {
            $prefix = self::prefix($blocks[$i]->text);
            if ($prefix !== null) {
                $prefixes[$i] = $prefix;
            }
        }
        $names = array_unique(array_map(static fn (array $p): string => $p['speaker'], $prefixes));
        if (count($paragraphs) < self::MIN_PARAGRAPHS || count($prefixes) < self::MIN_SHARE * count($paragraphs) || count($names) < 2) {
            return [];
        }

        $out = [];
        $last = null;
        foreach ($paragraphs as $i) {
            $block = $blocks[$i];
            $prefix = $prefixes[$i] ?? null;
            if ($prefix !== null) {
                $last = $prefix;
                $meta = ['speaker' => $prefix['speaker']] + ($prefix['full'] !== $prefix['speaker'] ? ['speaker_full' => $prefix['full']] : []) + ($prefix['time'] !== null ? ['time' => $prefix['time']] : []);
                $out[$i] = Block::paragraph($prefix['text'], [...$block->meta, ...$meta]);
            } elseif ($last !== null) {
                $out[$i] = Block::paragraph($block->text, [...$block->meta, 'speaker' => $last['speaker']] + ($last['full'] !== $last['speaker'] ? ['speaker_full' => $last['full']] : []));
            }
        }

        return $out;
    }

    /**
     * A name in an uncased script needs a colon, or a `○`-style marker before it when only a space
     * follows it (as in Japanese Diet records), so ordinary sentences do not read as speakers.
     *
     * @param  array<int, string>|null  $match
     * @param-out array<int, string>  $match
     */
    private static function uncased(string $text, ?array &$match): bool
    {
        if (preg_match(self::UNCASED, $text, $found) !== 1) {
            return false;
        }
        $colon = preg_match('/^(?:\[?\d{1,2}:\d{2}(?::\d{2})?\]?\s+)?(?:[○◯◎●]\s*)?\p{Lo}{1,16}(?:\s*[(（][^()（）\n]{1,80}[)）])?\s*[:：]/u', $text) === 1;
        if (! $colon && preg_match('/^(?:\[?\d{1,2}:\d{2}(?::\d{2})?\]?\s+)?[○◯◎●]/u', $text) !== 1) {
            return false;
        }
        $match = $found;

        return true;
    }

    /**
     * @return array{speaker: string, full: string, time: string|null, text: string}|null
     */
    private static function prefix(string $text): ?array
    {
        $text = Text::trim($text);
        $cased = str_replace('JOINERS', self::JOINERS, self::CASED);
        if (preg_match($cased, $text, $match) !== 1 && ! self::uncased($text, $match)) {
            return null;
        }
        $name = $match[2];
        $first = mb_strtolower(explode(' ', (string) preg_replace('/\s+/u', ' ', $name))[0]);
        if (in_array(mb_strtolower($name), self::NOT_NAMES, true) || in_array($first, self::NOT_NAMES, true) || mb_strlen($name) < 2 || preg_match('/\d/u', $name) === 1) {
            return null;
        }
        $role = $match[3] !== '' ? Text::trim($match[3]) : null;

        return ['speaker' => $name, 'full' => $role === null ? $name : "{$name} ({$role})", 'time' => $match[1] !== '' ? $match[1] : null, 'text' => Text::trim($match[4])];
    }
}
