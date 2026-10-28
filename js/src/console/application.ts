import { readFileSync, statSync } from 'node:fs';
import { basename } from 'node:path';
import type { Block } from '../block.js';
import { Chunker } from '../chunker.js';
import { Config } from '../config.js';
import { ContextAtlasError } from '../errors.js';
import { parseBlocks } from '../parsing/blocks.js';
import { parseMarkdown } from '../parsing/markdown.js';
import { parseText } from '../parsing/text.js';
import { Reranker } from '../reranker.js';
import { Passage } from '../retrieval/passage.js';
import { formatOf as documentFormatOf, readBlocks } from '../parsing/formats.js';
import { Processor } from '../processor.js';
import { FileStore } from '../store/fileStore.js';
import { phpArray, phpTrim } from '../support/php.js';
import { clientFromEnv } from '../typesafe/client.js';
import type { Jev } from '../typesafe/jev.js';
import { Args } from './args.js';
import { encode, JsonFloat } from './json.js';

export const VERSION = '1.0.0';

const USAGE = `Usage:
  context-atlas chunk [FILE|-] [--format=markdown|text|blocks] [--no-jev] [--config=FILE] [--pretty]
  context-atlas rank QUERY [FILE|-] [--limit=N] [--config=FILE] [--pretty]
  context-atlas ingest FILE|- [--id=ID] [--format=FORMAT] [--profile=PROFILE] [--title=TITLE]
                        [--store=DIR] [--no-jev] [--config=FILE] [--pretty]
  context-atlas append FILE|- --id=ID --store=DIR [--format=FORMAT] [--profile=PROFILE]
                        [--no-jev] [--config=FILE] [--pretty]
  context-atlas retrieve QUERY --store=DIR [--documents=ID,...] [--config=FILE] [--pretty]

chunk  Splits a document into passages. Reads Markdown by default; a .txt file is
       read as plain text and a .json file as a list of blocks.
rank   Orders passages by whether they answer QUERY. FILE is a JSON list of
       {"id", "text", "heading_path"?}, or the output of \`chunk\`.
ingest Reads a document in any supported format (by its extension, or --format;
       stdin needs --format and --id) and prints it as a stored document, or,
       with --store, saves it in that directory and prints a summary.
append Adds a document's new text (by its extension, or --format) to the end of the
       prose document --id in --store, asking Jev only about the new blocks, and
       prints a summary.
retrieve
       Gathers the context that answers QUERY from the documents in --store,
       searched by exact terms; --documents limits it to those ids.

Jev is reached with TYPESAFE_API_KEY, or OPENROUTER_API_KEY with
TYPESAFE_PROVIDER=openrouter. Without it, \`chunk\` falls back to headings and
sizes, \`ingest\` gives every section the guidance profile, and \`rank\` and
\`retrieve\` fail.

Exit codes: 0 done, 1 bad input, 2 Jev did not answer.`;

export interface Output {
    write(text: string): unknown;
}

export interface ApplicationOptions {
    /** Jev for both commands; built from the environment when absent. */
    jev?: Jev;
    stdout?: Output;
    stderr?: Output;
    /** Reads all of standard input. */
    stdin?: () => string;
}

/** The command line: `chunk` and `rank`. JSON goes to stdout and notices to stderr, so the output can be piped. */
export class Application {
    private readonly stdout: Output;

    private readonly stderr: Output;

    private readonly stdin: () => string;

    constructor(private readonly options: ApplicationOptions = {}) {
        this.stdout = options.stdout ?? process.stdout;
        this.stderr = options.stderr ?? process.stderr;
        this.stdin = options.stdin ?? (() => readFileSync(0, 'utf8'));
    }

    /** The exit code, for arguments after the program name. */
    async run(argv: readonly string[]): Promise<number> {
        try {
            const args = Args.parse(argv);

            if (args.has('version')) {
                return this.write(VERSION);
            }
            if (args.command === 'chunk') {
                return await this.chunk(args);
            }
            if (args.command === 'rank') {
                return await this.rank(args);
            }
            if (args.command === 'ingest') {
                return await this.ingest(args);
            }
            if (args.command === 'append') {
                return await this.append(args);
            }
            if (args.command === 'retrieve') {
                return await this.retrieve(args);
            }

            return this.usage(args.command);
        } catch (error) {
            if (!(error instanceof ContextAtlasError)) {
                throw error;
            }
            this.stderr.write(`${error.message}\n`);

            return 1;
        }
    }

    private async chunk(args: Args): Promise<number> {
        const path = args.positional[0] ?? '-';
        const blocks = this.parse(this.read(path), args.option('format') ?? formatOf(path));
        const jev = args.has('no-jev') ? null : this.jev();
        const chunked = await new Chunker(jev, this.config(args)).chunk(blocks);

        if (jev !== null && !chunked.usedJev) {
            this.stderr.write('Jev was not used: the document had no long sections, or Jev did not answer. The chunks follow headings and sizes only.\n');
        }

        return this.json(chunked.toObject(), args);
    }

    private async rank(args: Args): Promise<number> {
        const query = args.positional[0];

        if (query === undefined) {
            throw new ContextAtlasError('rank needs a QUERY.');
        }

        const passages = passagesOf(this.decode(this.read(args.positional[1] ?? '-')));
        const limit = args.option('limit');
        const ranked = await new Reranker(this.jev(), this.config(args)).rank(passages, query, limit === null ? null : Math.max(1, phpInt(limit)));

        if (ranked === null) {
            this.stderr.write('Jev did not answer. Check TYPESAFE_API_KEY.\n');

            return 2;
        }

        const texts = new Map(passages.map((passage) => [passage.id, passage.text]));

        return this.json(ranked.map((item) => ({
            id: item.passage.id,
            score: new JsonFloat(item.score),
            text: texts.get(item.passage.id) ?? '',
        })), args);
    }

    private async ingest(args: Args): Promise<number> {
        const path = args.positional[0];

        if (path === undefined) {
            throw new ContextAtlasError('ingest needs a FILE, or - with --format and --id.');
        }

        const format = args.option('format');
        const named = args.option('id');

        if (named === null && path === '-') {
            throw new ContextAtlasError('Reading stdin needs --id.');
        }

        const id = named ?? basename(path);
        const processor = new Processor(args.has('no-jev') ? null : this.jev(), this.config(args));
        const profile = args.option('profile');
        const title = args.option('title');

        if (format === null && path === '-') {
            throw new ContextAtlasError('Reading stdin needs --format.');
        }

        const document = format === null || ((format === 'xlsx' || format === 'docx') && documentFormatOf(path) === format)
            ? await processor.ingestFile(path, id, profile, title)
            : await processor.ingest(this.readBytes(path), id, format, profile, title);
        const store = args.option('store');

        if (store === null) {
            return this.json(document.toObject(), args);
        }

        new FileStore(store).put(document);

        return this.json({
            id: document.id,
            profile: document.profile,
            detected: document.detected,
            units: document.units.length,
            links: document.links.length,
            store,
        }, args);
    }

    private async append(args: Args): Promise<number> {
        const path = args.positional[0];
        const id = args.option('id');
        const store = args.option('store');

        if (path === undefined || id === null || store === null) {
            throw new ContextAtlasError('append needs a FILE or -, --id and --store.');
        }

        const format = args.option('format') ?? (path === '-' ? null : documentFormatOf(path));

        if (format === null) {
            throw new ContextAtlasError(path === '-' ? 'Reading stdin needs --format.' : `Cannot tell the format of ${path}: pass --format.`);
        }

        const files = new FileStore(store);
        const stored = files.document(id);

        if (stored === null) {
            throw new ContextAtlasError(`No document "${id}" in ${store}.`);
        }

        const blocks = readBlocks(this.read(path), format);
        const document = await new Processor(args.has('no-jev') ? null : this.jev(), this.config(args)).append(stored, blocks, args.option('profile'));
        files.put(document);

        return this.json({
            id: document.id,
            profile: document.profile,
            detected: document.detected,
            units: document.units.length,
            links: document.links.length,
            store,
        }, args);
    }

    private async retrieve(args: Args): Promise<number> {
        const query = args.positional[0];

        if (query === undefined) {
            throw new ContextAtlasError('retrieve needs a QUERY.');
        }

        const store = args.option('store');

        if (store === null) {
            throw new ContextAtlasError('retrieve needs --store=DIR.');
        }
        if (!isDirectory(store)) {
            throw new ContextAtlasError(`${store} is not a directory.`);
        }

        const documents = args.option('documents');
        const ids = documents === null ? undefined : documents.split(',').map((id) => phpTrim(id)).filter((id) => id !== '');
        const context = await new Processor(this.jev(), this.config(args)).retrieve(query, new FileStore(store), ids === undefined ? {} : { documents: ids });

        if (context === null) {
            this.stderr.write('Jev did not answer. Check TYPESAFE_API_KEY.\n');

            return 2;
        }

        return this.json(context.toObject(), args);
    }

    private parse(input: string, format: string): Block[] {
        switch (format) {
            case 'markdown':
                return parseMarkdown(input);
            case 'text':
                return parseText(input);
            case 'blocks':
                return parseBlocks(input);
            default:
                throw new ContextAtlasError('--format must be markdown, text or blocks.');
        }
    }

    /** A client that cannot be built, for want of a key, is a Jev that never answers. */
    private jev(): Jev {
        if (this.options.jev !== undefined) {
            return this.options.jev;
        }

        try {
            return clientFromEnv();
        } catch {
            return () => null;
        }
    }

    private config(args: Args): Config {
        const path = args.option('config');

        if (path === null) {
            return new Config();
        }

        const values = this.decode(this.read(path));

        return Config.fromObject(typeof values === 'object' && values !== null ? Object.fromEntries(phpArray(values)) : {});
    }

    /** A file's bytes, or stdin's, so text that is not UTF-8 can be read as Windows-1252. */
    private readBytes(path: string): Uint8Array {
        if (path === '-') {
            return this.options.stdin === undefined ? readFileSync(0) : new TextEncoder().encode(this.options.stdin());
        }

        try {
            if (statSync(path).isFile()) {
                return readFileSync(path);
            }
        } catch {
            // Reported below with the path.
        }

        throw new ContextAtlasError(`Cannot read ${path}.`);
    }

    private read(path: string): string {
        if (path === '-') {
            return this.stdin();
        }

        try {
            if (statSync(path).isFile()) {
                return readFileSync(path, 'utf8');
            }
        } catch {
            // Reported below with the path.
        }

        throw new ContextAtlasError(`Cannot read ${path}.`);
    }

    private decode(json: string): unknown {
        try {
            return JSON.parse(json);
        } catch {
            throw new ContextAtlasError('Not valid JSON.');
        }
    }

    private json(value: unknown, args: Args): number {
        return this.write(encode(value, args.has('pretty')));
    }

    private usage(command: string | null): number {
        if (command !== null && command !== 'help') {
            this.stderr.write(`Unknown command "${command}".\n\n${USAGE}\n`);

            return 1;
        }

        return this.write(USAGE);
    }

    private write(text: string): number {
        this.stdout.write(`${text}\n`);

        return 0;
    }
}

/**
 * Passages from a JSON list, or from the `chunks` list of a chunk result. A
 * passage without an `id` key takes its position as a string; `"id": null`
 * is an empty id, as in the PHP package.
 */
function passagesOf(value: unknown): Passage[] {
    const chunks = typeof value === 'object' && value !== null && !Array.isArray(value) ? (value as Record<string, unknown>)['chunks'] : undefined;
    const items = Array.isArray(chunks) ? chunks : value;

    if (!Array.isArray(items)) {
        throw new ContextAtlasError('The passages must be a JSON list, or the output of `chunk`.');
    }

    return items.map((item, index) => {
        const fields = typeof item === 'object' && item !== null && !Array.isArray(item) ? item as Record<string, unknown> : {};

        return Passage.fromObject(Object.hasOwn(fields, 'id') ? fields : { ...fields, id: String(index) });
    });
}

/** The extension is what follows the last dot of the file name, as PHP's `pathinfo` reads it, so `.json` alone is JSON. */
function isDirectory(path: string): boolean {
    try {
        return statSync(path).isDirectory();
    } catch {
        return false;
    }
}

function formatOf(path: string): string {
    const name = basename(path);
    const dot = name.lastIndexOf('.');

    switch (dot === -1 ? '' : name.slice(dot + 1).toLowerCase()) {
        case 'txt':
        case 'text':
            return 'text';
        case 'json':
            return 'blocks';
        default:
            return 'markdown';
    }
}

/** PHP's `(int)` on a string: its leading integer, or 0. */
function phpInt(value: string): number {
    const match = /^[ \t\n\r\v\f]*([+-]?\d+)/.exec(value);

    return match === null ? 0 : Number.parseInt(match[1]!, 10);
}
