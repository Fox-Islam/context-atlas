<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Document;

use Phox\ContextAtlas\Exceptions\ContextAtlasException;

/**
 * Everything ingestion builds for one input document, as plain data the caller stores where it
 * likes and hands back at retrieval. The same JSON shape is read and written by the PHP,
 * JavaScript and Python packages; spec/document.schema.json describes it.
 */
final readonly class Document
{
    public const int FORMAT = 2;

    /**
     * @param  list<Unit>  $units
     * @param  list<Link>  $links
     * @param  array{length: array<string, int>, terms: array<string, array<string, int>>}  $lexical
     * @param  array{profile: string, score: float|null, by: string}|null  $detected
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $id,
        public string $profile,
        public array $units,
        public array $links = [],
        public array $lexical = ['length' => [], 'terms' => []],
        public ?array $detected = null,
        public array $data = [],
    ) {}

    /**
     * @param  array<string, mixed>  $value
     *
     * @throws ContextAtlasException
     */
    public static function fromArray(array $value): self
    {
        if (($value['format'] ?? null) !== self::FORMAT) {
            throw new ContextAtlasException('Not a format ' . self::FORMAT . ' document.');
        }

        return new self(
            (string) $value['id'],
            (string) $value['profile'],
            array_values(array_map(static fn (array $unit): Unit => Unit::fromArray($unit), (array) ($value['units'] ?? []))),
            array_values(array_map(static fn (array $link): Link => Link::fromArray($link), (array) ($value['links'] ?? []))),
            ['length' => (array) ($value['lexical']['length'] ?? []), 'terms' => (array) ($value['lexical']['terms'] ?? [])],
            self::detected($value['detected'] ?? null),
            (array) ($value['data'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'id' => $this->id,
            'profile' => $this->profile,
            'detected' => $this->detected,
            'units' => array_map(static fn (Unit $unit): array => $unit->toArray(), $this->units),
            'links' => array_map(static fn (Link $link): array => $link->toArray(), $this->links),
            'lexical' => ['length' => (object) $this->lexical['length'], 'terms' => (object) $this->lexical['terms']],
            'data' => (object) $this->data,
        ];
    }

    /**
     * @return array{profile: string, score: float|null, by: string}|null
     */
    private static function detected(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return ['profile' => (string) ($value['profile'] ?? ''), 'score' => isset($value['score']) ? (float) $value['score'] : null, 'by' => (string) ($value['by'] ?? '')];
    }

    public function unit(string $id): ?Unit
    {
        foreach ($this->units as $unit) {
            if ($unit->id === $id) {
                return $unit;
            }
        }

        return null;
    }
}
