<?php

declare(strict_types=1);

namespace Phox\ContextAtlas;

/**
 * The chunks of a document, with its outline: every heading in order.
 */
final readonly class Chunked
{
    /**
     * @param  list<Chunk>  $chunks
     * @param  list<array{block: int, title: string, level: int}>  $outline
     */
    public function __construct(
        public array $chunks,
        public array $outline,
        public bool $usedJev,
    ) {}

    /**
     * @return array{chunks: list<array<string, mixed>>, outline: list<array{block: int, title: string, level: int}>, used_jev: bool}
     */
    public function toArray(): array
    {
        return [
            'chunks' => array_map(static fn (Chunk $chunk): array => $chunk->toArray(), $this->chunks),
            'outline' => $this->outline,
            'used_jev' => $this->usedJev,
        ];
    }
}
