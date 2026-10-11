<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Prose;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Chunker;
use Phox\ContextAtlas\Chunking\Breadcrumbs;
use Phox\ContextAtlas\Config;
use Phox\ContextAtlas\Document\Document;
use Phox\ContextAtlas\Document\Unit;
use Phox\ContextAtlas\Exceptions\ContextAtlasException;
use Phox\ContextAtlas\Index\Lexical;
use Closure;
use Phox\ContextAtlas\Jev\JevCaller;
use Phox\ContextAtlas\Text;
use Phox\TypeSafe\Client;
use Phox\TypeSafe\Requests\SystemOne;
use Phox\TypeSafe\Responses\SystemOneResponse;

/**
 * Ingests prose: chunks it, gives each passage its section's profile, and builds the links
 * those profiles use. Without Jev every passage is guidance with no links.
 */
final readonly class ProseProfile
{
    private ?JevCaller $caller;

    /**
     * @param  Client|Closure(Closure(SystemOne): SystemOne): ?SystemOneResponse|null  $jev
     */
    public function __construct(
        private Client|Closure|null $jev,
        private Config $config,
    ) {
        $this->caller = $jev === null ? null : new JevCaller($jev);
    }

    /**
     * @param  array<int, Block>  $blocks
     *
     * @throws ContextAtlasException
     */
    public function ingest(array $blocks, string $id, ?string $profile = null): Document
    {
        self::checkProfile($profile);

        if ($profile === null || $profile === Profiles::CONVERSATIONAL) {
            $blocks = Speakers::infer(array_values($blocks));
        }
        $chunked = (new Chunker($this->jev, $this->config))->chunk(self::foldAdmonitions($blocks), $profile === null || $profile === Profiles::CONVERSATIONAL);
        $depth = Detector::depth($chunked->chunks);
        $crumbs = Breadcrumbs::of(array_values($blocks));
        [$bySection, $detected] = $this->profiles($chunked->chunks, $profile, self::speaking(array_values($blocks), $crumbs, $depth));

        $units = [];
        $built = [];
        $conversations = [];
        foreach ($chunked->chunks as $i => $chunk) {
            $key = Detector::key($chunk->headingPath, $depth);
            if (! in_array($bySection[$key], [Profiles::REFERENCE, Profiles::CONVERSATIONAL], true)) {
                $units[] = new Unit('p' . ($i + 1), 'passage', $chunk->text, $chunk->headingPath, ['blocks' => $chunk->blocks, 'profile' => $bySection[$key]]);
            } elseif (! isset($built[$key])) {
                $built[$key] = true;
                $section = self::section(array_values($blocks), $crumbs, $key, $depth);
                if ($bySection[$key] === Profiles::REFERENCE) {
                    array_push($units, ...self::entries($section, count($units)));
                } else {
                    $conversation = (new Conversation($this->caller, $this->config->charsPerToken, $this->config->conversationReplies, $this->config->conversationOutcomes, $this->config->conversationCorrections))->build($section, count($units));
                    array_push($units, ...$conversation['units']);
                    array_push($conversations, ...$conversation['links']);
                }
            }
        }

        $links = [...$this->links($units), ...$conversations];

        return new Document($id, $detected['profile'], $units, $links, Lexical::build($units), $detected, ['outline' => $chunked->outline, 'sections' => (object) $bySection]);
    }

    /**
     * @throws ContextAtlasException
     */
    public static function checkProfile(?string $profile): void
    {
        if ($profile !== null && (! Profiles::exists($profile) || $profile === Profiles::MARKUP)) {
            throw new ContextAtlasException("Unknown prose profile \"{$profile}\"; use one of: " . implode(', ', array_keys(array_diff_key(Profiles::DESCRIPTIONS, [Profiles::MARKUP => true]))) . '.');
        }
    }

    /**
     * @param  array<int, Block>  $blocks
     *
     * @throws ContextAtlasException
     */
    public function append(Document $document, array $blocks, ?string $profile = null): Document
    {
        return (new Appender($this, $this->caller, $this->config))->append($document, $blocks, $profile);
    }

    /**
     * A section's non-empty blocks with their heading paths, keyed by block index.
     *
     * @param  list<Block>  $blocks
     * @param  list<array{path: list<string>, section: int, level: int|null}>  $crumbs
     * @return array<int, array{block: Block, path: list<string>}>
     */
    private static function section(array $blocks, array $crumbs, string $key, int $depth): array
    {
        $section = [];
        foreach ($blocks as $i => $block) {
            if (Detector::key($crumbs[$i]['path'], $depth) === $key && Text::trim($block->text) !== '') {
                $section[$i] = ['block' => $block, 'path' => $crumbs[$i]['path']];
            }
        }

        return $section;
    }

    /**
     * The keys of sections where blocks that name their speaker hold at least half the text of
     * the section's non-heading blocks: a debate page's vote lists and glossary, under a heading
     * with one stray speaker, stay prose.
     *
     * @param  list<Block>  $blocks
     * @param  list<array{path: list<string>, section: int, level: int|null}>  $crumbs
     * @return array<string, true>
     */
    private static function speaking(array $blocks, array $crumbs, int $depth): array
    {
        $spoken = [];
        $total = [];
        foreach ($blocks as $i => $block) {
            if ($block->isHeading()) {
                continue;
            }
            $key = Detector::key($crumbs[$i]['path'], $depth);
            $length = mb_strlen(Text::trim($block->text));
            $total[$key] = ($total[$key] ?? 0) + $length;
            if (is_string($block->meta['speaker'] ?? null) && $block->meta['speaker'] !== '') {
                $spoken[$key] = ($spoken[$key] ?? 0) + $length;
            }
        }

        return array_map(static fn (): bool => true, array_filter($spoken, static fn (int $length, string|int $key): bool => $length > 0 && 2 * $length >= $total[$key], ARRAY_FILTER_USE_BOTH));
    }

    /**
     * The entries of one reference section, numbered on from the units before them.
     *
     * @param  array<int, array{block: Block, path: list<string>}>  $section
     * @return list<Unit>
     */
    private static function entries(array $section, int $offset): array
    {
        $units = [];
        foreach (Entries::build(array_values($section)) as $n => $entry) {
            $prefix = $entry['kind'] === 'row' || $entry['path'] === [] ? '' : '[' . implode(' > ', $entry['path']) . "]\n\n";
            $origin = array_keys($section);
            $units[] = new Unit('e' . ($offset + $n + 1), 'entry', $prefix . $entry['text'], $entry['path'], [
                'name' => $entry['name'],
                'row' => $entry['kind'] === 'row',
                'group' => $entry['group'] ?? null,
                'fields' => array_map(static fn (string|int $field, array $values): array => ['field' => (string) $field, 'values' => $values], array_keys($entry['fields']), array_values($entry['fields'])),
                'blocks' => array_map(static fn (int $b): int => $origin[$b], $entry['blocks']),
                'profile' => Profiles::REFERENCE,
            ]);
        }

        return $units;
    }

    /**
     * A heading such as "Note" or "Warning" titles an aside, not a section: it becomes a
     * paragraph, so the aside stays with the section it belongs to.
     *
     * @param  array<int, Block>  $blocks
     * @return array<int, Block>
     */
    private static function foldAdmonitions(array $blocks): array
    {
        return array_map(static fn (Block $block): Block => $block->isHeading() && preg_match('/^(note|tip|warning|caution|important|hint|info)\s*:?$/i', Text::trim($block->text)) === 1
            ? Block::paragraph($block->text, $block->meta)
            : $block, $blocks);
    }

    /**
     * Section key => profile, and what the document as a whole was detected as. A section whose
     * blocks name speakers is conversational without asking Jev.
     *
     * @param  list<\Phox\ContextAtlas\Chunk>  $chunks
     * @param  array<string, true>  $speaking
     * @return array{array<string, string>, array{profile: string, score: float|null, by: string}}
     */
    private function profiles(array $chunks, ?string $profile, array $speaking): array
    {
        $titles = array_map('strval', array_keys(Detector::sections($chunks)));

        if ($profile !== null) {
            return [array_fill_keys($titles, $profile), ['profile' => $profile, 'score' => null, 'by' => 'caller']];
        }

        $sections = array_fill_keys($titles, Profiles::GUIDANCE);
        $scores = [];
        $detected = $this->caller !== null && array_diff_key($sections, $speaking) !== [] ? (new Detector($this->caller))->detect($chunks) : null;
        if ($detected !== null) {
            ['sections' => $sections, 'scores' => $scores] = $detected;
        }
        foreach (array_keys($speaking) as $key) {
            if (isset($sections[$key])) {
                $sections[$key] = Profiles::CONVERSATIONAL;
                unset($scores[$key]);
            }
        }

        $depth = Detector::depth($chunks);
        $weights = [];
        foreach ($chunks as $chunk) {
            $section = $sections[Detector::key($chunk->headingPath, $depth)];
            $weights[$section] = ($weights[$section] ?? 0) + $chunk->tokens;
        }
        arsort($weights);
        $main = (string) array_key_first($weights);
        $mainScores = array_filter($scores, static fn (string $title): bool => $sections[$title] === $main, ARRAY_FILTER_USE_KEY);
        $by = match (true) {
            $main === Profiles::CONVERSATIONAL => 'speakers',
            $detected !== null => 'jev',
            default => 'default',
        };

        return [$sections, ['profile' => $main, 'score' => $mainScores === [] ? null : max($mainScores), 'by' => $by]];
    }

    /**
     * @param  list<Unit>  $units
     * @return list<\Phox\ContextAtlas\Document\Link>
     */
    private function links(array $units): array
    {
        $builder = new Links($this->caller);
        $types = [];
        foreach ($units as $unit) {
            foreach (Profiles::LINKS[$unit->meta['profile']] as $type) {
                $types[$type][] = $unit;
            }
        }

        return [
            ...isset($types['needs']) ? $builder->needs($types['needs']) : [],
            ...isset($types['defined_by']) ? $builder->definitions($types['defined_by']) : [],
            ...isset($types['refers_to']) ? Links::references($types['refers_to']) : [],
        ];
    }
}
