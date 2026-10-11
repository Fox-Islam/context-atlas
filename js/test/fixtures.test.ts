import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import type { TypeSafeClient } from '@typesafe-ai/sdk';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';
import { ingestGrid } from '../src/tabular/profile.js';
import { parseDocx } from '../src/parsing/docx.js';
import { readBlocks } from '../src/parsing/formats.js';
import {
    Block,
    Chunker,
    Collection,
    Document,
    Grid,
    Processor,
    Config,
    parseBlocks,
    parseMarkdown,
    parseText,
    Passage,
    Reranker,
    ContextAtlasError,
    type JevHook,
} from '../src/index.js';
import { failure, fakeClient, keyOrder, member, parseOrdered, type OrderedJson } from './support.js';

interface Call {
    request: { state: unknown; questions: unknown };
    response: unknown;
}

interface SpreadsheetInput {
    id: string;
    title: string;
    sheets: Record<string, { csv?: string; csv_base64?: string; xlsx?: string }>;
}

interface MarkupInput {
    id: string;
    markup: string;
    format: string;
    title?: string;
}

interface ProseInput {
    id: string;
    blocks: unknown[];
    config?: Record<string, unknown>;
    profile?: string;
}

interface AppendInput extends ProseInput {
    append: unknown[][];
}

interface Case {
    name: string;
    kind: 'chunk' | 'rank' | 'config' | 'blocks' | 'ingest' | 'retrieve' | 'prose-ingest' | 'prose-retrieve' | 'prose-append' | 'markup-ingest' | 'read';
    documents?: (SpreadsheetInput | ProseInput | MarkupInput)[];
    scope?: string[];
    format?: 'markdown' | 'text' | 'blocks';
    input?: unknown;
    query?: string;
    passages?: Record<string, unknown>[];
    limit?: number;
    jev?: boolean;
    config?: Record<string, unknown>;
    fail?: boolean;
    calls?: Call[];
    expected: unknown;
}

const fixtureText = readFileSync(new URL('../../../spec/fixtures.json', import.meta.url), 'utf8');
const fixtures = JSON.parse(fixtureText) as { cases: Case[] };
const orderedCases = member(parseOrdered(fixtureText), 'cases') as OrderedJson[];

/** The keys of every request a case recorded, in the order PHP sent them. */
function recordedKeyOrder(index: number): string[][] {
    return (member(orderedCases[index]!, 'calls') as OrderedJson[] | null ?? []).map((call) => keyOrder(member(call, 'request')));
}

/** The keys of the state and questions of each body sent, in the order sent. */
function sentKeyOrder(bodies: readonly string[]): string[][] {
    return bodies.map((body) => {
        const parsed = parseOrdered(body);

        return keyOrder({ entries: [['state', member(parsed, 'state')], ['questions', member(parsed, 'questions')]] });
    });
}

describe('spec/fixtures.json', () => {
    for (const fixture of fixtures.cases.filter((each) => each.kind === 'config' || each.kind === 'blocks')) {
        it(fixture.name, () => {
            assert.deepEqual(outcome(fixture), fixture.expected);
        });
    }

    for (const fixture of fixtures.cases.filter((each) => each.kind === 'ingest')) {
        it(fixture.name, () => {
            assertSameJson(ingest(fixture.input as SpreadsheetInput).toObject(), fixture.expected);
        });
    }

    for (const fixture of fixtures.cases.filter((each) => each.kind === 'retrieve')) {
        it(fixture.name, async () => {
            const calls = fixture.calls ?? [];
            const { client, requests, bodies } = fakeClient(
                calls.map((call) => (fixture.fail === true ? failure : { status: 200, body: call.response })),
            );
            const collection = new Collection((fixture.documents ?? []).map((input) => ingest(input as SpreadsheetInput)));
            const context = await new Processor(client).retrieve(fixture.query ?? '', collection, fixture.scope === undefined ? {} : { documents: fixture.scope });

            assertSameJson(
                requests.map((body) => ({ state: body['state'], questions: body['questions'] })),
                calls.map((call) => call.request),
            );
            assertSameJson(context === null ? null : context.toObject(), fixture.expected);
            assert.deepEqual(sentKeyOrder(bodies), recordedKeyOrder(fixtures.cases.indexOf(fixture)));
        });
    }

    for (const fixture of fixtures.cases.filter((each) => each.kind === 'read')) {
        it(fixture.name, () => {
            const input = fixture.input as { format: string; text?: string; file?: string };
            let output: unknown;

            try {
                const blocks = input.file !== undefined ? parseDocx(fileURLToPath(new URL(input.file, root))) : readBlocks(input.text ?? '', input.format);
                output = { blocks: blocks.map((block) => block.toObject()) };
            } catch (error) {
                if (!(error instanceof ContextAtlasError)) {
                    throw error;
                }
                output = { error: error.message };
            }

            assertSameJson(output, fixture.expected);
        });
    }

    for (const fixture of fixtures.cases.filter((each) => each.kind === 'markup-ingest')) {
        it(fixture.name, () => {
            assertSameJson(markup(fixture.input as MarkupInput).toObject(), fixture.expected);
        });
    }

    for (const fixture of fixtures.cases.filter((each) => each.kind === 'prose-ingest' || each.kind === 'prose-retrieve' || each.kind === 'prose-append')) {
        it(fixture.name, async () => {
            const calls = fixture.calls ?? [];
            const { client, requests, bodies } = fakeClient(
                calls.map((call) => (fixture.fail === true ? failure : { status: 200, body: call.response })),
            );
            let output: unknown;

            if (fixture.kind === 'prose-ingest') {
                output = (await prose(fixture.input as ProseInput, fixture.jev === false ? null : client)).toObject();
            } else if (fixture.kind === 'prose-append') {
                output = (await proseAppend(fixture.input as AppendInput, client)).toObject();
            } else {
                const documents: Document[] = [];

                for (const input of fixture.documents ?? []) {
                    documents.push('markup' in input ? markup(input as MarkupInput) : await prose(input as ProseInput, client));
                }

                const context = await new Processor(client).retrieve(fixture.query ?? '', new Collection(documents), fixture.scope === undefined ? {} : { documents: fixture.scope });
                output = context === null ? null : context.toObject();
            }

            assertSameJson(requests.map((body) => ({ state: body['state'], questions: body['questions'] })), calls.map((call) => call.request));
            assertSameJson(output, fixture.expected);
            assert.deepEqual(sentKeyOrder(bodies), recordedKeyOrder(fixtures.cases.indexOf(fixture)));
        });
    }

    for (const fixture of fixtures.cases.filter((each) => each.kind === 'chunk' || each.kind === 'rank')) {
        it(fixture.name, async () => {
            const calls = fixture.calls ?? [];
            const { client, requests, bodies } = fakeClient(
                calls.map((call) => (fixture.fail === true ? failure : { status: 200, body: call.response })),
            );
            const config = Config.fromObject(fixture.config ?? {});
            let output: unknown;

            if (fixture.kind === 'rank') {
                const passages = (fixture.passages ?? []).map((passage) => Passage.fromObject(passage));
                const ranked = await new Reranker(client, config).rank(passages, fixture.query ?? '', fixture.limit ?? null);
                output = ranked === null ? null : ranked.map((item) => item.toObject());
            } else {
                output = (await new Chunker(fixture.jev === false ? null : client, config).chunk(blocksOf(fixture))).toObject();
            }

            assert.deepEqual(
                requests.map((body) => ({ state: body['state'], questions: body['questions'] })),
                calls.map((call) => call.request),
            );
            assert.deepEqual(output, fixture.expected);
            assert.deepEqual(sentKeyOrder(bodies), recordedKeyOrder(fixtures.cases.indexOf(fixture)));
        });
    }
});

describe('a hook in place of a client', () => {
    const rankCase = fixtures.cases.find((fixture) => fixture.name === 'rank by answer, with the cross-check pick first')!;
    const rankCalls = rankCase.calls ?? [];
    const passages = (rankCase.passages ?? []).map((passage) => Passage.fromObject(passage));

    it('is handed the request and sends it itself', async () => {
        const { client, requests } = fakeClient([{ status: 200, body: rankCalls[0]!.response }]);
        const seen: unknown[] = [];
        const hook: JevHook = (request) => {
            seen.push(request.state);

            return client.systemOne(request);
        };

        const ranked = await new Reranker(hook).rank(passages, rankCase.query ?? '');

        assert.equal(seen.length, 1);
        assert.deepEqual(seen[0], rankCalls[0]!.request.state);
        assert.deepEqual(requests[0]!['questions'], rankCalls[0]!.request.questions);
        assert.deepEqual(ranked?.map((item) => item.toObject()), rankCase.expected);
    });

    it('that throws is an unanswered call', async () => {
        const hook: JevHook = () => {
            throw new Error('down');
        };

        assert.equal(await new Reranker(hook).rank(passages, 'query'), null);
    });

    it('that returns null leaves the chunks structural', async () => {
        const text = 'Invoices go out monthly and are due in fourteen days. '.repeat(10)
            + '\n\n' + 'Either party may cancel with thirty days notice. '.repeat(10);
        let calls = 0;
        const hook: JevHook = () => {
            calls++;

            return null;
        };

        const chunked = await new Chunker(hook).chunk(parseText(text));

        assert.equal(calls, 1);
        assert.equal(chunked.usedJev, false);
        assert.equal(chunked.chunks.length, 1);
    });
});

/** A config or block-list case's result: the values or blocks read, or the message of the error thrown. */
function outcome(fixture: Case): unknown {
    try {
        return fixture.kind === 'config'
            ? { values: Config.fromObject(fixture.input as Record<string, unknown>).toObject() }
            : { blocks: parseBlocks(String(fixture.input)).map((block) => block.toObject()) };
    } catch (error) {
        if (!(error instanceof ContextAtlasError)) {
            throw error;
        }

        return { error: error.message };
    }
}

const root = new URL('../../../', import.meta.url);

function markup(input: MarkupInput): Document {
    return new Processor().ingestMarkup(input.markup, input.id, input.format, input.title ?? null);
}

async function prose(input: ProseInput, jev: TypeSafeClient | null): Promise<Document> {
    return new Processor(jev, Config.fromObject(input.config ?? {})).ingestBlocks(input.blocks.map((block) => Block.fromObject(block)), input.id, input.profile ?? null);
}

/** Ingests the blocks, then appends each list in `append` in turn. */
async function proseAppend(input: AppendInput, jev: TypeSafeClient): Promise<Document> {
    const processor = new Processor(jev, Config.fromObject(input.config ?? {}));
    let document = await prose(input, jev);

    for (const blocks of input.append) {
        document = await processor.append(document, blocks.map((block) => Block.fromObject(block)), input.profile ?? null);
    }

    return document;
}

function ingest(input: SpreadsheetInput): Document {
    const sheets = Object.entries(input.sheets).flatMap(([name, source]) => {
        if (source.xlsx !== undefined) {
            return Grid.xlsx(fileURLToPath(new URL(source.xlsx, root))).sheets;
        }

        return Grid.csv(source.csv_base64 !== undefined ? Buffer.from(source.csv_base64, 'base64') : source.csv ?? '', name).sheets;
    });

    return ingestGrid(new Grid(sheets), input.id, input.title);
}

/**
 * Deep equality after a JSON round trip, with an empty `[]` and `{}` alike: PHP writes an empty
 * map as `[]`.
 */
function assertSameJson(actual: unknown, expected: unknown): void {
    assert.deepEqual(normalised(JSON.parse(JSON.stringify(actual))), normalised(expected));
}

function normalised(value: unknown): unknown {
    if (Array.isArray(value)) {
        return value.length === 0 ? {} : value.map(normalised);
    }
    if (typeof value === 'object' && value !== null) {
        return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, normalised(item)]));
    }

    return value;
}

function blocksOf(fixture: Case): Block[] {
    switch (fixture.format) {
        case 'markdown':
            return parseMarkdown(String(fixture.input));
        case 'text':
            return parseText(String(fixture.input));
        default:
            return (fixture.input as unknown[]).map((block) => Block.fromObject(block));
    }
}
