import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, it } from 'node:test';
import { Collection, Document, FileStore, parseMarkdown, Processor, Unit, type JevHook } from '../src/index.js';
import { buildLexical, searchLexical } from '../src/store/lexical.js';
import { fakeClient } from './support.js';

/** Recorded from the PHP package: two guidance documents, their ingestion requests, the ranking request and the context it produced. */
const recorded = JSON.parse(readFileSync(new URL('../../../js/test/fixtures/passage-retrieval.json', import.meta.url), 'utf8')) as {
    documents: unknown[];
    calls: { request: { state: unknown; questions: unknown }; response: unknown }[];
    expected: unknown;
};

const GUIDE = '# Returns\n\nYou can return goods within 30 days of delivery.\n\n# Delivery\n\nDelivery takes three working days.\n\n## Costs\n\nDelivery is free over £50, and returns cost nothing.';

const FAQ = 'Refund questions\n\nRefunds go back to the original card within five days of the return arriving.';

describe('passage retrieval', () => {
    it('ingests blocks and retrieves passages as the PHP package does', async () => {
        const { client, requests } = fakeClient(recorded.calls.map((call) => ({ status: 200, body: call.response })));
        const processor = new Processor(client);
        const documents = [await processor.ingestBlocks(parseMarkdown(GUIDE), 'guide'), await processor.ingestBlocks(parseMarkdown(FAQ), 'faq')];

        assert.deepEqual(JSON.parse(JSON.stringify(documents)), recorded.documents);

        const context = await processor.retrieve('How many days do I have to return goods for a refund?', new Collection(documents));

        assert.deepEqual(requests.map((body) => ({ state: body['state'], questions: body['questions'] })), recorded.calls.map((call) => call.request));
        assert.deepEqual(context?.toObject(), recorded.expected);
    });

    it('abstains with no candidates, and refuses to retrieve without Jev', async () => {
        const hook: JevHook = () => null;
        const context = await new Processor(hook).retrieve('anything at all', new Collection());

        assert.deepEqual(context?.toObject(), { text: '', sources: [], abstained: true, trace: { reason: 'no candidates' } });
        await assert.rejects(new Processor().retrieve('q', new Collection()), /Retrieval needs Jev/);
    });

    it('fuses the caller\'s nearest units with exact-term hits by reciprocal rank', async () => {
        const seen: string[] = [];
        const hook: JevHook = (request) => {
            seen.push(...Object.values((request.state as Record<string, Record<string, string>>)['the passages the search found']!));

            return null;
        };
        const documents = recorded.documents.map((document) => Document.fromObject(document as Record<string, unknown>));

        await new Processor(hook).retrieve('return goods', new Collection(documents), {
            nearest: async (_query, limit, kinds) => {
                assert.deepEqual([limit, kinds], [20, null]);

                return [{ document: 'guide', unit: 'p2' }, { document: 'missing', unit: 'p1' }];
            },
        });

        const [guide, faq] = documents;

        assert.deepEqual(seen, [guide!.unit('p1')!.text, guide!.unit('p2')!.text, faq!.unit('p1')!.text]);
    });
});

describe('exact-term search', () => {
    it('ranks by BM25 and keeps first-met order on ties', async () => {
        const units = [new Unit('a', 'passage', 'alpha beta'), new Unit('b', 'passage', 'alpha beta'), new Unit('c', 'passage', 'gamma')];
        const document = new Document('d', 'guidance', units, [], buildLexical(units));
        const hits = await searchLexical(new Collection([document]), 'Beta alpha beta', 10);

        assert.deepEqual(hits.map((hit) => hit.unit), ['a', 'b']);
        assert.equal(hits[0]!.score, hits[1]!.score);
        assert.deepEqual(document.lexical.terms, { alpha: { a: 1, b: 1 }, beta: { a: 1, b: 1 }, gamma: { c: 1 } });
    });

    it('stores documents as files named by the hash of their id, and searches them', async () => {
        const directory = mkdtempSync(join(tmpdir(), 'stp-store-'));

        try {
            const store = new FileStore(join(directory, 'docs'));
            const units = [new Unit('p1', 'passage', 'Network Rail payments', ['Spend'])];
            store.put(new Document('id/with:odd chars', 'guidance', units, [], buildLexical(units)));

            assert.equal(store.document('id/with:odd chars')?.unit('p1')?.text, 'Network Rail payments');
            assert.deepEqual((await searchLexical(store, 'rail', 5)).map((hit) => hit.document), ['id/with:odd chars']);
            store.remove('id/with:odd chars');
            assert.equal(store.document('id/with:odd chars'), null);
        } finally {
            rmSync(directory, { recursive: true, force: true });
        }
    });

    it('refuses an artifact of another format', () => {
        assert.throws(() => Document.fromObject({ format: 1 }), /Not a format 2 document\./);
    });
});
