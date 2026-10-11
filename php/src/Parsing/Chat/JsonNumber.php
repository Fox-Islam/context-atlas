<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing\Chat;

/**
 * A JSON number kept as written, so re-serialising it never reformats it.
 */
final readonly class JsonNumber
{
    public function __construct(
        public string $raw,
    ) {}
}
