<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing\Chat;

/**
 * One message, event or item of a transcript in vendor-neutral form: who sent it, its ids, and
 * its parts in order. A part is one of:
 * `['text', string]`, `['reasoning', string]`, `['media', string]` (the placeholder),
 * `['call', name, arguments, ?id]` and `['result', text, ?call id, ?tool name, error]`.
 * `$result` marks a tool message whose whole text is one result.
 */
final readonly class Message
{
    /**
     * @param  list<string>  $ids  every id the message can be named by, first the turn id
     * @param  list<array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}>  $parts
     */
    public function __construct(
        public string $role,
        public ?string $speakerFull,
        public array $ids,
        public ?string $parent,
        public ?string $time,
        public array $parts,
        public bool $result = false,
        public ?string $callId = null,
        public ?string $tool = null,
        public bool $error = false,
    ) {}
}
