<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing\Chat;

/**
 * A decoded JSON object: its members in source order, a repeated key keeping its first place
 * and its last value.
 */
final readonly class JsonMap
{
    /**
     * @param  array<array-key, mixed>  $members
     */
    public function __construct(
        public array $members,
    ) {}

    public function get(string $key): mixed
    {
        return $this->members[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->members);
    }

    /**
     * The member as a string when it is a non-empty string, else null.
     */
    public function string(string $key): ?string
    {
        $value = $this->members[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function map(string $key): ?self
    {
        $value = $this->members[$key] ?? null;

        return $value instanceof self ? $value : null;
    }

    /**
     * @return list<mixed>|null
     */
    public function list(string $key): ?array
    {
        $value = $this->members[$key] ?? null;

        return is_array($value) && array_is_list($value) ? $value : null;
    }
}
