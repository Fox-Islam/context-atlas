<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Prose;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Config;
use Phox\ContextAtlas\Document\Document;
use Phox\ContextAtlas\Document\Link;
use Phox\ContextAtlas\Document\Unit;
use Phox\ContextAtlas\Exceptions\ContextAtlasException;
use Phox\ContextAtlas\Index\Lexical;
use Phox\ContextAtlas\Jev\JevCaller;
use Phox\ContextAtlas\Tabular\TabularProfile;
use Phox\ContextAtlas\Text;

/**
 * Adds blocks to the end of a prose document without rebuilding what it holds, so a growing
 * transcript costs Jev only for its new turns.
 *
 * When the document ends in a conversation and the new blocks open with speaker blocks holding
 * at least half the text before their first heading, those blocks continue that conversation:
 * their turns may reply to, settle or correct the existing messages. Every other new block is
 * ingested as prose on its own, its units and links renumbered to follow the document's.
 */
final readonly class Appender
{
    public function __construct(
        private ProseProfile $prose,
        private ?JevCaller $caller,
        private Config $config,
    ) {}

    /**
     * @param  array<int, Block>  $blocks
     *
     * @throws ContextAtlasException
     */
    public function append(Document $document, array $blocks, ?string $profile = null): Document
    {
        if (in_array($document->profile, [Profiles::MARKUP, TabularProfile::NAME], true)) {
            throw new ContextAtlasException("A {$document->profile} document cannot be appended to; ingest it again.");
        }
        ProseProfile::checkProfile($profile);
        $blocks = array_values($blocks);
        if ($profile === null || $profile === Profiles::CONVERSATIONAL) {
            $blocks = Speakers::infer($blocks);
        }
        $offset = self::lastNumber($document);
        $blockOffset = self::nextBlock($document);
        $tail = self::tail($document);
        $lead = self::lead($blocks);

        $units = [];
        $links = [];
        if ($tail !== [] && self::spoken(array_slice($blocks, 0, $lead))) {
            $path = $tail[count($tail) - 1]->path;
            $section = [];
            foreach (array_slice($blocks, 0, $lead) as $i => $block) {
                if (Text::trim($block->text) !== '') {
                    $section[$blockOffset + $i] = ['block' => $block, 'path' => $path];
                }
            }
            ['units' => $units, 'links' => $links] = (new Conversation($this->caller, $this->config->charsPerToken, $this->config->conversationReplies, $this->config->conversationOutcomes, $this->config->conversationCorrections))->build($section, $offset, $tail);
            $blockOffset += $lead;
            $blocks = array_slice($blocks, $lead);
        }

        $data = $document->data;
        if ($blocks !== []) {
            $added = $this->prose->ingest($blocks, $document->id, $profile);
            $shift = $offset + count($units);
            $units = [...$units, ...array_map(static fn (Unit $u): Unit => self::moved($u, $shift, $blockOffset), $added->units)];
            $links = [...$links, ...array_map(static fn (Link $l): Link => new Link($l->type, self::renumber($l->from, $shift), self::renumber($l->to, $shift)), $added->links)];
            $data = self::mergeData($data, $added->data, $blockOffset);
        }

        $all = [...$document->units, ...$units];

        return new Document($document->id, $document->profile, $all, [...$document->links, ...$links], Lexical::build($all), $document->detected, $data);
    }

    /**
     * The message and output units of the conversation the document ends in: those after the
     * last unit of another kind or path, or none when the last unit is not a message or output.
     *
     * @return list<Unit>
     */
    private static function tail(Document $document): array
    {
        $tail = [];
        for ($i = count($document->units) - 1; $i >= 0; $i--) {
            $unit = $document->units[$i];
            $last = $tail[0] ?? $unit;
            if (! in_array($unit->kind, ['message', 'output'], true) || $unit->path !== $last->path) {
                break;
            }
            array_unshift($tail, $unit);
        }

        return $tail;
    }

    /**
     * @param  list<Block>  $blocks
     */
    private static function lead(array $blocks): int
    {
        foreach ($blocks as $i => $block) {
            if ($block->isHeading()) {
                return $i;
            }
        }

        return count($blocks);
    }

    /**
     * Whether blocks naming a speaker hold at least half the text.
     *
     * @param  list<Block>  $blocks
     */
    private static function spoken(array $blocks): bool
    {
        $total = 0;
        $spoken = 0;
        foreach ($blocks as $block) {
            $length = mb_strlen(Text::trim($block->text));
            $total += $length;
            if (is_string($block->meta['speaker'] ?? null) && $block->meta['speaker'] !== '') {
                $spoken += $length;
            }
        }

        return $spoken > 0 && 2 * $spoken >= $total;
    }

    private static function lastNumber(Document $document): int
    {
        $last = 0;
        foreach ($document->units as $unit) {
            if (preg_match('/(\d+)$/', $unit->id, $match) === 1) {
                $last = max($last, (int) $match[1]);
            }
        }

        return $last;
    }

    private static function nextBlock(Document $document): int
    {
        $next = 0;
        foreach ($document->units as $unit) {
            foreach ((array) ($unit->meta['blocks'] ?? []) as $block) {
                $next = max($next, (int) $block + 1);
            }
        }
        foreach ((array) ($document->data['outline'] ?? []) as $heading) {
            $next = max($next, (int) ($heading['block'] ?? -1) + 1);
        }

        return $next;
    }

    private static function moved(Unit $unit, int $shift, int $blockOffset): Unit
    {
        $meta = $unit->meta;
        if (isset($meta['turn'])) {
            $meta['turn'] = self::renumber((string) $meta['turn'], $shift);
        }
        if (isset($meta['blocks'])) {
            $meta['blocks'] = array_map(static fn (int $b): int => $b + $blockOffset, $meta['blocks']);
        }

        return new Unit(self::renumber($unit->id, $shift), $unit->kind, $unit->text, $unit->path, $meta);
    }

    private static function renumber(string $id, int $shift): string
    {
        return (string) preg_replace_callback('/^([a-z]+)(\d+)$/', static fn (array $m): string => $m[1] . ((int) $m[2] + $shift), $id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $added
     * @return array<string, mixed>
     */
    private static function mergeData(array $data, array $added, int $blockOffset): array
    {
        $outline = array_map(static fn (array $h): array => ['block' => (int) $h['block'] + $blockOffset] + $h, (array) ($added['outline'] ?? []));

        return [
            ...$data,
            'outline' => [...(array) ($data['outline'] ?? []), ...$outline],
            'sections' => (object) array_replace((array) ($data['sections'] ?? []), (array) ($added['sections'] ?? [])),
        ];
    }
}
