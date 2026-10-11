import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { Chunker, Config, parseText } from '../src/index.js';

describe('packing', () => {
    it('does not emit an empty chunk when the first block is oversized and min_tokens is 0', async () => {
        const text = 'Invoices are issued on the first working day of each month. Payment is due within fourteen days by bank transfer.\n\nShort again.';
        const chunked = await new Chunker(null, new Config({ maxTokens: 20, minTokens: 0, overlapTokens: 0 })).chunk(parseText(text));

        assert.deepEqual(chunked.chunks.map((chunk) => [chunk.blocks, chunk.tokens, chunk.topicStart]), [[[0], 29, false], [[1], 3, false]]);
    });
});
