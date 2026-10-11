<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Index;

use Phox\ContextAtlas\Document\Document;

/**
 * Documents held in memory. Build it from stored artifacts with fromArray(), or add documents as
 * they are ingested.
 */
final class Collection implements Source
{
    /** @var array<string, Document> */
    private array $documents = [];

    /**
     * @param  iterable<Document>  $documents
     */
    public function __construct(iterable $documents = [])
    {
        foreach ($documents as $document) {
            $this->put($document);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $documents  artifacts as Document::toArray() wrote them
     */
    public static function fromArray(array $documents): self
    {
        return new self(array_map(static fn (array $document): Document => Document::fromArray($document), $documents));
    }

    public function put(Document $document): void
    {
        $this->documents[$document->id] = $document;
    }

    public function remove(string $id): void
    {
        unset($this->documents[$id]);
    }

    public function document(string $id): ?Document
    {
        return $this->documents[$id] ?? null;
    }

    /**
     * @return list<Document>
     */
    public function documents(): array
    {
        return array_values($this->documents);
    }

    public function postings(array $terms): array
    {
        $out = [];

        foreach ($terms as $term) {
            $out[$term] = [];
            foreach ($this->documents as $document) {
                foreach ($document->lexical['terms'][$term] ?? [] as $unit => $count) {
                    $out[$term][] = ['document' => $document->id, 'unit' => (string) $unit, 'count' => (int) $count, 'length' => (int) ($document->lexical['length'][$unit] ?? 0)];
                }
            }
        }

        return $out;
    }

    public function stats(): array
    {
        $units = 0;
        $length = 0;

        foreach ($this->documents as $document) {
            $units += count($document->lexical['length']);
            $length += array_sum($document->lexical['length']);
        }

        return ['units' => $units, 'length' => $length];
    }
}
