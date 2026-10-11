<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Retrieval;

final readonly class RankedPassage
{
    public function __construct(
        public Passage $passage,
        public float $score,
    ) {}

    /**
     * @return array{id: string, score: float}
     */
    public function toArray(): array
    {
        return ['id' => $this->passage->id, 'score' => $this->score];
    }
}
