<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Markup;

use Phox\ContextAtlas\Document\Document;
use Phox\ContextAtlas\Exceptions\ContextAtlasException;
use Phox\ContextAtlas\Index\Lexical;
use Phox\ContextAtlas\Prose\Profiles;

final class MarkupProfile
{
    /**
     * @throws ContextAtlasException
     */
    public static function ingest(string $text, string $id, string $format, string $title, int $charsPerToken = 4): Document
    {
        $outline = match ($format) {
            'yaml' => YamlOutline::read($text),
            'json' => JsonOutline::read($text),
            'jsonl' => JsonlOutline::read($text),
            'xml' => XmlOutline::read($text),
            'toml' => TomlOutline::read($text),
            default => throw new ContextAtlasException("Cannot read \"{$format}\" as markup; use yaml, json, jsonl, xml or toml."),
        };
        ['units' => $units, 'links' => $links] = Subtrees::build($outline, $title, $charsPerToken);

        return new Document($id, Profiles::MARKUP, $units, $links, Lexical::build($units), ['profile' => Profiles::MARKUP, 'score' => null, 'by' => 'format'], ['format' => $format]);
    }
}
