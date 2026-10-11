<?php

declare(strict_types=1);

namespace Phox\ContextAtlas;

/**
 * What retrieval hands to the LLM: the text to put in its context, where each part came from,
 * and how it was chosen. `abstained` means the source does not hold what the question needs.
 */
final readonly class Context
{
    /**
     * @param  list<array{document: string, unit: string, rows?: list<string>}>  $sources
     * @param  array<string, mixed>  $trace
     */
    public function __construct(
        public string $text,
        public array $sources,
        public bool $abstained,
        public array $trace = [],
    ) {}

    /**
     * @param  array<string, mixed>  $trace
     */
    public static function abstain(array $trace = []): self
    {
        return new self('', [], true, $trace);
    }

    /**
     * @return array{text: string, sources: list<array<string, mixed>>, abstained: bool, trace: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['text' => $this->text, 'sources' => $this->sources, 'abstained' => $this->abstained, 'trace' => $this->trace];
    }
}
