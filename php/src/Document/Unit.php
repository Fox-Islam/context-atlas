<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Document;

/**
 * One retrievable piece of a document: a passage, an entry, a turn, a table. `text` is what the
 * caller embeds and what retrieval returns; `path` locates it in the document.
 */
final readonly class Unit
{
    /**
     * @param  list<string>  $path
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $text,
        public array $path = [],
        public array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array $value): self
    {
        return new self(
            (string) $value['id'],
            (string) $value['kind'],
            (string) $value['text'],
            array_values(array_map('strval', (array) ($value['path'] ?? []))),
            (array) ($value['meta'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter(
            ['id' => $this->id, 'kind' => $this->kind, 'text' => $this->text, 'path' => $this->path, 'meta' => $this->meta],
            static fn (mixed $value): bool => $value !== [],
        );
    }
}
