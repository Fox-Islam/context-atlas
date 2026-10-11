import { Block, BlockType } from '../block.js';
import { ContextAtlasError } from '../errors.js';
import { squash, trim, WHITESPACE } from '../text.js';
import { parseMarkdown } from './markdown.js';

const NOT_A_NOTEBOOK = 'A notebook must be a JSON object with a "cells" list.';

const ANSI = /\x1B\[[0-9;?]*[A-Za-z]/g;

const TRAILING_SPACE = new RegExp(`${WHITESPACE}+$`, 'u');

type JsonObject = Record<string, unknown>;

/**
 * Reads a Jupyter notebook (nbformat 4 JSON) cell by cell: markdown cells as Markdown, code cells
 * as code followed by their text outputs, raw cells as paragraphs. Images and other rich outputs
 * are dropped (spec/formats/notebook.md).
 *
 * @throws {ContextAtlasError} when the text is not a JSON object with a `cells` list
 */
export function parseNotebook(text: string): Block[] {
    let notebook: unknown;

    try {
        notebook = decode(text);
    } catch {
        throw new ContextAtlasError(NOT_A_NOTEBOOK);
    }

    if (!isObject(notebook) || !Array.isArray(notebook['cells'])) {
        throw new ContextAtlasError(NOT_A_NOTEBOOK);
    }

    return notebook['cells'].flatMap((cell: unknown) => (isObject(cell) && typeof cell['cell_type'] === 'string' ? cellBlocks(cell) : []));
}

/**
 * JSON as PHP's `json_decode` accepts it: containers nested at most 511 deep, and no object key
 * that starts with a NUL character.
 *
 * @throws {Error} on anything else
 */
function decode(text: string): unknown {
    let depth = 0;
    let deepest = 0;
    let quoted = false;

    for (let i = 0; i < text.length; i++) {
        const c = text[i];

        if (quoted) {
            if (c === '\\') {
                i++;
            } else if (c === '"') {
                quoted = false;
            }
        } else if (c === '"') {
            quoted = true;
        } else if (c === '[' || c === '{') {
            deepest = Math.max(deepest, ++depth);
        } else if (c === ']' || c === '}') {
            depth--;
        }
    }
    if (deepest >= 512) {
        throw new Error('Maximum stack depth exceeded');
    }

    return JSON.parse(text, (key: string, value: unknown) => {
        if (key.startsWith('\u0000')) {
            throw new Error('The decoded property name is invalid');
        }

        return value;
    });
}

/** A decoded JSON object: PHP's `json_decode` gives these as objects and JSON lists as arrays. */
function isObject(value: unknown): value is JsonObject {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function cellBlocks(cell: JsonObject): Block[] {
    const source = joined(cell['source'] ?? '');

    switch (cell['cell_type']) {
        case 'markdown':
            return parseMarkdown(source);
        case 'raw':
            return paragraphs(source);
        case 'code':
            return [...code(source), ...outputs(cell['outputs'] ?? [])];
        default:
            return [];
    }
}

function paragraphs(source: string): Block[] {
    return lines(source).split(/\n[ \t]*\n/).map(squash).filter((piece) => piece !== '').map((piece) => Block.paragraph(piece));
}

function code(text: string, prefix = ''): Block[] {
    const normalised = lines(text);

    return trim(normalised) === '' ? [] : [new Block(BlockType.Code, prefix + normalised)];
}

function outputs(value: unknown): Block[] {
    const blocks: Block[] = [];

    for (const output of Array.isArray(value) ? value : []) {
        const type = isObject(output) ? output['output_type'] : null;

        if (!isObject(output)) {
            continue;
        }
        if (type === 'stream') {
            blocks.push(...outputText(joined(output['text'] ?? '')));
        } else if (type === 'execute_result' || type === 'display_data') {
            const data = output['data'];
            blocks.push(...outputText(isObject(data) ? joined(data['text/plain'] ?? '') : ''));
        } else if (type === 'error') {
            blocks.push(...errorText(joined(output['ename'] ?? ''), joined(output['evalue'] ?? '')));
        }
    }

    return blocks;
}

function errorText(name: string, value: string): Block[] {
    const ename = trim(name.replace(ANSI, ''));
    const evalue = trim(lines(value).replace(ANSI, ''));

    return code(evalue === '' ? ename : ename === '' ? evalue : `${ename}: ${evalue}`);
}

function outputText(text: string): Block[] {
    return code(lines(text).replace(ANSI, '').replace(TRAILING_SPACE, ''), 'Output:\n');
}

/** A source or text field: a string, or a list of strings joined with nothing between them. */
function joined(value: unknown): string {
    if (typeof value === 'string') {
        return value;
    }

    return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string').join('') : '';
}

function lines(text: string): string {
    return text.replace(/\r\n|\r/g, '\n');
}
