<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tests\Support;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Chunker;
use Phox\ContextAtlas\Config;
use Phox\ContextAtlas\Document\Document;
use Phox\ContextAtlas\Index\Collection;
use Phox\ContextAtlas\Processor;
use Phox\ContextAtlas\Tabular\Grid;
use Phox\ContextAtlas\Tabular\TabularProfile;
use Phox\ContextAtlas\Exceptions\ContextAtlasException;
use Phox\ContextAtlas\Parsing\BlockListParser;
use Phox\ContextAtlas\Parsing\DocxParser;
use Phox\ContextAtlas\Parsing\Formats;
use Phox\ContextAtlas\Parsing\MarkdownParser;
use Phox\ContextAtlas\Parsing\PlainTextParser;
use Phox\ContextAtlas\Reranker;
use Phox\ContextAtlas\Retrieval\Passage;
use Phox\ContextAtlas\Retrieval\RankedPassage;
use Phox\TypeSafe\Testing\FakeAnswers;
use Phox\TypeSafe\Testing\FakeCall;
use Phox\TypeSafe\Testing\FakeTypeSafe;

/**
 * Runs one fixture case against the SDK's fake and records what crossed the
 * wire. A case scripts its answers by question name; each call is answered
 * with the scripted values for the questions it asked.
 */
final class FixtureRunner
{
    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    public static function record(array $case): array
    {
        if ($case['kind'] === 'config' || $case['kind'] === 'blocks') {
            return ['name' => $case['name'], 'kind' => $case['kind'], 'input' => $case['input'], 'expected' => self::parsed($case)];
        }
        if ($case['kind'] === 'read') {
            return ['name' => $case['name'], 'kind' => 'read', 'input' => $case['input'], 'expected' => self::read($case['input'])];
        }
        if ($case['kind'] === 'markup-ingest') {
            return ['name' => $case['name'], 'kind' => 'markup-ingest', 'input' => $case['input'], 'expected' => self::markup($case['input'])->toArray()];
        }
        if ($case['kind'] === 'ingest') {
            return ['name' => $case['name'], 'kind' => 'ingest', 'input' => $case['input'], 'expected' => self::spreadsheet($case['input'])->toArray()];
        }

        $fake = FakeTypeSafe::make();
        $responses = [];

        if ($case['fail'] ?? false) {
            $fake->fail(500, ['error' => 'scripted failure'], times: 100);
        } else {
            $fake->alwaysReply(static function (FakeCall $call) use ($case, &$responses): array {
                $body = self::answers($call, (array) ($case['answers'] ?? []))->toArray($call);
                if (($body['answers'] ?? null) === []) {
                    $body['answers'] = (object) [];
                }
                $responses[] = $body;

                return $body;
            });
        }

        $output = match ($case['kind']) {
            'retrieve' => self::retrieve($case, $fake),
            'prose-ingest' => self::prose($case['input'], ($case['jev'] ?? true) ? $fake : null)->toArray(),
            'prose-retrieve' => self::proseRetrieve($case, $fake),
            'prose-append' => self::proseAppend($case['input'], $fake)->toArray(),
            default => self::run($case, $fake),
        };
        $calls = [];

        foreach ($fake->systemOneCalls() as $index => $call) {
            $body = $call->body();
            $calls[] = [
                'request' => ['state' => $body['state'], 'questions' => $body['questions']],
                'response' => $responses[$index] ?? null,
            ];
        }

        return array_filter([
            'name' => $case['name'],
            'kind' => $case['kind'],
            'format' => $case['format'] ?? null,
            'input' => $case['input'] ?? null,
            'query' => $case['query'] ?? null,
            'passages' => $case['passages'] ?? null,
            'documents' => $case['documents'] ?? null,
            'scope' => $case['scope'] ?? null,
            'nearest' => $case['nearest'] ?? null,
            'limit' => $case['limit'] ?? null,
            'jev' => $case['jev'] ?? true,
            'config' => (object) ($case['config'] ?? []),
            'fail' => $case['fail'] ?? false,
            'calls' => $calls,
        ], static fn (mixed $value): bool => $value !== null) + ['expected' => $output];
    }

    /**
     * @param  array<string, mixed>  $input  {id, blocks, config?, profile?}
     */
    private static function prose(array $input, ?FakeTypeSafe $fake): Document
    {
        $blocks = array_map(static fn (array $block): Block => Block::fromArray($block), (array) $input['blocks']);
        $profile = isset($input['profile']) ? (string) $input['profile'] : null;

        return (new Processor($fake?->client(), Config::fromArray((array) ($input['config'] ?? []))))->ingestBlocks($blocks, (string) $input['id'], $profile);
    }

    /**
     * @param  array<string, mixed>  $input  {id, blocks, append: list<list<block>>, config?, profile?}
     */
    private static function proseAppend(array $input, FakeTypeSafe $fake): Document
    {
        $processor = new Processor($fake->client(), Config::fromArray((array) ($input['config'] ?? [])));
        $profile = isset($input['profile']) ? (string) $input['profile'] : null;
        $document = self::prose($input, $fake);
        foreach ((array) $input['append'] as $blocks) {
            $document = $processor->append($document, array_map(static fn (array $block): Block => Block::fromArray($block), (array) $blocks), $profile);
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $input  {id, markup, format, title?}
     */
    private static function markup(array $input): Document
    {
        return (new Processor())->ingestMarkup((string) $input['markup'], (string) $input['id'], (string) $input['format'], isset($input['title']) ? (string) $input['title'] : null);
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>|null
     */
    private static function proseRetrieve(array $case, FakeTypeSafe $fake): ?array
    {
        $collection = new Collection(array_map(static fn (array $input): Document => isset($input['markup']) ? self::markup($input) : self::prose($input, $fake), $case['documents']));
        // A vector search that returns the case's hits, best first, whatever the query.
        $nearest = isset($case['nearest']) ? static fn (string $query, int $limit, ?array $kinds, ?array $documents): array => $case['nearest'] : null;

        return (new Processor($fake->client(), Config::fromArray((array) ($case['config'] ?? []))))->retrieve($case['query'], $collection, $nearest, $case['scope'] ?? null)?->toArray();
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>|null
     */
    private static function retrieve(array $case, FakeTypeSafe $fake): ?array
    {
        $collection = new Collection(array_map(self::spreadsheet(...), $case['documents']));

        return (new Processor($fake->client()))->retrieve($case['query'], $collection, documents: $case['scope'] ?? null)?->toArray();
    }

    /**
     * @param  array{id: string, title: string, sheets: array<string, array<string, string>>}  $input
     */
    public static function spreadsheet(array $input): Document
    {
        $sheets = [];

        foreach ($input['sheets'] as $name => $source) {
            $grid = match (true) {
                isset($source['xlsx']) => Grid::xlsx(__DIR__ . '/../../../' . $source['xlsx']),
                isset($source['csv_base64']) => Grid::csv((string) base64_decode($source['csv_base64'], true), $name),
                default => Grid::csv($source['csv'], $name),
            };
            array_push($sheets, ...$grid->sheets);
        }

        return TabularProfile::ingest(new Grid($sheets), $input['id'], $input['title']);
    }

    /**
     * A document format read into blocks, or the error every package must give.
     *
     * @param  array{format: string, text?: string, file?: string}  $input  `file`, for docx, is a path from the repository root
     * @return array<string, mixed>
     */
    private static function read(array $input): array
    {
        try {
            $blocks = isset($input['file']) ? DocxParser::parse(__DIR__ . '/../../../' . $input['file']) : Formats::blocks($input['text'] ?? '', $input['format']);

            return ['blocks' => array_map(static fn (Block $block): array => $block->toArray(), $blocks)];
        } catch (ContextAtlasException $exception) {
            return ['error' => $exception->getMessage()];
        }
    }

    /**
     * Config and block-list cases record the parsed result, or the error
     * message every package must give.
     *
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private static function parsed(array $case): array
    {
        try {
            return $case['kind'] === 'config'
                ? ['values' => Config::fromArray($case['input'])->toArray()]
                : ['blocks' => array_map(static fn (Block $block): array => $block->toArray(), BlockListParser::parse($case['input']))];
        } catch (ContextAtlasException $exception) {
            return ['error' => $exception->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $case
     */
    private static function run(array $case, FakeTypeSafe $fake): mixed
    {
        $config = Config::fromArray((array) ($case['config'] ?? []));

        if ($case['kind'] === 'rank') {
            $ranked = (new Reranker($fake->client(), $config))->rank(
                array_map(static fn (array $passage): Passage => Passage::fromArray($passage), $case['passages']),
                $case['query'],
                $case['limit'] ?? null,
            );

            return $ranked === null ? null : array_map(static fn (RankedPassage $item): array => $item->toArray(), $ranked);
        }

        $blocks = match ($case['format']) {
            'markdown' => MarkdownParser::parse($case['input']),
            'text' => PlainTextParser::parse($case['input']),
            default => array_map(static fn (array $block): Block => Block::fromArray($block), $case['input']),
        };

        return (new Chunker(($case['jev'] ?? true) ? $fake->client() : null, $config))->chunk($blocks)->toArray();
    }

    /**
     * @param  array<string, float|string>  $scripted
     */
    private static function answers(FakeCall $call, array $scripted): FakeAnswers
    {
        $answers = FakeAnswers::make()->only()->model('jev-latest');

        foreach (array_keys($call->questions()) as $name) {
            if (! array_key_exists($name, $scripted)) {
                continue;
            }
            $value = $scripted[$name];
            if (is_string($value)) {
                $answers->choice($name, $value);
            } else {
                $answers->noul($name, (float) $value);
            }
        }

        return $answers;
    }
}
