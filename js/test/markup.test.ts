import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { Processor, ContextAtlasError } from '../src/index.js';
import { readJson } from '../src/markup/jsonOutline.js';
import { readYaml } from '../src/markup/yamlOutline.js';

describe('markup outlines', () => {
    it('prints JSON in one layout with numbers as written', () => {
        assert.deepEqual(readJson('{"a":1.0,"b":[]}').lines, ['{', '  "a": 1.0,', '  "b": []', '}']);
    });

    it('names the UTF-8 byte offset of invalid JSON', () => {
        assert.throws(() => readJson('{"é": tru}'), new ContextAtlasError('Invalid JSON at byte 7.'));
    });

    it('reads YAML keys, items opening with a key, anchors and aliases', () => {
        const outline = readYaml('base: &b\n  x: 1\nlist:\n- name: one\n  ref: *b\n');

        assert.deepEqual(outline.entries.map((entry) => [entry.path.join('/'), entry.start, entry.end, entry.value, entry.alias]), [
            ['', 0, 6, null, null],
            ['base', 0, 2, null, null],
            ['base/x', 1, 2, '1', null],
            ['list', 2, 5, null, null],
            ['list/[0]', 3, 5, null, null],
            ['list/[0]/name', 3, 4, 'one', null],
            ['list/[0]/ref', 4, 5, null, 'b'],
        ]);
        assert.deepEqual(outline.anchors.get('b'), ['base']);
    });

    it('refuses a format that is not a data format', () => {
        assert.throws(() => new Processor().ingestMarkup('a: 1', 'x', 'ini'), /Cannot read "ini" as markup; use yaml, json, jsonl, xml or toml\./);
    });
});
