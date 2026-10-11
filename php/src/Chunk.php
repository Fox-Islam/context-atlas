<?php

declare(strict_types=1);

namespace Phox\ContextAtlas;

/**
 * A passage ready to embed.
 *
 * `text` is what to embed and show: the heading path in brackets, any overlap
 * carried from the previous chunk, then the chunk's own text. `tokens` counts
 * all of it, `overlap` the carried part. `blocks` are the indexes of the input
 * blocks it was built from.
 */
final readonly class Chunk
{
    /**
     * @param  list<int>  $blocks
     * @param  list<string>  $headingPath
     */
    public function __construct(
        public string $text,
        public array $blocks,
        public array $headingPath,
        public ?int $headingLevel,
        public int $section,
        public int $tokens,
        public int $overlap,
        public bool $topicStart,
    ) {}

    /**
     * @return array{text: string, blocks: list<int>, heading_path: list<string>, heading_level: int|null, section: int, tokens: int, overlap: int, topic_start: bool}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'blocks' => $this->blocks,
            'heading_path' => $this->headingPath,
            'heading_level' => $this->headingLevel,
            'section' => $this->section,
            'tokens' => $this->tokens,
            'overlap' => $this->overlap,
            'topic_start' => $this->topicStart,
        ];
    }
}
