import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtempSync, readdirSync, readFileSync, rmSync, unlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, it } from 'node:test';
import { Collection, Document, FileStore, Unit } from '../src/index.js';
import { buildLexical, searchLexical } from '../src/store/lexical.js';

const sha256 = (text: string): string => createHash('sha256').update(text).digest('hex');

function documentOf(id: string, texts: string[]): Document {
    const units = texts.map((text, i) => new Unit(`p${i + 1}`, 'passage', text));

    return new Document(id, 'guidance', units, [], buildLexical(units));
}

function withStore(test: (directory: string, store: FileStore) => void | Promise<void>): () => Promise<void> {
    return async () => {
        const directory = mkdtempSync(join(tmpdir(), 'stp-index-'));

        try {
            await test(directory, new FileStore(directory));
        } finally {
            rmSync(directory, { recursive: true, force: true });
        }
    };
}

describe('FileStore term index', () => {
    it('writes stats and shards in the shared layout, versioning rewrites', withStore((directory, store) => {
        store.put(documentOf('a', ['rail payment 2019']));
        store.put(documentOf('a', ['rail']));

        const hash = sha256('a');
        const shard = sha256('rail').slice(0, 3);

        assert.equal(readFileSync(join(directory, 'index', 'stats.json'), 'utf8'), `{"${hash}":["a",1,1,2]}`);
        assert.equal(readFileSync(join(directory, 'index', `${shard}.tsv`), 'utf8'), `rail\t${hash.slice(0, 16)}\t1\tp1:1:3\nrail\t${hash.slice(0, 16)}\t2\tp1:1:1\n`);
        assert.deepEqual(store.postings(['rail', 'payment']), new Map([['rail', [{ document: 'a', unit: 'p1', count: 1, length: 1 }]], ['payment', []]]));

        store.compact();
        assert.equal(readFileSync(join(directory, 'index', `${shard}.tsv`), 'utf8'), `rail\t${hash.slice(0, 16)}\t2\tp1:1:1\n`);
    }));

    it('lists postings in document-hash order and drops removed documents', withStore((_directory, store) => {
        for (const id of ['one', 'two', 'three']) {
            store.put(documentOf(id, [`rail ${id}`]));
        }
        store.remove('two');

        const expected = ['one', 'three'].sort((a, b) => (sha256(a) < sha256(b) ? -1 : 1));

        assert.deepEqual(store.postings(['rail']).get('rail')!.map((posting) => posting.document), expected);
        assert.deepEqual(store.stats(), { units: 2, length: 4 });
        assert.equal(store.document('two'), null);
    }));

    it('indexes a store with document files and no index on first use', withStore((directory, store) => {
        store.put(documentOf('a', ['network rail']));
        rmSync(join(directory, 'index'), { recursive: true });

        const fresh = new FileStore(directory);

        assert.deepEqual(fresh.stats(), { units: 1, length: 2 });
        assert.ok(readdirSync(join(directory, 'index')).includes('stats.json'));
        assert.equal(fresh.postings(['network']).get('network')!.length, 1);
    }));

    it('leaves a line out when its document file is gone but stats still name it', withStore((directory, store) => {
        store.put(documentOf('a', ['rail']));
        unlinkSync(join(directory, `${sha256('a')}.json`));

        assert.equal(store.postings(['rail']).get('rail')!.length, 1);
        assert.equal(new FileStore(directory).document('a'), null);
    }));
});

describe('exact-term search within documents', () => {
    it('scores only the named documents, weighting terms over the whole source', async () => {
        const documents = [documentOf('a', ['rail fare', 'bus']), documentOf('b', ['rail rail fare'])];
        const source = new Collection(documents);
        const all = await searchLexical(source, 'rail fare', 10);
        const onlyB = await searchLexical(source, 'rail fare', 10, null, ['b']);

        assert.deepEqual(onlyB, all.filter((hit) => hit.document === 'b'));
    });
});
