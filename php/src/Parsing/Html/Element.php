<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing\Html;

/**
 * An element of the tree Tree builds: a lowercase name, its attributes (lowercase names,
 * decoded values, the first of a repeated name kept) and its children, text as strings.
 */
final class Element
{
    /** @var list<Element|string> */
    public array $children = [];

    /**
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly array $attributes = [],
    ) {}

    public function attribute(string $name): ?string
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * The attribute's value trimmed of ASCII whitespace and lowercased (ASCII), or null.
     */
    public function token(string $name): ?string
    {
        $value = $this->attributes[$name] ?? null;

        return $value === null ? null : strtolower(trim($value, " \t\n\f\r"));
    }

    /**
     * The attribute's value split at runs of ASCII whitespace, case kept.
     *
     * @return list<string>
     */
    public function tokens(string $name): array
    {
        return array_values(array_filter(explode(' ', strtr($this->attributes[$name] ?? '', "\t\n\f\r", '    ')), static fn (string $token): bool => $token !== ''));
    }

    public function append(Element|string $child): void
    {
        $last = count($this->children) - 1;

        if (is_string($child) && $last >= 0 && is_string($this->children[$last])) {
            $this->children[$last] .= $child;

            return;
        }

        $this->children[] = $child;
    }
}
