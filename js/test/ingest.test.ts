import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, it } from 'node:test';
import { formatOf, Processor, type Document } from '../src/index.js';

describe('ingest by format', () => {
    it('reads a document format into passages, and a data format as markup', async () => {
        const processor = new Processor();
        const page = await processor.ingest('<h1>Guide</h1><p>Read this.</p>', 'page', 'html');
        const data = await processor.ingest('a: 1', 'conf', 'yaml');

        assert.deepEqual([page.profile, page.units.map((unit) => unit.text)], ['guidance', ['[Guide]\n\nRead this.']]);
        assert.equal(data.profile, 'markup');
    });

    it('reads bytes that are not UTF-8 as Windows-1252', async () => {
        const document = await new Processor().ingest(new Uint8Array([...Buffer.from('<p>Caf'), 0xe9, 0x20, 0x80, ...Buffer.from(' 50</p>')]), 'p', 'html');

        assert.equal(document.units[0]!.text, 'Café € 50');
    });

    it('refuses XLSX and DOCX as text, and a format it does not know', async () => {
        await assert.rejects(new Processor().ingest('', 'x', 'xlsx'), /XLSX is read from a file: use ingestFile or ingestSpreadsheet\./);
        await assert.rejects(new Processor().ingest('', 'x', 'odt'), /"odt" is not a document format; use one of: markdown, /);
        await assert.rejects(new Processor().ingest('<w:document/>', 'x', 'docx'), /DOCX is read from a file: use ingestFile\./);
    });

    it('reads a file in the format its extension names', async () => {
        const directory = mkdtempSync(join(tmpdir(), 'stp-ingest-'));

        try {
            writeFileSync(join(directory, 'notes.org'), '* Heading\nSome text.\n');
            const document = await new Processor().ingestFile(join(directory, 'notes.org'), 'notes');

            assert.deepEqual(document.units.map((unit) => unit.path), [['Heading']]);
            assert.equal(formatOf('a/B.YML'), 'yaml');
            await assert.rejects(new Processor().ingestFile(join(directory, 'notes.odt'), 'x'), /Cannot tell the format of notes\.odt from its extension; call ingest\(\) with a format\./);
            await assert.rejects(new Processor().ingestFile(join(directory, 'notes.docx'), 'x'), /Cannot open notes\.docx as a DOCX document\./);
        } finally {
            rmSync(directory, { recursive: true, force: true });
        }
    });
});

describe('ingestFile with a chat export', () => {
    async function ingestWritten(name: string, contents: string): Promise<Document> {
        const directory = mkdtempSync(join(tmpdir(), 'stp-chat-'));

        try {
            writeFileSync(join(directory, name), contents);

            return await new Processor().ingestFile(join(directory, name), name);
        } finally {
            rmSync(directory, { recursive: true, force: true });
        }
    }

    it('reads a chat export saved as JSON as a conversation', async () => {
        const document = await ingestWritten('export.json', '{"messages": [{"role": "user", "content": "Where is the staging config?"}, {"role": "assistant", "content": "In deploy/staging.yaml."}]}');

        assert.equal(document.profile, 'conversational');
        assert.deepEqual(document.units.map((unit) => unit.kind), ['message', 'message']);
    });

    it('reads chat lines saved as JSON Lines as a conversation', async () => {
        const document = await ingestWritten('session.jsonl', '{"role": "user", "content": "Run the tests"}\n{"role": "assistant", "content": "All 12 pass."}\n');

        assert.equal(document.profile, 'conversational');
    });

    it('keeps JSON the chat reader does not recognise as data', async () => {
        const document = await ingestWritten('config.json', '{"service": "api", "replicas": 3, "data": [{"region": "eu-west-1"}]}');

        assert.equal(document.profile, 'markup');
    });

    it('keeps a single message as data', async () => {
        const document = await ingestWritten('one.json', '{"messages": [{"role": "user", "content": "Hello"}]}');

        assert.equal(document.profile, 'markup');
    });
});
