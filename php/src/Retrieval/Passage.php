<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Retrieval;

/**
 * A search hit to be ranked: the caller's id for it, its text, and the
 * heading path it sits under, which names it for Jev.
 */
final readonly class Passage
{
    /**
     * @param  list<string>  $headingPath
     */
    public function __construct(
        public string $id,
        public string $text,
        public array $headingPath = [],
    ) {}

    /**
     * @param  array<string, mixed>  $value  {"id", "text", "heading_path"?}
     */
    public static function fromArray(array $value): self
    {
        $path = $value['heading_path'] ?? [];

        return new self(
            (string) ($value['id'] ?? ''),
            (string) ($value['text'] ?? ''),
            is_array($path) ? array_values(array_map('strval', $path)) : [(string) $path],
        );
    }
}
