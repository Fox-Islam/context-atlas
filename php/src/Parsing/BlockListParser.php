<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use JsonException;
use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Exceptions\ContextAtlasException;

/**
 * Blocks a caller built itself, as JSON: a list of
 * `{"type", "text", "level"?, "meta"?}`. This is how output from a PDF or HTML
 * extractor comes in.
 */
final class BlockListParser
{
    /**
     * @return list<Block>
     *
     * @throws ContextAtlasException
     */
    public static function parse(string $json): array
    {
        try {
            $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ContextAtlasException('The blocks are not valid JSON.', 0, $exception);
        }

        if (! is_array($value)) {
            throw new ContextAtlasException('The blocks must be a JSON list.');
        }

        return array_values(array_map(static fn (mixed $block): Block => Block::fromArray(is_object($block) ? self::toArray($block) : []), $value));
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(object $value): array
    {
        $array = json_decode((string) json_encode($value), true);

        return is_array($array) ? $array : [];
    }
}
