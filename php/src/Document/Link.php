<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Document;

/**
 * A typed relation between two units of one document, such as a passage that uses a term
 * another defines.
 */
final readonly class Link
{
    public function __construct(
        public string $type,
        public string $from,
        public string $to,
    ) {}

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array $value): self
    {
        return new self((string) $value['type'], (string) $value['from'], (string) $value['to']);
    }

    /**
     * @return array{type: string, from: string, to: string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'from' => $this->from, 'to' => $this->to];
    }
}
