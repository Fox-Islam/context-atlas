import { readFileSync, statSync } from 'node:fs';
import { basename, extname } from 'node:path';
import type { Block } from './block.js';
import { Config } from './config.js';
import { Context } from './context.js';
import { Document } from './document/document.js';
import { Unit } from './document/unit.js';
import { ContextAtlasError } from './errors.js';
import { ingestMarkupText } from './markup/profile.js';
import { utf8OrWindows1252 } from './support/encoding.js';
import { parseChat } from './parsing/chat.js';
import { parseDocx, parseDocxBytes } from './parsing/docx.js';
import { DATA_FORMATS, formatOf, readBlocks, SPREADSHEET_FORMATS } from './parsing/formats.js';
import { appendProse } from './prose/appender.js';
import { ingestProse } from './prose/ingest.js';
import { inDocumentOrder, key, ProseRetriever, unique } from './prose/retriever.js';
import { searchLexical } from './store/lexical.js';
import type { Source } from './store/source.js';
import { meaningOf, runPlan } from './tabular/executor.js';
import { Grid, type Sheet } from './tabular/grid.js';
import { Planner } from './tabular/planner.js';
import { ingestGrid } from './tabular/profile.js';
import type { Table } from './tabular/tables.js';
import type { Jev } from './typesafe/jev.js';

const CANDIDATES = 20;

const MAX_TABLES = 12;

const FUSION_K = 60;

const SUPERSEDED = '(A later message corrects this one.)';

export interface UnitReference {
    document: string;
    unit: string;
}

/**
 * The caller's vector search: up to `limit` units nearest the query, best first. `kinds` limits
 * the units to those kinds and `documents` to those documents; either is null for all.
 */
export type Nearest = (query: string, limit: number, kinds: string[] | null, documents: readonly string[] | null) => readonly UnitReference[] | Promise<readonly UnitReference[]>;

export interface RetrieveOptions {
    nearest?: Nearest;
    /** Only these documents: every table in them is a table candidate, and passages come from the search kept to them. */
    documents?: readonly string[];
}

interface Candidate {
    document: Document;
    unit: Unit;
}

/**
 * Ingests documents into storable artifacts, and retrieves context for a question from them. The
 * caller stores what ingestion returns and passes a Source back at retrieval; the library writes
 * nothing anywhere.
 *
 * ```ts
 * const processor = new Processor(new TypeSafeClient());
 * store.put(processor.ingestSpreadsheet('spend.csv', 'dft-spend-2026-03'));
 * const context = await processor.retrieve('How much went to Network Rail?', store);
 * ```
 */
export class Processor {
    constructor(
        private readonly jev: Jev | null = null,
        private readonly config: Config = new Config(),
    ) {}

    /**
     * Text in any format: a data format (`yaml`, `json`, `jsonl`, `xml`, `toml`) as markup, `csv`
     * as a table, and a document format read into blocks and ingested as prose with `profile`.
     * Bytes that are not valid UTF-8 are decoded as Windows-1252 first. DOCX is binary: given as
     * bytes it is read as a package (messages name it by `id`), given as a string it is refused.
     *
     * @throws {ContextAtlasError} on XLSX, on DOCX given as a string, or on an unknown format
     */
    async ingest(input: string | Uint8Array, id: string, format: string, profile: string | null = null, title: string | null = null): Promise<Document> {
        if (format === 'docx') {
            if (typeof input === 'string') {
                throw new ContextAtlasError('DOCX is read from a file: use ingestFile.');
            }

            return this.ingestBlocks(parseDocxBytes(input, id), id, profile);
        }

        const text = typeof input === 'string' ? input : utf8OrWindows1252(input);

        if (DATA_FORMATS.includes(format)) {
            return this.ingestMarkup(text, id, format, title);
        }
        if (format === 'csv') {
            return this.ingestCsv(text, id, title ?? id);
        }
        if (format === 'xlsx') {
            throw new ContextAtlasError('XLSX is read from a file: use ingestFile or ingestSpreadsheet.');
        }

        return this.ingestBlocks(readBlocks(text, format), id, profile);
    }

    /**
     * A file in the format its extension names.
     *
     * @throws {ContextAtlasError} when the extension names no format or the file cannot be read
     */
    async ingestFile(path: string, id: string, profile: string | null = null, title: string | null = null): Promise<Document> {
        const format = formatOf(path);

        if (format === null) {
            throw new ContextAtlasError(`Cannot tell the format of ${basename(path)} from its extension; call ingest() with a format.`);
        }
        if (SPREADSHEET_FORMATS.includes(format)) {
            return this.ingestSpreadsheet(path, id, title ?? undefined);
        }
        if (format === 'docx') {
            return this.ingestBlocks(parseDocx(path), id, profile);
        }

        const bytes = readBytes(path);
        const chat = format === 'json' || format === 'jsonl' ? chatBlocks(utf8OrWindows1252(bytes)) : null;

        return chat === null ? this.ingest(bytes, id, format, profile, title) : this.ingestBlocks(chat, id, profile);
    }

    /**
     * CSV and XLSX files, chosen by extension, as one document, so lookup tables in one file join
     * onto data in another. Pass one path, or several keyed by the sheet name each CSV takes; an
     * XLSX file contributes its own sheets. The title defaults to the first sheet name.
     *
     * @throws {ContextAtlasError} when a file cannot be read or is not CSV or XLSX
     */
    ingestSpreadsheet(paths: string | Readonly<Record<string, string>>, id: string, title?: string): Document {
        const named = typeof paths === 'string' ? { [basename(paths, extname(paths))]: paths } : paths;
        const sheets: Sheet[] = [];

        for (const [name, path] of Object.entries(named)) {
            const extension = extname(basename(path)).slice(1).toLowerCase();

            if (extension === 'xlsx') {
                sheets.push(...Grid.xlsx(path).sheets);
            } else if (extension === 'csv' || extension === 'txt') {
                sheets.push(...Grid.csv(readBytes(path), name).sheets);
            } else {
                throw new ContextAtlasError(`Cannot read .${extension} as a spreadsheet; give a .csv or .xlsx file.`);
            }
        }

        return ingestGrid(new Grid(sheets), id, title ?? Object.keys(named)[0] ?? '');
    }

    /**
     * A YAML or JSON file as path subtrees linked by `$ref` and YAML aliases (spec/markup.md).
     * `format` is `yaml` or `json`; `title` defaults to the id. No Jev request is made.
     *
     * @throws {ContextAtlasError} on another format or invalid JSON
     */
    ingestMarkup(text: string, id: string, format: string, title: string | null = null): Document {
        return ingestMarkupText(text, id, format, title ?? id, this.config.charsPerToken);
    }

    /** CSV text or bytes as a one-sheet document whose sheet takes the title. */
    ingestCsv(csv: string | Uint8Array, id: string, title: string): Document {
        return ingestGrid(Grid.csv(csv, title), id, title);
    }

    /**
     * Prose blocks as passages chunked by Chunker, each section given a profile (detected by Jev,
     * or `profile` for every section) and the links its profile uses (spec/prose.md).
     *
     * @throws {ContextAtlasError} on a profile that does not exist
     */
    async ingestBlocks(blocks: readonly Block[], id: string, profile: string | null = null): Promise<Document> {
        return ingestProse(this.jev, this.config, blocks, id, profile);
    }

    /**
     * Blocks added to the end of a prose document ingested earlier, asking Jev only about the new
     * blocks: turns that continue the conversation the document ends in link back to its
     * messages, and other blocks are ingested as prose and numbered after the document's units.
     *
     * @throws {ContextAtlasError} on a markup or tabular document, or a profile that does not exist
     */
    async append(document: Document, blocks: readonly Block[], profile: string | null = null): Promise<Document> {
        return appendProse(this.jev, this.config, document, blocks, profile);
    }

    /**
     * The context that answers a question, or null when Jev did not answer. Candidates come from
     * exact-term search over the source, fused with `nearest` by reciprocal rank when given, or
     * are every unit of `documents`. Tables among them go to the tabular planner; passages are
     * reranked.
     *
     * @throws {ContextAtlasError} when the Processor has no Jev
     */
    async retrieve(query: string, source: Source, options: RetrieveOptions = {}): Promise<Context | null> {
        if (this.jev === null) {
            throw new ContextAtlasError('Retrieval needs Jev: pass a TypeSafeClient or a hook to the Processor.');
        }

        const documents = options.documents ?? null;
        const candidates = await this.candidates(query, source, options.nearest ?? null, documents);
        const tables = (documents === null ? candidates : await everyUnit(source, documents)).filter((c) => c.unit.kind === 'table').slice(0, MAX_TABLES);
        const passages = candidates.filter((c) => c.unit.kind !== 'table');

        if (tables.length > 0) {
            const context = await this.fromTables(query, tables, this.jev);

            if (context === null || !context.abstained || passages.length === 0) {
                return context;
            }
        }

        return passages.length === 0 ? Context.abstain({ reason: 'no candidates' }) : this.fromPassages(query, passages, this.jev);
    }

    private async fromTables(query: string, candidates: readonly Candidate[], jev: Jev): Promise<Context | null> {
        const byKey = new Map<string, Candidate>();
        const tables = candidates.map((candidate) => {
            const table = tableOf(candidate);
            const key = `${candidate.document.id}\0${table.id}`;
            byKey.set(key, candidate);

            return { ...table, id: key };
        });

        const planned = await new Planner(jev).plan(query, tables);

        if (planned === null) {
            return null;
        }
        if (planned.table === null) {
            return Context.abstain(planned.trace);
        }

        const candidate = byKey.get(planned.table.id)!;
        const table = tableOf(candidate);
        const result = planned.plan === null ? meaningOf(table) : runPlan(table, planned.plan);

        return new Context(
            result.text,
            [{ document: candidate.document.id, unit: candidate.unit.id, rows: result.sources }],
            false,
            { ...planned.trace, table: `${candidate.document.id}#${table.id}` },
        );
    }

    private async fromPassages(query: string, candidates: readonly Candidate[], jev: Jev): Promise<Context | null> {
        const result = await new ProseRetriever(jev, this.config).retrieve(query, candidates);

        if (result === null) {
            return null;
        }
        if (result.units.length === 0) {
            return Context.abstain({ reason: 'no passage answers the question', ...result.trace });
        }

        const [units, supersededBy] = withCorrections(result.units);

        return new Context(
            units.map((c) => c.unit.text + (supersededBy.has(key(c)) ? `\n${SUPERSEDED}` : '')).join('\n\n'),
            units.map((c) => ({ document: c.document.id, unit: c.unit.id, ...(supersededBy.has(key(c)) ? { superseded_by: supersededBy.get(key(c))! } : {}) })),
            false,
            result.trace,
        );
    }

    /**
     * Exact-term hits fused with the caller's nearest units by reciprocal rank. With `documents`,
     * both lists are searched ten times as deep and kept to those documents.
     */
    private async candidates(query: string, source: Source, nearest: Nearest | null, documents: readonly string[] | null): Promise<Candidate[]> {
        const limit = documents === null ? CANDIDATES : CANDIDATES * 10;
        let lists: (readonly UnitReference[])[] = [(await searchLexical(source, query, limit, null, documents)).map((hit) => ({ document: hit.document, unit: hit.unit }))];

        if (nearest !== null) {
            lists.push(await nearest(query, limit, null, documents));
        }
        if (documents !== null) {
            lists = lists.map((list) => list.filter((hit) => documents.includes(hit.document)));
        }

        const scores = new Map<string, number>();

        for (const list of lists) {
            list.forEach((hit, rank) => {
                const key = `${hit.document}\0${hit.unit}`;
                scores.set(key, (scores.get(key) ?? 0) + 1 / (FUSION_K + rank + 1));
            });
        }

        const out: Candidate[] = [];

        for (const [key] of [...scores].sort(([, a], [, b]) => b - a).slice(0, CANDIDATES)) {
            const [documentId = '', unitId = ''] = key.split('\0');
            const document = await source.document(documentId);
            const unit = document?.unit(unitId) ?? null;

            if (document !== null && unit !== null) {
                out.push({ document, unit });
            }
        }

        return out;
    }
}

/**
 * Each kept unit that a later unit `corrects` brings the last unit of that chain, the one no other
 * unit corrects, and is marked superseded by it. Returns the units in document order, and the
 * superseded units' keys mapped to the latest unit's id.
 */
function withCorrections(units: readonly Candidate[]): [Candidate[], Map<string, string>] {
    const added: Candidate[] = [];
    const supersededBy = new Map<string, string>();

    for (const candidate of units) {
        const latest = latestCorrection(candidate.document, candidate.unit.id);

        if (latest !== null) {
            supersededBy.set(key(candidate), latest.id);
            added.push({ document: candidate.document, unit: latest });
        }
    }

    return [inDocumentOrder(unique([...units, ...added])), supersededBy];
}

/** Follows `corrects` links from a unit to the units that correct it, taking the latest corrector in document order at each step, until no unit corrects the last one. */
function latestCorrection(document: Document, start: string): Unit | null {
    const order = new Map(document.units.map((unit, i) => [unit.id, i]));
    const seen = new Set([start]);
    let id = start;
    let latest: Unit | null = null;

    for (;;) {
        let next: string | null = null;

        for (const link of document.links) {
            if (link.type === 'corrects' && link.to === id && order.has(link.from) && !seen.has(link.from) && (next === null || order.get(link.from)! > order.get(next)!)) {
                next = link.from;
            }
        }
        if (next === null) {
            return latest;
        }
        seen.add(next);
        id = next;
        latest = document.unit(next);
    }
}

async function everyUnit(source: Source, documents: readonly string[]): Promise<Candidate[]> {
    const out: Candidate[] = [];

    for (const id of documents) {
        const document = await source.document(id);

        for (const unit of document?.units ?? []) {
            out.push({ document: document!, unit });
        }
    }

    return out;
}

/** A stored table; its `columns` is a list in column order. */
function tableOf(candidate: Candidate): Table {
    const tables = candidate.document.data['tables'] as Table[];

    return tables[Number(candidate.unit.meta['table'])]!;
}

/**
 * The blocks of a JSON or JSON Lines file the chat reader reads into at least two messages: a chat
 * export saved as `.json` is a conversation, not data.
 */
function chatBlocks(text: string): Block[] | null {
    let blocks: Block[];

    try {
        blocks = parseChat(text);
    } catch (error) {
        if (error instanceof ContextAtlasError) {
            return null;
        }
        throw error;
    }

    const messages = blocks.filter((block) => typeof block.meta['speaker'] === 'string' && block.meta['attachment'] !== true);

    return messages.length >= 2 ? blocks : null;
}

function readBytes(path: string): Uint8Array {
    try {
        if (statSync(path).isFile()) {
            return readFileSync(path);
        }
    } catch {
        // Reported below with the path.
    }

    throw new ContextAtlasError(`Cannot read ${path}.`);
}
