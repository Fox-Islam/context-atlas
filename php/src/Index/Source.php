<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Index;

use Phox\ContextAtlas\Document\Document;

/**
 * Where retrieval reads ingested documents from. Collection holds them in memory; a caller with
 * a large collection implements this over its own table or key-value store, reading only the
 * terms and documents a query touches.
 */
interface Source
{
    /**
     * Every unit containing one of the terms, with its term count and length.
     *
     * @param  list<string>  $terms
     * @return array<string, list<array{document: string, unit: string, count: int, length: int}>> term => postings
     */
    public function postings(array $terms): array;

    /**
     * @return array{units: int, length: int} how many units the source holds, and their total length in terms
     */
    public function stats(): array;

    public function document(string $id): ?Document;
}
