<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Exceptions\ContextAtlasException;

/**
 * The input formats and where each goes: document formats are read into blocks for the prose
 * profiles, data formats into a key tree for the markup profile, spreadsheets into tables.
 */
final class Formats
{
    /** @var list<string> formats read into blocks */
    public const array DOCUMENT = ['markdown', 'mdx', 'text', 'html', 'rst', 'asciidoc', 'org', 'latex', 'ipynb', 'vtt', 'srt', 'chat', 'docx', 'blocks'];

    /** @var list<string> formats read into a key tree */
    public const array DATA = ['yaml', 'json', 'jsonl', 'xml', 'toml'];

    /** @var list<string> formats read as tables */
    public const array SPREADSHEET = ['csv', 'xlsx'];

    /** @var array<string, string> file extension => format */
    public const array EXTENSIONS = [
        'md' => 'markdown',
        'markdown' => 'markdown',
        'mdx' => 'mdx',
        'txt' => 'text',
        'text' => 'text',
        'html' => 'html',
        'htm' => 'html',
        'xhtml' => 'html',
        'rst' => 'rst',
        'adoc' => 'asciidoc',
        'asciidoc' => 'asciidoc',
        'org' => 'org',
        'tex' => 'latex',
        'ipynb' => 'ipynb',
        'vtt' => 'vtt',
        'srt' => 'srt',
        'docx' => 'docx',
        'yaml' => 'yaml',
        'yml' => 'yaml',
        'json' => 'json',
        'jsonl' => 'jsonl',
        'ndjson' => 'jsonl',
        'xml' => 'xml',
        'toml' => 'toml',
        'csv' => 'csv',
        'xlsx' => 'xlsx',
    ];

    /**
     * The format a file's extension names, or null.
     */
    public static function of(string $path): ?string
    {
        return self::EXTENSIONS[mb_strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    }

    /**
     * @return list<Block>
     *
     * @throws ContextAtlasException
     */
    public static function blocks(string $text, string $format): array
    {
        return match ($format) {
            'markdown' => MarkdownParser::parse($text),
            'mdx' => MdxParser::parse($text),
            'text' => PlainTextParser::parse($text),
            'html' => HtmlParser::parse($text),
            'rst' => RstParser::parse($text),
            'asciidoc' => AsciidocParser::parse($text),
            'org' => OrgParser::parse($text),
            'latex' => LatexParser::parse($text),
            'ipynb' => NotebookParser::parse($text),
            'vtt' => VttParser::parse($text),
            'srt' => SrtParser::parse($text),
            'chat' => ChatParser::parse($text),
            'blocks' => BlockListParser::parse($text),
            'docx' => throw new ContextAtlasException('DOCX is read from a file: use ingestFile.'),
            default => throw new ContextAtlasException("\"{$format}\" is not a document format; use one of: " . implode(', ', self::DOCUMENT) . '.'),
        };
    }
}
