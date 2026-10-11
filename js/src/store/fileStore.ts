import { createHash } from 'node:crypto';
import { appendFileSync, existsSync, mkdirSync, readdirSync, readFileSync, renameSync, rmdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { Document } from '../document/document.js';
import { ContextAtlasError } from '../errors.js';
import type { Posting, Source, SourceStats } from './source.js';

/** Hex characters of a term's SHA-256 that name its shard. */
const SHARD = 3;

/** Hex characters of a document's hash written on its index lines. */
const KEY = 16;

const LOCK_WAIT_MS = 30_000;

const LOCK_RETRY_MS = 20;

const LOCK_STALE_MS = 120_000;

/** Document hash to `[id, units, length, version]`, in the order `stats.json` lists them. */
type Stats = Map<string, [string, number, number, number]>;

/**
 * One JSON file per document in a directory the caller chooses, with an append-only term index
 * beside them, so a query reads only the index shards of its terms and the documents it returns,
 * and a write appends to the shards its document's terms fall in. The layout is the one the PHP
 * and Python packages write, so a store is shared between them (spec/document.md, "Sources"):
 *
 * - `<sha256(id)>.json` per document;
 * - `index/stats.json`, `{document hash: [id, units, length, version]}`;
 * - `index/<hhh>.tsv`, one line per term and document version for every term whose SHA-256
 *   starts with the hex characters `hhh`: `term TAB key TAB version TAB unit:count:length,...`,
 *   `key` the first 16 hex characters of the document's hash.
 *
 * A line counts only while its version is the document's version in `stats.json`, so a rewritten
 * or removed document leaves dead lines behind; `compact()` drops them. Postings are read in
 * document-hash order. A store written without an index is indexed on first use.
 *
 * A writer holds the store by creating the directory `index/.lock.d`, which only one process
 * can do at a time, so writers from every package exclude each other. It retries every
 * 20 ms for up to 30 s, removes a lock left more than 120 s ago, and removes its own after the
 * write.
 */
export class FileStore implements Source {
    private readonly cache = new Map<string, Document>();

    private byHash: Stats = new Map();

    private indexed = false;

    constructor(private readonly directory: string) {}

    /** @throws {ContextAtlasError} when the index directory cannot be created */
    put(document: Document): void {
        this.locked(() => {
            this.loadOrBuild();
            writeFileSync(this.path(document.id), documentJson(document));

            const hash = sha256(document.id);
            const version = (this.byHash.get(hash)?.[3] ?? 0) + 1;

            for (const [shard, lines] of linesOf([document], new Map([[hash, version]]))) {
                appendFileSync(this.shardPath(shard), lines);
            }
            this.byHash.set(hash, [document.id, ...sizes(document), version]);
            this.writeStats();
            this.cache.delete(document.id);
        });
    }

    /** @throws {ContextAtlasError} when the index directory cannot be created */
    remove(id: string): void {
        this.locked(() => {
            this.loadOrBuild();
            this.byHash.delete(sha256(id));
            this.cache.delete(id);
            this.writeStats();
            rmSync(this.path(id), { force: true });
        });
    }

    /**
     * Rewrites the index without the lines of rewritten or removed documents.
     *
     * @throws {ContextAtlasError} when the index directory cannot be created
     */
    compact(): void {
        this.locked(() => {
            this.loadOrBuild();

            const byKey = this.byKey();

            for (const name of sortedNames(this.indexDir(), '.tsv')) {
                const path = join(this.indexDir(), name);
                const kept = shardLines(path).filter((line) => this.counts(splitLine(line), byKey) !== null);

                writeAtomically(path, kept.map((line) => `${line}\n`).join(''));
            }
        });
    }

    document(id: string): Document | null {
        const cached = this.cache.get(id);

        if (cached !== undefined) {
            return cached;
        }
        if (!isFile(this.path(id))) {
            return null;
        }

        const document = read(this.path(id));
        this.cache.set(id, document);

        return document;
    }

    postings(terms: readonly string[]): Map<string, Posting[]> {
        this.ensureIndex();

        const wanted = new Map<string, Map<string, string>>(terms.map((term) => [term, new Map()]));
        const byKey = this.byKey();
        const byShard = new Map<string, Set<string>>();

        for (const term of terms) {
            const shard = sha256(term).slice(0, SHARD);
            byShard.set(shard, (byShard.get(shard) ?? new Set<string>()).add(term));
        }
        for (const [shard, shardTerms] of byShard) {
            for (const line of shardLines(this.shardPath(shard))) {
                const fields = splitLine(line);
                const hash = this.counts(fields, byKey);

                if (hash !== null && shardTerms.has(fields[0]!)) {
                    wanted.get(fields[0]!)!.set(hash, fields[3]!);
                }
            }
        }

        const out = new Map<string, Posting[]>();

        for (const [term, byDocument] of wanted) {
            const postings: Posting[] = [];

            for (const hash of [...byDocument.keys()].sort()) {
                for (const posting of byDocument.get(hash)!.split(',')) {
                    const [unit = '', count = '', length = ''] = posting.split(':');
                    postings.push({ document: this.byHash.get(hash)![0], unit, count: phpInt(count), length: phpInt(length) });
                }
            }
            out.set(term, postings);
        }

        return out;
    }

    stats(): SourceStats {
        this.ensureIndex();

        let units = 0;
        let length = 0;

        for (const [, documentUnits, documentLength] of this.byHash.values()) {
            units += documentUnits;
            length += documentLength;
        }

        return { units, length };
    }

    /** The hash of the document a line belongs to when the line counts (four fields, a known key, the current version), else null. */
    private counts(fields: readonly string[], byKey: ReadonlyMap<string, string>): string | null {
        const hash = byKey.get(fields[1] ?? '');

        return fields.length === 4 && hash !== undefined && this.byHash.get(hash)![3] === phpInt(fields[2]!) ? hash : null;
    }

    private ensureIndex(): void {
        if (this.indexed) {
            return;
        }
        if (isFile(this.statsPath())) {
            this.loadStats();
        } else {
            this.locked(() => this.loadOrBuild());
        }
        this.indexed = true;
    }

    /** Loads the index, or builds it in one pass from the stored documents. */
    private loadOrBuild(): void {
        if (isFile(this.statsPath())) {
            this.loadStats();

            return;
        }

        const documents = sortedNames(this.directory, '.json').map((name) => read(join(this.directory, name)));
        const versions = new Map<string, number>();
        this.byHash = new Map();

        for (const document of documents) {
            const hash = sha256(document.id);
            versions.set(hash, 1);
            this.byHash.set(hash, [document.id, ...sizes(document), 1]);
        }
        for (const [shard, lines] of linesOf(documents, versions)) {
            writeAtomically(this.shardPath(shard), lines);
        }
        this.writeStats();
        this.indexed = true;
    }

    /** Each document's 16-character key to its hash; of two hashes sharing a key, the later in `stats.json`. */
    private byKey(): Map<string, string> {
        return new Map([...this.byHash.keys()].map((hash) => [hash.slice(0, KEY), hash]));
    }

    private loadStats(): void {
        const decoded: unknown = JSON.parse(readFileSync(this.statsPath(), 'utf8'));
        const entries = typeof decoded === 'object' && decoded !== null ? Object.entries(decoded) : [];

        this.byHash = new Map(entries.map(([hash, value]) => {
            const fields: unknown[] = Array.isArray(value) ? value : Object.values(value as object);

            return [hash, [String(fields[0] ?? ''), Math.trunc(Number(fields[1] ?? 0)), Math.trunc(Number(fields[2] ?? 0)), Math.trunc(Number(fields[3] ?? 0))]];
        }));
    }

    private writeStats(): void {
        const members = [...this.byHash].map(([hash, [id, units, length, version]]) => `${jsonText(hash)}:[${jsonText(id)},${units},${length},${version}]`);

        writeAtomically(this.statsPath(), `{${members.join(',')}}`);
    }

    /** @throws {ContextAtlasError} when the index directory cannot be created */
    private locked(write: () => void): void {
        try {
            mkdirSync(this.indexDir(), { recursive: true });
        } catch {
            throw new ContextAtlasError(`Cannot create ${this.indexDir()}.`);
        }

        const lock = join(this.indexDir(), '.lock.d');
        const deadline = Date.now() + LOCK_WAIT_MS;

        while (!tryMkdir(lock)) {
            const modified = modifiedAt(lock);

            if (modified !== null && modified < Date.now() - LOCK_STALE_MS) {
                removeDir(lock);
                continue;
            }
            if (Date.now() > deadline) {
                throw new ContextAtlasError(`Cannot lock ${this.indexDir()}: another writer holds ${lock}.`);
            }
            sleep(LOCK_RETRY_MS);
        }

        try {
            write();
        } finally {
            removeDir(lock);
        }
    }

    private shardPath(shard: string): string {
        return join(this.indexDir(), `${shard}.tsv`);
    }

    private statsPath(): string {
        return join(this.indexDir(), 'stats.json');
    }

    private indexDir(): string {
        return join(this.directory, 'index');
    }

    /** File names are the document id hashed, so any id is safe on any file system. */
    private path(id: string): string {
        return join(this.directory, `${sha256(id)}.json`);
    }
}

/**
 * Index lines by shard, shards in the order first met. A document's terms are taken in byte
 * order, the order its term map is written in; a JavaScript object lists a term such as `2019`
 * first.
 */
function linesOf(documents: readonly Document[], versions: ReadonlyMap<string, number>): Map<string, string> {
    const out = new Map<string, string>();

    for (const document of documents) {
        const hash = sha256(document.id);
        const terms = document.lexical.terms;

        for (const term of Object.keys(terms).sort(byteOrder)) {
            const postings = Object.entries(terms[term]!).map(([unit, count]) => `${unit}:${Math.trunc(Number(count))}:${Math.trunc(Number(document.lexical.length[unit] ?? 0))}`);
            const shard = sha256(term).slice(0, SHARD);

            out.set(shard, `${out.get(shard) ?? ''}${term}\t${hash.slice(0, KEY)}\t${versions.get(hash)}\t${postings.join(',')}\n`);
        }
    }

    return out;
}

function sizes(document: Document): [number, number] {
    const lengths = Object.values(document.lexical.length).map((value) => Math.trunc(Number(value)));

    return [lengths.length, lengths.reduce((sum, value) => sum + value, 0)];
}

/** The line split in at most four fields, as PHP's `explode("\t", $line, 4)`. */
function splitLine(line: string): string[] {
    const fields = line.split('\t');

    return fields.length <= 4 ? fields : [...fields.slice(0, 3), fields.slice(3).join('\t')];
}

function shardLines(path: string): string[] {
    return isFile(path) ? readFileSync(path, 'utf8').split('\n').filter((line) => line !== '') : [];
}

/** File names with this extension, in byte order, as PHP's `glob` lists them. */
function sortedNames(directory: string, extension: string): string[] {
    if (!existsSync(directory)) {
        return [];
    }

    return readdirSync(directory).filter((name) => name.endsWith(extension) && isFile(join(directory, name))).sort(byteOrder);
}

/** Written to a temporary file and renamed, so a reader never sees half a file. */
function writeAtomically(path: string, contents: string): void {
    writeFileSync(`${path}.tmp`, contents);
    renameSync(`${path}.tmp`, path);
}

/** Creates the directory, or returns false when it already exists: mkdir is atomic, so only one writer succeeds. */
function tryMkdir(path: string): boolean {
    try {
        mkdirSync(path);

        return true;
    } catch (error) {
        if ((error as NodeJS.ErrnoException).code === 'EEXIST') {
            return false;
        }
        throw error;
    }
}

function modifiedAt(path: string): number | null {
    try {
        return statSync(path).mtimeMs;
    } catch {
        return null;
    }
}

function removeDir(path: string): void {
    try {
        rmdirSync(path);
    } catch {
        // Already gone: a writer that judged the lock stale removed it.
    }
}

/** Blocks the thread, as PHP's `usleep` does; the store's methods are synchronous. */
function sleep(ms: number): void {
    Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
}

function isFile(path: string): boolean {
    try {
        return statSync(path).isFile();
    } catch {
        return false;
    }
}

function sha256(text: string): string {
    return createHash('sha256').update(text, 'utf8').digest('hex');
}

function byteOrder(a: string, b: string): number {
    return Buffer.compare(Buffer.from(a, 'utf8'), Buffer.from(b, 'utf8'));
}

/** PHP's `(int)` on a string: its leading integer, or 0. */
function phpInt(text: string): number {
    const match = /^[ \t\n\r\v\f]*([+-]?\d+)/.exec(text);

    return match === null ? 0 : Number(match[1]);
}

/** JSON as PHP's `json_encode` writes it with Unicode and slashes unescaped: U+2028 and U+2029 stay escaped. */
function jsonText(value: unknown): string {
    return JSON.stringify(value).replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');
}

/**
 * A document as stored, with its term map in byte order as PHP writes it (a JavaScript object
 * lists a term such as `2019` first). Floats print as JavaScript prints them, so a table row's
 * `1960.0` from PHP is `1960` here.
 */
function documentJson(document: Document): string {
    const value = document.toObject();
    const terms = value.lexical.terms;
    const sentinel = `\u0000terms-${sha256(document.id)}\u0000`;
    const ordered = `{${Object.keys(terms).sort(byteOrder).map((term) => `${jsonText(term)}:${jsonText(terms[term])}`).join(',')}}`;

    return jsonText({ ...value, lexical: { length: value.lexical.length, terms: sentinel } }).replace(jsonText(sentinel), () => ordered);
}

/** @throws {ContextAtlasError} when the file is not a stored document */
function read(path: string): Document {
    let value: unknown;

    try {
        value = JSON.parse(readFileSync(path, 'utf8'));
    } catch {
        throw new ContextAtlasError(`${path} is not a stored document.`);
    }

    return Document.fromObject(typeof value === 'object' && value !== null ? value as Record<string, unknown> : {});
}
