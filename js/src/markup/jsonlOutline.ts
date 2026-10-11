import { ContextAtlasError } from '../errors.js';
import { readJson } from './jsonOutline.js';
import type { Outline, OutlineEntry } from './outline.js';

/**
 * The records of a JSON Lines file as one key tree: each non-blank line is a JSON value read and
 * printed by the JSON outline, under the root as item `[i]`, i counting non-blank lines from 0.
 * The outline is marked `records`, so the records are cut in order with no root unit.
 *
 * @throws {ContextAtlasError} naming the line (from 1) of the first record that is not valid JSON
 */
export function readJsonl(text: string): Outline {
    const lines: string[] = [];
    const entries: OutlineEntry[] = [{ path: [], start: 0, end: 0, value: null, alias: null }];
    let item = 0;

    text.split('\n').forEach((line, n) => {
        if (line.replace(/^[ \t\r]+|[ \t\r]+$/g, '') === '') {
            return;
        }

        let record: Outline;

        try {
            record = readJson(line);
        } catch (error) {
            if (!(error instanceof ContextAtlasError)) {
                throw error;
            }
            throw new ContextAtlasError(`Invalid JSONL at line ${n + 1}: ${error.message}`);
        }

        const offset = lines.length;

        for (const entry of record.entries) {
            entries.push({ path: [`[${item}]`, ...entry.path], start: entry.start + offset, end: entry.end + offset, value: entry.value, alias: null });
        }
        lines.push(...record.lines);
        item++;
    });
    entries[0]!.end = lines.length;

    return { lines, entries, anchors: new Map(), records: true };
}
