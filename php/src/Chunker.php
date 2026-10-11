<?php

declare(strict_types=1);

namespace Phox\ContextAtlas;

use Closure;
use Phox\ContextAtlas\Chunking\Breadcrumbs;
use Phox\ContextAtlas\Chunking\CutPlanner;
use Phox\ContextAtlas\Chunking\Packer;
use Phox\ContextAtlas\Chunking\SeamScorer;
use Phox\ContextAtlas\Chunking\TopicShifts;
use Phox\ContextAtlas\Jev\JevCaller;
use Phox\TypeSafe\Client;
use Phox\TypeSafe\Requests\SystemOne;
use Phox\TypeSafe\Responses\SystemOneResponse;

/**
 * Splits a document's blocks into chunks at headings, at size limits, and,
 * when given Jev, where the topic changes inside a section.
 *
 * Without Jev, or when Jev does not answer, the chunks are the structural ones:
 * headings and sizes only.
 *
 * ```php
 * $chunked = (new Chunker(new Client()))->chunk(MarkdownParser::parse($markdown));
 * ```
 */
final readonly class Chunker
{
    private ?JevCaller $jev;

    /**
     * @param  Client|Closure(Closure(SystemOne): SystemOne): ?SystemOneResponse|null  $jev
     */
    public function __construct(
        Client|Closure|null $jev = null,
        private Config $config = new Config(),
    ) {
        $this->jev = $jev === null ? null : new JevCaller($jev);
    }

    /**
     * @param  array<int, Block>  $blocks  chunk block indexes refer to these keys
     * @param  bool  $quietSpeech  ask nothing about stretches mostly made of blocks that name a speaker
     */
    public function chunk(array $blocks, bool $quietSpeech = false): Chunked
    {
        $kept = array_filter($blocks, static fn (Block $block): bool => Text::trim($block->text) !== '');
        $origin = array_keys($kept);
        $blocks = array_values($kept);
        $tokens = array_map(fn (Block $block): int => Text::tokens(Text::trim($block->text), $this->config->charsPerToken), $blocks);
        $crumbs = Breadcrumbs::of($blocks);
        $shifts = $this->shifts($blocks, $tokens, $crumbs, $quietSpeech);
        $chunks = (new Packer($blocks, $tokens, $crumbs, $shifts ?? ['blocks' => [], 'sentences' => []], $this->config))->pack();

        return new Chunked(
            array_map(static fn (Chunk $chunk): Chunk => self::withOrigin($chunk, $origin), $chunks),
            array_map(static fn (array $heading): array => ['block' => $origin[$heading['block']]] + $heading, self::outline($blocks)),
            $shifts !== null,
        );
    }

    /**
     * Points a chunk's block indexes back at the caller's input, which may
     * have held empty blocks this left out.
     *
     * @param  list<int>  $origin
     */
    private static function withOrigin(Chunk $chunk, array $origin): Chunk
    {
        return new Chunk(
            $chunk->text,
            array_map(static fn (int $index): int => $origin[$index], $chunk->blocks),
            $chunk->headingPath,
            $chunk->headingLevel,
            $chunk->section,
            $chunk->tokens,
            $chunk->overlap,
            $chunk->topicStart,
        );
    }

    /**
     * @param  list<Block>  $blocks
     * @param  list<int>  $tokens
     * @param  list<array{path: list<string>, section: int, level: int|null}>  $crumbs
     * @return array{blocks: array<int, true>, sentences: array<int, array<int, true>>}|null
     */
    private function shifts(array $blocks, array $tokens, array $crumbs, bool $quietSpeech): ?array
    {
        if ($this->jev === null) {
            return null;
        }

        $finder = new TopicShifts(new SeamScorer($this->jev, $this->config), new CutPlanner($this->config), $this->config);

        return $finder->find($blocks, $tokens, $crumbs, $quietSpeech);
    }

    /**
     * @param  list<Block>  $blocks
     * @return list<array{block: int, title: string, level: int}>
     */
    private static function outline(array $blocks): array
    {
        $outline = [];

        foreach ($blocks as $index => $block) {
            if ($block->isHeading()) {
                $outline[] = ['block' => $index, 'title' => Text::squash($block->text), 'level' => (int) $block->level];
            }
        }

        return $outline;
    }
}
