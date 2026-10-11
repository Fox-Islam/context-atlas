<?php

declare(strict_types=1);

namespace Phox\ContextAtlas;

use Phox\ContextAtlas\Exceptions\ContextAtlasException;

/**
 * Every tunable number. Sizes are approximate tokens of `charsPerToken`
 * Unicode code points each; spec/config.schema.json describes each key.
 */
final readonly class Config
{
    private const array KEYS = [
        'max_tokens' => 'maxTokens',
        'min_tokens' => 'minTokens',
        'overlap_tokens' => 'overlapTokens',
        'topic_min_tokens' => 'topicMinTokens',
        'split_long_paragraph_at' => 'splitLongParagraphAt',
        'chars_per_token' => 'charsPerToken',
        'seam_threshold' => 'seamThreshold',
        'refers_back_weight' => 'refersBackWeight',
        'refers_back_protect' => 'refersBackProtect',
        'snippet_chars' => 'snippetChars',
        'batch_size' => 'batchSize',
        'max_pairs' => 'maxPairs',
        'rank_threshold' => 'rankThreshold',
        'passage_chars' => 'passageChars',
        'cross_check' => 'crossCheck',
        'cross_check_rescue' => 'crossCheckRescue',
        'conversation_replies' => 'conversationReplies',
        'conversation_outcomes' => 'conversationOutcomes',
        'conversation_corrections' => 'conversationCorrections',
    ];

    public function __construct(
        public int $maxTokens = 800,
        public int $minTokens = 200,
        public int $overlapTokens = 80,
        public int $topicMinTokens = 60,
        public int $splitLongParagraphAt = 900,
        public int $charsPerToken = 4,
        public float $seamThreshold = 0.5,
        public float $refersBackWeight = 0.4,
        public float $refersBackProtect = 0.5,
        public int $snippetChars = 240,
        public int $batchSize = 40,
        public int $maxPairs = 2000,
        public float $rankThreshold = 0.4,
        public int $passageChars = 2400,
        public bool $crossCheck = true,
        public bool $crossCheckRescue = false,
        public bool $conversationReplies = true,
        public bool $conversationOutcomes = true,
        public bool $conversationCorrections = true,
    ) {}

    /**
     * Builds a config from snake_case keys, checking each value against its
     * type and range in spec/config.schema.json. An integer key takes any
     * whole number, `500.0` included, because JavaScript cannot tell it from
     * `500`.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ContextAtlasException
     */
    public static function fromArray(array $values): self
    {
        $schema = self::schema();
        $arguments = [];

        foreach ($values as $key => $value) {
            if (! isset(self::KEYS[$key], $schema[$key])) {
                throw new ContextAtlasException("Unknown config key \"{$key}\".");
            }
            $arguments[self::KEYS[$key]] = self::checked((string) $key, $value, $schema[$key]);
        }

        return new self(...$arguments);
    }

    /**
     * @param  array<string, mixed>  $rule
     *
     * @throws ContextAtlasException
     */
    private static function checked(string $key, mixed $value, array $rule): int|float|bool
    {
        $type = $rule['type'] ?? null;

        if ($type === 'boolean') {
            return is_bool($value) ? $value : throw new ContextAtlasException("Config key \"{$key}\" must be true or false.");
        }

        $minimum = $rule['minimum'] ?? 0;
        $maximum = $rule['maximum'] ?? INF;
        $number = (is_int($value) || is_float($value)) && is_finite((float) $value)
            && ($type !== 'integer' || floor((float) $value) === (float) $value);

        if (! $number || $value < $minimum || $value > $maximum) {
            $range = $type === 'integer' ? "at least {$minimum}" : "between {$minimum} and {$maximum}";
            throw new ContextAtlasException("Config key \"{$key}\" must be " . ($type === 'integer' ? 'an integer' : 'a number') . " {$range}.");
        }

        return $type === 'integer' ? (int) $value : (float) $value;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function schema(): array
    {
        $schema = json_decode((string) file_get_contents(__DIR__ . '/../../spec/config.schema.json'), true);

        return is_array($schema) && is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
    }

    /**
     * @return array<string, int|float|bool>
     */
    public function toArray(): array
    {
        $values = [];

        foreach (self::KEYS as $key => $property) {
            $values[$key] = $this->{$property};
        }

        return $values;
    }
}
