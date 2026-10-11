<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Console;

use JsonException;
use Phox\ContextAtlas\Chunker;
use Phox\ContextAtlas\Config;
use Phox\ContextAtlas\Exceptions\ContextAtlasException;
use Phox\ContextAtlas\Index\FileStore;
use Phox\ContextAtlas\Parsing\BlockListParser;
use Phox\ContextAtlas\Parsing\Formats;
use Phox\ContextAtlas\Parsing\MarkdownParser;
use Phox\ContextAtlas\Parsing\PlainTextParser;
use Phox\ContextAtlas\Processor;
use Phox\ContextAtlas\Reranker;
use Phox\ContextAtlas\Retrieval\Passage;
use Phox\TypeSafe\Client;
use stdClass;
use Throwable;

/**
 * The command line: `chunk`, `rank`, `ingest` and `retrieve`. Each prints JSON on stdout and any
 * notice on stderr, so the output can be piped.
 */
final class Application
{
    public const string VERSION = '1.0.0';

    private const string USAGE = <<<'TXT'
        Usage:
          context-atlas chunk [FILE|-] [--format=markdown|text|blocks] [--no-jev] [--config=FILE] [--pretty]
          context-atlas rank QUERY [FILE|-] [--limit=N] [--config=FILE] [--pretty]
          context-atlas ingest FILE|- [--id=ID] [--format=FORMAT] [--profile=PROFILE] [--title=TITLE]
                                [--store=DIR] [--no-jev] [--config=FILE] [--pretty]
          context-atlas retrieve QUERY --store=DIR [--documents=ID,...] [--config=FILE] [--pretty]

        chunk  Splits a document into passages. Reads Markdown by default; a .txt file is
               read as plain text and a .json file as a list of blocks.
        rank   Orders passages by whether they answer QUERY. FILE is a JSON list of
               {"id", "text", "heading_path"?}, or the output of `chunk`.
        ingest Reads a document in any supported format (by its extension, or --format;
               stdin needs --format and --id) and prints it as a stored document, or,
               with --store, saves it in that directory and prints a summary.
        retrieve
               Gathers the context that answers QUERY from the documents in --store,
               searched by exact terms; --documents limits it to those ids.

        Jev is reached with TYPESAFE_API_KEY, or OPENROUTER_API_KEY with
        TYPESAFE_PROVIDER=openrouter. Without it, `chunk` falls back to headings and
        sizes, `ingest` gives every section the guidance profile, and `rank` and
        `retrieve` fail.

        Exit codes: 0 done, 1 bad input, 2 Jev did not answer.
        TXT;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param  resource|null  $stdout
     * @param  resource|null  $stderr
     */
    public function __construct(
        private readonly ?Client $client = null,
        $stdout = null,
        $stderr = null,
    ) {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    /**
     * @param  list<string>  $argv  arguments after the program name
     */
    public function run(array $argv): int
    {
        try {
            $args = Args::parse($argv);

            return match (true) {
                $args->has('version') => $this->write(self::VERSION),
                $args->command === 'chunk' => $this->chunk($args),
                $args->command === 'rank' => $this->rank($args),
                $args->command === 'ingest' => $this->ingest($args),
                $args->command === 'retrieve' => $this->retrieve($args),
                default => $this->usage($args->command),
            };
        } catch (ContextAtlasException $exception) {
            fwrite($this->stderr, $exception->getMessage() . "\n");

            return 1;
        }
    }

    private function chunk(Args $args): int
    {
        $path = $args->positional[0] ?? '-';
        $input = $this->read($path);
        $blocks = match ($args->option('format') ?? self::formatOf($path)) {
            'markdown' => MarkdownParser::parse($input),
            'text' => PlainTextParser::parse($input),
            'blocks' => BlockListParser::parse($input),
            default => throw new ContextAtlasException('--format must be markdown, text or blocks.'),
        };

        $jev = $args->has('no-jev') ? null : $this->client();
        $chunked = (new Chunker($jev, $this->config($args)))->chunk($blocks);

        if ($jev !== null && ! $chunked->usedJev) {
            fwrite($this->stderr, "Jev was not used: the document had no long sections, or Jev did not answer. The chunks follow headings and sizes only.\n");
        }

        return $this->json($chunked->toArray(), $args);
    }

    private function rank(Args $args): int
    {
        $query = $args->positional[0] ?? throw new ContextAtlasException('rank needs a QUERY.');
        $passages = self::passages($this->read($args->positional[1] ?? '-'));
        $limit = $args->option('limit');

        $ranked = (new Reranker($this->client(), $this->config($args)))->rank($passages, $query, $limit === null ? null : max(1, (int) $limit));

        if ($ranked === null) {
            fwrite($this->stderr, "Jev did not answer. Check TYPESAFE_API_KEY.\n");

            return 2;
        }

        $byId = [];
        foreach ($passages as $passage) {
            $byId[$passage->id] = $passage->text;
        }

        return $this->json(array_map(static fn ($item): array => $item->toArray() + ['text' => $byId[$item->passage->id] ?? ''], $ranked), $args);
    }

    private function ingest(Args $args): int
    {
        $path = $args->positional[0] ?? throw new ContextAtlasException('ingest needs a FILE, or - with --format and --id.');
        $format = $args->option('format');
        $id = $args->option('id') ?? ($path === '-' ? throw new ContextAtlasException('Reading stdin needs --id.') : basename($path));
        $processor = new Processor($args->has('no-jev') ? null : $this->client(), $this->config($args));
        $profile = $args->option('profile');
        $title = $args->option('title');

        $document = match (true) {
            $format === null && $path === '-' => throw new ContextAtlasException('Reading stdin needs --format.'),
            $format === null => $processor->ingestFile($path, $id, $profile, $title),
            in_array($format, ['xlsx', 'docx'], true) && Formats::of($path) === $format => $processor->ingestFile($path, $id, $profile, $title),
            default => $processor->ingest($this->read($path), $id, $format, $profile, $title),
        };

        $store = $args->option('store');
        if ($store === null) {
            return $this->json($document->toArray(), $args);
        }
        (new FileStore($store))->put($document);

        return $this->json(['id' => $document->id, 'profile' => $document->profile, 'detected' => $document->detected, 'units' => count($document->units), 'links' => count($document->links), 'store' => $store], $args);
    }

    private function retrieve(Args $args): int
    {
        $query = $args->positional[0] ?? throw new ContextAtlasException('retrieve needs a QUERY.');
        $store = $args->option('store') ?? throw new ContextAtlasException('retrieve needs --store=DIR.');
        if (! is_dir($store)) {
            throw new ContextAtlasException("{$store} is not a directory.");
        }
        $documents = $args->option('documents');
        $ids = $documents === null ? null : array_values(array_filter(array_map('trim', explode(',', $documents)), static fn (string $id): bool => $id !== ''));

        $context = (new Processor($this->client(), $this->config($args)))->retrieve($query, new FileStore($store), null, $ids);

        if ($context === null) {
            fwrite($this->stderr, "Jev did not answer. Check TYPESAFE_API_KEY.\n");

            return 2;
        }

        return $this->json($context->toArray(), $args);
    }

    /**
     * A passage without an id takes its position as one. The JSON is decoded
     * to objects first, so an empty object is not read as an empty list.
     *
     * @return list<Passage>
     */
    private function passages(string $json): array
    {
        $value = $this->decode($json, false);
        $items = $value instanceof stdClass && is_array($value->chunks ?? null) ? $value->chunks : $value;

        if (! is_array($items)) {
            throw new ContextAtlasException('The passages must be a JSON list, or the output of `chunk`.');
        }

        $passages = [];
        foreach (array_values($items) as $index => $item) {
            $fields = is_object($item) ? (array) json_decode((string) json_encode($item), true) : [];
            $passages[] = Passage::fromArray($fields + ['id' => (string) $index]);
        }

        return $passages;
    }

    private function client(): Client
    {
        return $this->client ?? new Client();
    }

    private function config(Args $args): Config
    {
        $path = $args->option('config');

        if ($path === null) {
            return new Config();
        }

        $values = $this->decode($this->read($path));

        return Config::fromArray(is_array($values) ? $values : []);
    }

    private function read(string $path): string
    {
        $contents = $path === '-' ? stream_get_contents(STDIN) : (is_file($path) ? file_get_contents($path) : false);

        if ($contents === false) {
            throw new ContextAtlasException("Cannot read {$path}.");
        }

        return $contents;
    }

    private function decode(string $json, bool $associative = true): mixed
    {
        try {
            return json_decode($json, $associative, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ContextAtlasException('Not valid JSON.', 0, $exception);
        }
    }

    private static function formatOf(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'txt', 'text' => 'text',
            'json' => 'blocks',
            default => 'markdown',
        };
    }

    private function json(mixed $value, Args $args): int
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | ($args->has('pretty') ? JSON_PRETTY_PRINT : 0);

        try {
            return $this->write(json_encode($value, $flags | JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            throw new ContextAtlasException('Cannot write the output: ' . $exception->getMessage());
        }
    }

    private function usage(?string $command): int
    {
        if ($command !== null && $command !== 'help') {
            fwrite($this->stderr, "Unknown command \"{$command}\".\n\n" . self::USAGE . "\n");

            return 1;
        }

        return $this->write(self::USAGE);
    }

    private function write(string $text): int
    {
        fwrite($this->stdout, $text . "\n");

        return 0;
    }
}
