import { Block } from '../block.js';
import { ContextAtlasError } from '../errors.js';

/**
 * Blocks a caller built itself, as a JSON list of `{"type", "text", "level"?, "meta"?}`:
 * the way output from a PDF or HTML extractor comes in.
 *
 * @throws {ContextAtlasError} on invalid JSON, a value that is not a list, or a bad block
 */
export function parseBlocks(json: string): Block[] {
    let value: unknown;

    try {
        value = JSON.parse(json);
    } catch {
        throw new ContextAtlasError('The blocks are not valid JSON.');
    }

    if (!Array.isArray(value)) {
        throw new ContextAtlasError('The blocks must be a JSON list.');
    }

    return value.map((block) => Block.fromObject(block));
}
