import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';
import { Application, VERSION } from '../src/console/application.js';
import type { Jev } from '../src/index.js';
import { failure, fakeClient } from './support.js';

const dir = mkdtempSync(join(tmpdir(), 'stp-'));
const bin = fileURLToPath(new URL('../bin/context-atlas.js', import.meta.url));

after(() => rmSync(dir, { recursive: true, force: true }));

function file(name: string, contents: string): string {
    const path = join(dir, name);
    writeFileSync(path, contents);

    return path;
}

async function run(argv: string[], jev: Jev = () => null, stdin = ''): Promise<{ code: number; out: string; err: string }> {
    let out = '';
    let err = '';
    const code = await new Application({
        jev,
        stdout: { write: (text: string) => (out += text) },
        stderr: { write: (text: string) => (err += text) },
        stdin: () => stdin,
    }).run(argv);

    return { code, out, err };
}

describe('chunk', () => {
    it('reads a Markdown file and prints the chunks as JSON', async () => {
        const { code, out, err } = await run(['chunk', file('doc.md', '# Fees\n\nInvoices go out monthly.\n'), '--no-jev']);

        assert.equal(code, 0);
        assert.equal(err, '');
        assert.equal(out, '{"chunks":[{"text":"[Fees]\\n\\nInvoices go out monthly.","blocks":[0,1],"heading_path":["Fees"],'
            + '"heading_level":1,"section":1,"tokens":8,"overlap":0,"topic_start":false}],"outline":[{"block":0,"title":"Fees","level":1}],"used_jev":false}\n');
    });

    it('reads a .json file as blocks', async () => {
        const path = file('blocks.json', '[{"type":"heading","text":"A","level":2},{"type":"paragraph","text":"Body."}]');
        const { code, out } = await run(['chunk', path, '--no-jev']);

        assert.equal(code, 0);
        assert.equal(JSON.parse(out).outline[0].level, 2);
    });

    it('reads a .txt file as plain text, and stdin for -', async () => {
        const fromFile = await run(['chunk', file('doc.txt', '# Not a heading\n\nSecond.'), '--no-jev']);
        const fromStdin = await run(['chunk', '-', '--no-jev', '--format=text'], () => null, '# Not a heading\n\nSecond.');

        assert.deepEqual(JSON.parse(fromFile.out).outline, []);
        assert.equal(fromStdin.out, fromFile.out);
    });

    it('pretty-prints with four spaces and leaves Unicode and slashes unescaped', async () => {
        const { out } = await run(['chunk', '-', '--no-jev', '--pretty'], () => null, 'Café / bar');

        assert.ok(out.startsWith('{\n    "chunks": [\n        {\n            "text": "Café / bar",'));
        assert.ok(out.endsWith('\n    "used_jev": false\n}\n'));
    });

    it('says on stderr when Jev was not used', async () => {
        const { code, err } = await run(['chunk', file('short.md', 'One line.')]);

        assert.equal(code, 0);
        assert.equal(err, 'Jev was not used: the document had no long sections, or Jev did not answer. The chunks follow headings and sizes only.\n');
    });

    it('takes a config file and refuses an unknown key', async () => {
        const good = await run(['chunk', '-', '--no-jev', `--config=${file('good.json', '{"chars_per_token": 1}')}`], () => null, 'Four');
        const bad = await run(['chunk', '-', '--no-jev', `--config=${file('bad-config.json', '{"max_token": 1}')}`], () => null, 'Four');

        assert.equal(JSON.parse(good.out).chunks[0].tokens, 4);
        assert.deepEqual([bad.code, bad.out, bad.err], [1, '', 'Unknown config key "max_token".\n']);
    });
});

describe('rank', () => {
    const chunks = file('chunks.json', JSON.stringify({
        chunks: [
            { text: 'Delivery takes three days.', heading_path: ['Shipping'] },
            { text: 'Returns are accepted for 30 days.', heading_path: ['Returns'] },
        ],
    }));

    it('takes the output of chunk, numbering passages without an id', async () => {
        const { client, requests } = fakeClient([{
            status: 200,
            body: {
                model: 'jev-latest',
                answers: {
                    'c_shipping-0': { type: 'noul', noul: 0.1 },
                    'c_returns-1': { type: 'noul', noul: 1 },
                    best: { type: 'choice', choice: 'returns-1', confidence: 0.9, probabilities: { 'shipping-0': 0.1, 'returns-1': 0.9 } },
                },
                usage: { input_tokens: 1, output_tokens: 1 },
            },
        }]);

        const { code, out } = await run(['rank', 'How long can I return things?', chunks, '--limit=1'], client);

        assert.equal(code, 0);
        assert.equal(out, '[{"id":"1","score":1.0,"text":"Returns are accepted for 30 days."}]\n');
        assert.equal(requests.length, 1);
    });

    it('keeps the id a passage has', async () => {
        const { client } = fakeClient([{ status: 200, body: { answers: { 'c_passage-1-0': { type: 'noul', noul: 0.9 } } } }]);
        const { out } = await run(['rank', 'q', file('ids.json', '[{"id": "a-7", "text": "x"}]')], client);

        assert.deepEqual(JSON.parse(out), [{ id: 'a-7', score: 0.9, text: 'x' }]);
    });

    it('exits 2 when Jev does not answer', async () => {
        const { client, requests } = fakeClient([failure]);
        const { code, out, err } = await run(['rank', 'q', file('p.json', '[{"id":"a","text":"x"}]')], client);

        assert.deepEqual([code, out, err], [2, '', 'Jev did not answer. Check TYPESAFE_API_KEY.\n']);
        assert.equal(requests.length, 1);
    });

    it('needs a query', async () => {
        assert.deepEqual(await run(['rank']), { code: 1, out: '', err: 'rank needs a QUERY.\n' });
    });
});

describe('ingest and retrieve', () => {
    const answers = {
        model: 'jev-latest',
        answers: {
            c_p0: { type: 'noul', noul: 0.9 },
            best: { type: 'choice', choice: 'p0', confidence: 1, probabilities: { p0: 1 } },
            scope: { type: 'choice', choice: 'one passage', confidence: 1, probabilities: { 'one passage': 1 } },
        },
        usage: { input_tokens: 1, output_tokens: 1 },
    };

    it('prints a stored document', async () => {
        const { code, out } = await run(['ingest', file('fees.md', '# Fees\n\nInvoices go out monthly.\n'), '--no-jev']);
        const document = JSON.parse(out);

        assert.deepEqual([code, document.format, document.id, document.profile], [0, 2, 'fees.md', 'guidance']);
    });

    it('saves into a store, and retrieve reads it', async () => {
        const store = join(dir, 'store-a');
        const saved = await run(['ingest', file('due.md', '# Fees\n\nInvoices go out monthly and are due within fourteen days.\n'), '--no-jev', '--id=fees', `--store=${store}`]);
        const summary = JSON.parse(saved.out);

        assert.deepEqual([saved.code, summary.id, summary.units, summary.store], [0, 'fees', 1, store]);

        const { client } = fakeClient(Array.from({ length: 5 }, () => ({ status: 200, body: answers })));
        const { code, out } = await run(['retrieve', 'When are invoices due?', `--store=${store}`, '--documents=fees'], client);
        const context = JSON.parse(out);

        assert.deepEqual([code, context.abstained, context.sources], [0, false, [{ document: 'fees', unit: 'p1' }]]);
    });

    it('needs an id and a format to read stdin', async () => {
        assert.deepEqual(await run(['ingest', '-', '--format=markdown']), { code: 1, out: '', err: 'Reading stdin needs --id.\n' });
        assert.deepEqual((await run(['ingest', '-', '--id=x'])).err, 'Reading stdin needs --format.\n');
    });

    it('exits 2 when Jev does not answer, and 1 when the store is not a directory', async () => {
        const store = join(dir, 'store-b');
        await run(['ingest', file('fees2.md', '# Fees\n\nInvoices go out monthly.\n'), '--no-jev', `--store=${store}`]);
        const { client } = fakeClient([failure]);
        const failed = await run(['retrieve', 'invoices', `--store=${store}`], client);
        const missing = await run(['retrieve', 'invoices', `--store=${join(dir, 'missing')}`]);

        assert.deepEqual([failed.code, failed.err], [2, 'Jev did not answer. Check TYPESAFE_API_KEY.\n']);
        assert.deepEqual([missing.code, missing.err], [1, `${join(dir, 'missing')} is not a directory.\n`]);
    });
});

describe('bad input and usage', () => {
    it('refuses {} as passages', async () => {
        assert.deepEqual(await run(['rank', 'q', file('empty.json', '{}')]), {
            code: 1,
            out: '',
            err: 'The passages must be a JSON list, or the output of `chunk`.\n',
        });
    });

    it('exits 1 with one line when the blocks are not a list', async () => {
        const { code, err } = await run(['chunk', file('bad.json', '{"not":"a list"}')]);

        assert.deepEqual([code, err], [1, 'The blocks must be a JSON list.\n']);
    });

    it('exits 1 on a file it cannot read, invalid JSON and an unknown format', async () => {
        const missing = join(dir, 'missing.md');

        assert.deepEqual(await run(['chunk', missing]), { code: 1, out: '', err: `Cannot read ${missing}.\n` });
        assert.deepEqual((await run(['rank', 'q', file('broken.json', '[')])).err, 'Not valid JSON.\n');
        assert.deepEqual((await run(['chunk', '-', '--format=pdf'])).err, '--format must be markdown, text or blocks.\n');
    });

    it('names an unknown command, then prints the usage', async () => {
        const { code, out, err } = await run(['split']);

        assert.equal(code, 1);
        assert.equal(out, '');
        assert.ok(err.startsWith('Unknown command "split".\n\nUsage:\n  context-atlas chunk [FILE|-]'));
    });

    it('prints the usage for help, --help, -h and no command', async () => {
        const outputs = await Promise.all([['help'], ['--help'], ['chunk', '-h'], []].map(async (argv) => run(argv)));

        for (const { code, out } of outputs) {
            assert.equal(code, 0);
            assert.ok(out.startsWith('Usage:\n'));
            assert.ok(out.endsWith('Exit codes: 0 done, 1 bad input, 2 Jev did not answer.\n'));
        }
    });

    it('prints the version from package.json', async () => {
        const manifest = JSON.parse(readFileSync(new URL('../../../package.json', import.meta.url), 'utf8')) as { version: string };

        assert.equal(VERSION, manifest.version);
        assert.deepEqual(await run(['--version']), { code: 0, out: `${VERSION}\n`, err: '' });
    });
});

describe('the bin', () => {
    const env = { ...process.env, TYPESAFE_API_KEY: '', OPENROUTER_API_KEY: '', TYPESAFE_PROVIDER: '' };

    it('chunks stdin and exits 0', () => {
        const result = spawnSync(process.execPath, [bin, 'chunk', '--no-jev'], { input: '# A\n\nB.', env, encoding: 'utf8' });

        assert.equal(result.status, 0);
        assert.deepEqual(JSON.parse(result.stdout).outline, [{ block: 0, title: 'A', level: 1 }]);
    });

    it('falls back to structural chunks without an API key', () => {
        const result = spawnSync(process.execPath, [bin, 'chunk'], { input: 'One line.', env, encoding: 'utf8' });

        assert.equal(result.status, 0);
        assert.match(result.stderr, /^Jev was not used/);
    });

    it('exits 2 for rank without an API key', () => {
        const result = spawnSync(process.execPath, [bin, 'rank', 'q'], { input: '[{"text": "x"}]', env, encoding: 'utf8' });

        assert.deepEqual([result.status, result.stdout, result.stderr], [2, '', 'Jev did not answer. Check TYPESAFE_API_KEY.\n']);
    });
});
