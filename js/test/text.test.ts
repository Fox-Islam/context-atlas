import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { encode, JsonFloat } from '../src/console/json.js';
import { length, slug, squash, tail, trim } from '../src/text.js';

describe('text rules', () => {
    it('counts code points, not UTF-16 units', () => {
        assert.equal(length('a😀é'), 3);
    });

    it('treats as whitespace what PHP\'s \\s with the u flag matches', () => {
        for (const space of ['\u0085', ' ', ' ', '᠎', ' ', ' ', ' ', ' ', '　']) {
            assert.equal(trim(`${space}x${space}`), 'x', `U+${space.codePointAt(0)!.toString(16)}`);
        }
        for (const other of ['﻿', '​', '\u001c']) {
            assert.equal(trim(`${other}x`), `${other}x`, `U+${other.codePointAt(0)!.toString(16)}`);
        }
        assert.equal(squash(' a \t\n b '), 'a b');
    });

    it('opens a tail at a sentence, else after the first whitespace, else keeps the slice', () => {
        assert.equal(tail('First one. Second one here.', 5, 4), 'Second one here.');
        assert.equal(tail('alpha beta gamma delta', 2, 4), 'delta');
        assert.equal(tail('abcdefghijkl', 1, 4), 'ijkl');
    });

    it('slugs with accents dropped and cut without a trailing hyphen', () => {
        assert.equal(slug('Café Déjà vu > Où sommes-nous ?', 60), 'cafe-deja-vu-ou-sommes-nous');
        assert.equal(slug('ab cd', 3), 'ab');
        assert.equal(slug('日本', 60), '');
    });
});

describe('JSON output', () => {
    it('writes floats as PHP json_encode does with JSON_PRESERVE_ZERO_FRACTION', () => {
        const values = [0.00001, 0.0001, 1.5e-7, 1, 0, 1e17, 1.23e20, 0.1 + 0.2].map((value) => new JsonFloat(value));

        assert.equal(encode(values, false), '[1.0e-5,0.0001,1.5e-7,1.0,0.0,1.0e+17,1.23e+20,0.30000000000000004]');
    });

    it('escapes U+2028 and U+2029 only', () => {
        assert.equal(encode('a/é  "', false), '"a/é\\u2028\\u2029\\""');
    });
});
