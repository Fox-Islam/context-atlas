<?php

declare(strict_types=1);

namespace Phox\ContextAtlas;

use Closure;
use Phox\ContextAtlas\Document\Document;
use Phox\ContextAtlas\Document\Unit;
use Phox\ContextAtlas\Exceptions\ContextAtlasException;
use Phox\ContextAtlas\Index\Lexical;
use Phox\ContextAtlas\Index\Source;
use Phox\ContextAtlas\Jev\JevCaller;
use Phox\ContextAtlas\Markup\MarkupProfile;
use Phox\ContextAtlas\Parsing\ChatParser;
use Phox\ContextAtlas\Parsing\DocxParser;
use Phox\ContextAtlas\Parsing\Formats;
use Phox\ContextAtlas\Prose\ProseProfile;
use Phox\ContextAtlas\Prose\Retriever as ProseRetriever;
use Phox\ContextAtlas\Tabular\Executor;
use Phox\ContextAtlas\Tabular\Grid;
use Phox\ContextAtlas\Tabular\Planner;
use Phox\ContextAtlas\Tabular\TabularProfile;
use Phox\TypeSafe\Client;
use Phox\TypeSafe\Requests\SystemOne;
use Phox\TypeSafe\Responses\SystemOneResponse;

/**
 * Ingests documents into storable artifacts, and retrieves context for a question from them.
 *
 * ```php
 * $processor = new Processor(new Client());
 * $store->put($processor->ingestSpreadsheet('spend.csv', 'dft-spend-2026-03'));
 * $context = $processor->retrieve('How much went to Network Rail?', $store);
 * ```
 *
 * The caller stores what ingest returns and passes a Source back at retrieval; nothing is
 * written anywhere by the library.
 */
final readonly class Processor
{
    private const int CANDIDATES = 20;

    private const int MAX_TABLES = 12;

    private const int FUSION_K = 60;

    private const string SUPERSEDED = '(A later message corrects this one.)';

    /** @var Client|Closure(Closure(SystemOne): SystemOne): ?SystemOneResponse|null */
    private Client|Closure|null $jev;

    /**
     * @param  Client|Closure(Closure(SystemOne): SystemOne): ?SystemOneResponse|null  $jev
     */
    public function __construct(
        Client|Closure|null $jev = null,
        private Config $config = new Config(),
    ) {
        $this->jev = $jev;
    }

    /**
     * Reads text in any format `Parsing\Formats` lists: a document format becomes blocks for the
     * prose profiles (`$profile` as in `ingestBlocks`), a data format a key tree for the markup
     * profile, and CSV a table. Text that is not valid UTF-8 is read as Windows-1252. XLSX is
     * binary: use `ingestFile` or `ingestSpreadsheet`; DOCX too: use `ingestFile`.
     *
     * @throws ContextAtlasException
     */
    public function ingest(string $text, string $id, string $format, ?string $profile = null, ?string $title = null): Document
    {
        $text = mb_check_encoding($text, 'UTF-8') ? $text : (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');

        return match (true) {
            in_array($format, Formats::DATA, true) => $this->ingestMarkup($text, $id, $format, $title),
            $format === 'csv' => $this->ingestCsv($text, $id, $title ?? $id),
            $format === 'xlsx' => throw new ContextAtlasException('XLSX is read from a file: use ingestFile or ingestSpreadsheet.'),
            $format === 'docx' => throw new ContextAtlasException('DOCX is read from a file: use ingestFile.'),
            default => $this->ingestBlocks(Formats::blocks($text, $format), $id, $profile),
        };
    }

    /**
     * Reads a file in the format its extension names (`Formats::EXTENSIONS`).
     *
     * @throws ContextAtlasException
     */
    public function ingestFile(string $path, string $id, ?string $profile = null, ?string $title = null): Document
    {
        $format = Formats::of($path) ?? throw new ContextAtlasException('Cannot tell the format of ' . basename($path) . ' from its extension; call ingest() with a format.');

        $chat = in_array($format, ['json', 'jsonl'], true) ? self::chat(self::read($path)) : null;

        return match (true) {
            in_array($format, Formats::SPREADSHEET, true) => $this->ingestSpreadsheet($path, $id, $title),
            $format === 'docx' => $this->ingestBlocks(DocxParser::parse($path), $id, $profile),
            $chat !== null => $this->ingestBlocks($chat, $id, $profile),
            default => $this->ingest(self::read($path), $id, $format, $profile, $title),
        };
    }

    /**
     * The blocks of a JSON or JSON Lines file the chat reader reads into at least two messages:
     * a chat export saved as `.json` is a conversation, not data.
     *
     * @return list<Block>|null
     */
    private static function chat(string $text): ?array
    {
        try {
            $blocks = ChatParser::parse(mb_check_encoding($text, 'UTF-8') ? $text : (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252'));
        } catch (ContextAtlasException) {
            return null;
        }
        $messages = array_filter($blocks, static fn (Block $block): bool => is_string($block->meta['speaker'] ?? null) && ($block->meta['attachment'] ?? false) !== true);

        return count($messages) >= 2 ? $blocks : null;
    }

    /**
     * Reads CSV and XLSX files, chosen by extension, as one document, so lookup tables in one
     * file join onto data in another. Pass one path, or several keyed by the sheet name each
     * CSV should take; an XLSX file contributes its own sheets.
     *
     * @param  string|array<string, string>  $paths
     *
     * @throws ContextAtlasException
     */
    public function ingestSpreadsheet(string|array $paths, string $id, ?string $title = null): Document
    {
        $paths = is_string($paths) ? [pathinfo($paths, PATHINFO_FILENAME) => $paths] : $paths;
        $title ??= (string) array_key_first($paths);
        $sheets = [];

        foreach ($paths as $name => $path) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $grid = match ($extension) {
                'xlsx' => Grid::xlsx($path),
                'csv', 'txt' => Grid::csv(self::read($path), (string) $name),
                default => throw new ContextAtlasException("Cannot read .{$extension} as a spreadsheet; give a .csv or .xlsx file."),
            };
            array_push($sheets, ...$grid->sheets);
        }

        return TabularProfile::ingest(new Grid($sheets), $id, $title);
    }

    public function ingestCsv(string $csv, string $id, string $title): Document
    {
        return TabularProfile::ingest(Grid::csv($csv, $title), $id, $title);
    }

    /**
     * Reads a YAML, JSON, JSON Lines, XML or TOML file's text into path subtrees, linked where a
     * `$ref`, a YAML alias or an XML `id` reference points elsewhere in the file. `$format` is
     * `yaml`, `json`, `jsonl`, `xml` or `toml`. No Jev request.
     *
     * @throws ContextAtlasException
     */
    public function ingestMarkup(string $text, string $id, string $format, ?string $title = null): Document
    {
        return MarkupProfile::ingest($text, $id, $format, $title ?? $id, $this->config->charsPerToken);
    }

    /**
     * Ingests prose blocks: passages chunked by Chunker, each top-level section given a profile
     * (detected by Jev unless `$profile` names one for the whole document), and the links those
     * profiles use.
     *
     * @param  array<int, Block>  $blocks
     *
     * @throws ContextAtlasException
     */
    public function ingestBlocks(array $blocks, string $id, ?string $profile = null): Document
    {
        return (new ProseProfile($this->jev, $this->config))->ingest($blocks, $id, $profile);
    }

    /**
     * Adds blocks to the end of a prose document ingested earlier, asking Jev only about the new
     * blocks: turns that continue the conversation the document ends in link back to its
     * messages, and other blocks are ingested as prose and numbered after the document's units.
     *
     * @param  array<int, Block>  $blocks
     *
     * @throws ContextAtlasException
     */
    public function append(Document $document, array $blocks, ?string $profile = null): Document
    {
        return (new ProseProfile($this->jev, $this->config))->append($document, $blocks, $profile);
    }

    /**
     * Gathers the context that answers a question.
     *
     * Candidates come from exact-term search over the source, fused with the caller's vector
     * search when `$nearest` is given: `fn (string $query, int $limit, ?list<string> $kinds,
     * ?list<string> $documents): list<array{document: string, unit: string}>`, best first, where
     * the closure's `$documents` (when not null) asks for hits from those documents only. This
     * method's `$documents` limits the search to those documents, and makes every table in them
     * a candidate. Tables go to the tabular planner; passages to the prose retriever.
     *
     * @param  list<string>|null  $documents
     * @return Context|null null when Jev did not answer
     */
    public function retrieve(string $query, Source $source, ?Closure $nearest = null, ?array $documents = null): ?Context
    {
        $jev = $this->jev ?? throw new ContextAtlasException('Retrieval needs Jev: pass a Client or a closure to the Processor.');

        $candidates = $this->candidates($query, $source, $nearest, $documents);
        $tableCandidates = $documents === null ? $candidates : self::everyUnit($source, $documents);
        $tables = array_slice(array_values(array_filter($tableCandidates, static fn (array $c): bool => $c['unit']->kind === 'table')), 0, self::MAX_TABLES);
        $passages = array_values(array_filter($candidates, static fn (array $c): bool => $c['unit']->kind !== 'table'));

        if ($tables !== []) {
            $context = $this->fromTables($query, $tables, $jev);
            if ($context === null || ! $context->abstained || $passages === []) {
                return $context;
            }
        }

        return $passages === [] ? Context::abstain(['reason' => 'no candidates']) : $this->fromPassages($query, $passages, $jev);
    }

    /**
     * @param  list<array{document: Document, unit: Unit}>  $tables
     * @param  Client|Closure(Closure(SystemOne): SystemOne): ?SystemOneResponse  $jev
     */
    private function fromTables(string $query, array $tables, Client|Closure $jev): ?Context
    {
        $byId = [];
        $data = [];
        foreach ($tables as $candidate) {
            $table = TabularProfile::table($candidate['document']->data['tables'][$candidate['unit']->meta['table']]);
            $key = $candidate['document']->id . "\0" . $table['id'];
            $byId[$key] = $candidate;
            $data[] = ['id' => $key] + $table;
        }

        $planned = (new Planner(new JevCaller($jev)))->plan($query, array_map(static fn (array $t): array => ['id' => $t['id']] + $t, $data));

        if ($planned === null) {
            return null;
        }
        if ($planned['table'] === null) {
            return Context::abstain($planned['trace']);
        }

        $candidate = $byId[$planned['table']['id']];
        $table = TabularProfile::table($candidate['document']->data['tables'][$candidate['unit']->meta['table']]);
        $result = $planned['plan'] === null ? Executor::meaning($table) : Executor::run($table, $planned['plan']);
        $trace = ['table' => $candidate['document']->id . '#' . $table['id']] + $planned['trace'];

        return new Context($result['text'], [['document' => $candidate['document']->id, 'unit' => $candidate['unit']->id, 'rows' => $result['sources']]], false, $trace);
    }

    /**
     * @param  list<array{document: Document, unit: Unit}>  $passages
     * @param  Client|Closure(Closure(SystemOne): SystemOne): ?SystemOneResponse  $jev
     */
    private function fromPassages(string $query, array $passages, Client|Closure $jev): ?Context
    {
        $result = (new ProseRetriever(new JevCaller($jev), $this->config))->retrieve($query, $passages);

        if ($result === null) {
            return null;
        }
        if ($result['units'] === []) {
            return Context::abstain(['reason' => 'no passage answers the question'] + $result['trace']);
        }
        [$units, $supersededBy] = self::withCorrections($result['units']);

        return new Context(
            implode("\n\n", array_map(static fn (array $c): string => $c['unit']->text . (isset($supersededBy[ProseRetriever::key($c)]) ? "\n" . self::SUPERSEDED : ''), $units)),
            array_map(static fn (array $c): array => ['document' => $c['document']->id, 'unit' => $c['unit']->id] + (isset($supersededBy[ProseRetriever::key($c)]) ? ['superseded_by' => $supersededBy[ProseRetriever::key($c)]] : []), $units),
            false,
            $result['trace'],
        );
    }

    /**
     * Each kept unit that a later unit `corrects` brings the last unit of that chain, the one no
     * other unit corrects, and is marked superseded by it.
     *
     * @param  list<array{document: Document, unit: Unit}>  $units
     * @return array{list<array{document: Document, unit: Unit}>, array<string, string>} the units in document order, and the superseded units' keys => the latest unit's id
     */
    private static function withCorrections(array $units): array
    {
        $added = [];
        $supersededBy = [];
        foreach ($units as $candidate) {
            $latest = self::latestCorrection($candidate['document'], $candidate['unit']->id);
            if ($latest !== null) {
                $supersededBy[ProseRetriever::key($candidate)] = $latest->id;
                $added[] = ['document' => $candidate['document'], 'unit' => $latest];
            }
        }

        return [ProseRetriever::inDocumentOrder(ProseRetriever::unique([...$units, ...$added])), $supersededBy];
    }

    /**
     * Follows `corrects` links from a unit to the units that correct it, taking the latest
     * corrector in document order at each step, until no unit corrects the last one.
     */
    private static function latestCorrection(Document $document, string $id): ?Unit
    {
        $order = array_flip(array_map(static fn (Unit $u): string => $u->id, $document->units));
        $latest = null;
        $seen = [$id => true];
        while (true) {
            $next = null;
            foreach ($document->links as $link) {
                if ($link->type === 'corrects' && $link->to === $id && isset($order[$link->from]) && ! isset($seen[$link->from]) && ($next === null || $order[$link->from] > $order[$next])) {
                    $next = $link->from;
                }
            }
            if ($next === null) {
                return $latest;
            }
            $seen[$next] = true;
            $id = $next;
            $latest = $document->unit($next);
        }
    }

    /**
     * @throws ContextAtlasException
     */
    private static function read(string $path): string
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        return $contents === false ? throw new ContextAtlasException("Cannot read {$path}.") : $contents;
    }

    /**
     * Exact-term hits fused with vector hits by reciprocal rank, limited to `$documents` when
     * given.
     *
     * @param  list<string>|null  $documents
     * @return list<array{document: Document, unit: Unit}>
     */
    private function candidates(string $query, Source $source, ?Closure $nearest, ?array $documents): array
    {
        $limit = $documents === null ? self::CANDIDATES : self::CANDIDATES * 10;
        $lists = [array_map(static fn (array $hit): array => ['document' => $hit['document'], 'unit' => $hit['unit']], Lexical::search($source, $query, $limit, null, $documents))];
        if ($nearest !== null) {
            $lists[] = array_values((array) $nearest($query, $limit, null, $documents));
        }
        if ($documents !== null) {
            $lists = array_map(static fn (array $list): array => array_values(array_filter($list, static fn (array $hit): bool => in_array($hit['document'], $documents, true))), $lists);
        }

        $scores = [];
        foreach ($lists as $list) {
            foreach ($list as $rank => $hit) {
                $key = $hit['document'] . "\0" . $hit['unit'];
                $scores[$key] = ($scores[$key] ?? 0.0) + 1 / (self::FUSION_K + $rank + 1);
            }
        }
        arsort($scores);

        $out = [];
        foreach (array_slice(array_keys($scores), 0, self::CANDIDATES) as $key) {
            [$documentId, $unitId] = explode("\0", (string) $key, 2);
            $document = $source->document($documentId);
            $unit = $document?->unit($unitId);
            if ($document !== null && $unit !== null) {
                $out[] = ['document' => $document, 'unit' => $unit];
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $documents
     * @return list<array{document: Document, unit: Unit}>
     */
    private static function everyUnit(Source $source, array $documents): array
    {
        $out = [];

        foreach ($documents as $id) {
            $document = $source->document($id);
            if ($document === null) {
                continue;
            }
            foreach ($document->units as $unit) {
                $out[] = ['document' => $document, 'unit' => $unit];
            }
        }

        return $out;
    }
}
