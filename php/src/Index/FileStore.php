<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Index;

use JsonException;
use Phox\ContextAtlas\Document\Document;
use Phox\ContextAtlas\Exceptions\ContextAtlasException;

/**
 * One JSON file per document in a directory the caller chooses, with an append-only term index
 * beside them, so a query reads only the index shards of its terms and the documents it returns,
 * and a write appends to the shards its document's terms fall in.
 *
 * Layout: `<sha256(id)>.json` per document; `index/stats.json`, `{document hash: [id, units,
 * length, version]}`; `index/<hh>.tsv`, one line per term and document version for every term
 * whose SHA-256 starts with the hex pair `hh`: `term TAB document hash TAB version TAB
 * unit:count:length,...`. A line counts only while its version is the document's version in
 * `stats.json`, so a rewritten or removed document leaves dead lines behind; `compact()` drops
 * them. Postings are read in document-hash order, the order the documents' files sort in. A
 * store written without an index is indexed on first use. A writer holds the store by creating
 * the directory `index/.lock.d` (atomic in every language and on network file systems), waits up
 * to 30 seconds for another writer, and removes a lock older than 120 seconds as left by a
 * writer that died.
 */
final class FileStore implements Source
{
    private const int SHARD = 3;

    private const int KEY = 16;

    private const int LOCK_WAIT = 30;

    private const int LOCK_STALE = 120;

    /** @var array<string, Document> */
    private array $read = [];

    /** @var array<string, array{string, int, int, int}> document hash => [id, units, length, version] */
    private array $stats = [];

    private bool $indexed = false;

    public function __construct(
        private readonly string $directory,
    ) {}

    /**
     * @throws ContextAtlasException
     */
    public function put(Document $document): void
    {
        $this->locked(function () use ($document): void {
            $this->loadOrBuild();
            file_put_contents($this->path($document->id), json_encode($document->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
            $hash = hash('sha256', $document->id);
            $version = ($this->stats[$hash][3] ?? 0) + 1;
            foreach ($this->lines([$document], [$hash => $version]) as $shard => $lines) {
                file_put_contents($this->shardPath((string) $shard), $lines, FILE_APPEND);
            }
            $this->stats[$hash] = [$document->id, count($document->lexical['length']), (int) array_sum($document->lexical['length']), $version];
            $this->writeStats();
            unset($this->read[$document->id]);
        });
    }

    /**
     * @throws ContextAtlasException
     */
    public function remove(string $id): void
    {
        $this->locked(function () use ($id): void {
            $this->loadOrBuild();
            unset($this->stats[hash('sha256', $id)], $this->read[$id]);
            $this->writeStats();
            if (is_file($this->path($id))) {
                unlink($this->path($id));
            }
        });
    }

    /**
     * Rewrites the index without the lines of rewritten or removed documents.
     *
     * @throws ContextAtlasException
     */
    public function compact(): void
    {
        $this->locked(function (): void {
            $this->loadOrBuild();
            foreach (glob($this->indexDir() . '/*.tsv') ?: [] as $path) {
                $kept = '';
                $byKey = [];
                foreach (array_keys($this->stats) as $hash) {
                    $byKey[substr((string) $hash, 0, self::KEY)] = (string) $hash;
                }
                foreach ($this->shardLines($path) as $line) {
                    $fields = explode("\t", $line, 4);
                    $hash = $byKey[$fields[1] ?? ''] ?? null;
                    if (count($fields) === 4 && $hash !== null && $this->stats[$hash][3] === (int) $fields[2]) {
                        $kept .= $line . "\n";
                    }
                }
                $this->writeFile($path, $kept);
            }
        });
    }

    public function document(string $id): ?Document
    {
        if (isset($this->read[$id])) {
            return $this->read[$id];
        }
        if (! is_file($this->path($id))) {
            return null;
        }

        return $this->read[$id] = self::read($this->path($id));
    }

    public function postings(array $terms): array
    {
        $this->ensureIndex();
        $wanted = array_fill_keys($terms, []);
        $byKey = [];
        foreach (array_keys($this->stats) as $hash) {
            $byKey[substr((string) $hash, 0, self::KEY)] = (string) $hash;
        }
        $byShard = [];
        foreach ($terms as $term) {
            $byShard[substr(hash('sha256', $term), 0, self::SHARD)][$term] = true;
        }
        foreach ($byShard as $shard => $shardTerms) {
            foreach ($this->shardLines($this->shardPath((string) $shard)) as $line) {
                $fields = explode("\t", $line, 4);
                $hash = $byKey[$fields[1] ?? ''] ?? null;
                if (count($fields) !== 4 || ! isset($shardTerms[$fields[0]]) || $hash === null || $this->stats[$hash][3] !== (int) $fields[2]) {
                    continue;
                }
                $wanted[$fields[0]][$hash] = $fields[3];
            }
        }

        $out = [];
        foreach ($wanted as $term => $byDocument) {
            ksort($byDocument, SORT_STRING);
            $out[(string) $term] = [];
            foreach ($byDocument as $hash => $postings) {
                foreach (explode(',', $postings) as $posting) {
                    [$unit, $count, $length] = explode(':', $posting);
                    $out[(string) $term][] = ['document' => $this->stats[(string) $hash][0], 'unit' => $unit, 'count' => (int) $count, 'length' => (int) $length];
                }
            }
        }

        return $out;
    }

    public function stats(): array
    {
        $this->ensureIndex();

        return [
            'units' => array_sum(array_map(static fn (array $d): int => $d[1], $this->stats)),
            'length' => array_sum(array_map(static fn (array $d): int => $d[2], $this->stats)),
        ];
    }

    private function ensureIndex(): void
    {
        if ($this->indexed) {
            return;
        }
        if (is_file($this->statsPath())) {
            $this->loadStats();
        } else {
            $this->locked($this->loadOrBuild(...));
        }
        $this->indexed = true;
    }

    /**
     * Loads the index, or builds it in one pass from the stored documents.
     */
    private function loadOrBuild(): void
    {
        if (is_file($this->statsPath())) {
            $this->loadStats();

            return;
        }
        $documents = array_map(self::read(...), glob(rtrim($this->directory, '/') . '/*.json') ?: []);
        $this->stats = [];
        $versions = [];
        foreach ($documents as $document) {
            $hash = hash('sha256', $document->id);
            $versions[$hash] = 1;
            $this->stats[$hash] = [$document->id, count($document->lexical['length']), (int) array_sum($document->lexical['length']), 1];
        }
        foreach ($this->lines($documents, $versions) as $shard => $lines) {
            $this->writeFile($this->shardPath((string) $shard), $lines);
        }
        $this->writeStats();
        $this->indexed = true;
    }

    /**
     * Index lines by shard for these documents.
     *
     * @param  list<Document>  $documents
     * @param  array<string, int>  $versions  document hash => version
     * @return array<array-key, string> shard => lines (a shard such as `12` is an integer key)
     */
    private function lines(array $documents, array $versions): array
    {
        $out = [];
        foreach ($documents as $document) {
            $hash = hash('sha256', $document->id);
            foreach ($document->lexical['terms'] as $term => $units) {
                $postings = [];
                foreach ($units as $unit => $count) {
                    $postings[] = $unit . ':' . (int) $count . ':' . (int) ($document->lexical['length'][$unit] ?? 0);
                }
                $out['s' . substr(hash('sha256', (string) $term), 0, self::SHARD)][] = $term . "\t" . substr($hash, 0, self::KEY) . "\t" . $versions[$hash] . "\t" . implode(',', $postings) . "\n";
            }
        }

        $joined = [];
        foreach ($out as $shard => $lines) {
            $joined[substr((string) $shard, 1)] = implode('', $lines);
        }

        return $joined;
    }

    /**
     * @return list<string>
     */
    private function shardLines(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        return array_values(array_filter(explode("\n", (string) file_get_contents($path)), static fn (string $line): bool => $line !== ''));
    }

    private function loadStats(): void
    {
        $this->stats = array_map(static fn (array $d): array => [(string) $d[0], (int) $d[1], (int) $d[2], (int) $d[3]], (array) json_decode((string) file_get_contents($this->statsPath()), true, 512, JSON_THROW_ON_ERROR));
    }

    private function writeStats(): void
    {
        $this->writeFile($this->statsPath(), json_encode((object) $this->stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  callable(): void  $write
     *
     * @throws ContextAtlasException
     */
    private function locked(callable $write): void
    {
        if (! is_dir($this->indexDir()) && ! @mkdir($this->indexDir(), 0775, true) && ! is_dir($this->indexDir())) {
            throw new ContextAtlasException("Cannot create {$this->indexDir()}.");
        }
        $lock = $this->indexDir() . '/.lock.d';
        $deadline = microtime(true) + self::LOCK_WAIT;
        while (! @mkdir($lock)) {
            clearstatcache(true, $lock);
            $modified = @filemtime($lock);
            if ($modified !== false && $modified < time() - self::LOCK_STALE) {
                @rmdir($lock);

                continue;
            }
            if (microtime(true) > $deadline) {
                throw new ContextAtlasException("Cannot lock {$this->indexDir()}: another writer holds {$lock}.");
            }
            usleep(20000);
        }
        try {
            $write();
        } finally {
            @rmdir($lock);
        }
    }

    private function shardPath(string $shard): string
    {
        return $this->indexDir() . '/' . $shard . '.tsv';
    }

    private function statsPath(): string
    {
        return $this->indexDir() . '/stats.json';
    }

    private function indexDir(): string
    {
        return rtrim($this->directory, '/') . '/index';
    }

    /**
     * File names are the document id hashed, so any id is safe on any file system.
     */
    private function path(string $id): string
    {
        return rtrim($this->directory, '/') . '/' . hash('sha256', $id) . '.json';
    }

    /**
     * Written to a temporary file and renamed, so a reader never sees half a file.
     */
    private function writeFile(string $path, string $contents): void
    {
        file_put_contents($path . '.tmp', $contents);
        rename($path . '.tmp', $path);
    }

    /**
     * @throws ContextAtlasException
     */
    private static function read(string $path): Document
    {
        try {
            return Document::fromArray((array) json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new ContextAtlasException("{$path} is not a stored document.", 0, $exception);
        }
    }
}
