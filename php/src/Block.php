<?php

declare(strict_types=1);

namespace Phox\ContextAtlas;

use Phox\ContextAtlas\Exceptions\ContextAtlasException;

/**
 * One unit of a document: a heading, a paragraph, a table or a code block.
 *
 * A paragraph is the only kind split into sentences when it is too long; a
 * table or a code block is kept whole however long it runs. `meta` is carried
 * through untouched, so a caller can map chunks back to pages or offsets.
 */
final readonly class Block
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public BlockType $type,
        public string $text,
        public ?int $level = null,
        public array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function heading(string $text, int $level, array $meta = []): self
    {
        return new self(BlockType::Heading, $text, $level, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function paragraph(string $text, array $meta = []): self
    {
        return new self(BlockType::Paragraph, $text, null, $meta);
    }

    /**
     * @param  array<string, mixed>  $value  {"type", "text", "level"?, "meta"?}
     *
     * @throws ContextAtlasException
     */
    public static function fromArray(array $value): self
    {
        $type = BlockType::tryFrom((string) ($value['type'] ?? ''));

        if ($type === null || ! is_string($value['text'] ?? null)) {
            throw new ContextAtlasException('A block needs a "type" (heading, paragraph, table or code) and a "text".');
        }
        if ($type === BlockType::Heading && ! is_int($value['level'] ?? null)) {
            throw new ContextAtlasException('A heading block needs an integer "level".');
        }

        return new self($type, $value['text'], $type === BlockType::Heading ? $value['level'] : null, (array) ($value['meta'] ?? []));
    }

    public function isHeading(): bool
    {
        return $this->type === BlockType::Heading;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter(
            ['type' => $this->type->value, 'text' => $this->text, 'level' => $this->level, 'meta' => $this->meta],
            static fn (mixed $value): bool => $value !== null && $value !== [],
        );
    }
}
