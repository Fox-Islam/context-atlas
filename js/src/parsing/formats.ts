import type { Block } from '../block.js';
import { ContextAtlasError } from '../errors.js';
import { parseAsciidoc } from './asciidoc.js';
import { parseBlocks } from './blocks.js';
import { parseChat } from './chat.js';
import { parseHtml } from './html.js';
import { parseLatex } from './latex.js';
import { parseMarkdown } from './markdown.js';
import { parseMdx } from './mdx.js';
import { parseNotebook } from './notebook.js';
import { parseOrg } from './org.js';
import { parseRst } from './rst.js';
import { parseSrt } from './srt.js';
import { parseText } from './text.js';
import { parseVtt } from './vtt.js';

/** Formats read into blocks for the prose profiles. */
export const DOCUMENT_FORMATS: readonly string[] = ['markdown', 'mdx', 'text', 'html', 'rst', 'asciidoc', 'org', 'latex', 'ipynb', 'vtt', 'srt', 'chat', 'docx', 'blocks'];

/** Formats read into a key tree for the markup profile. */
export const DATA_FORMATS: readonly string[] = ['yaml', 'json', 'jsonl', 'xml', 'toml'];

/** Formats read as tables. */
export const SPREADSHEET_FORMATS: readonly string[] = ['csv', 'xlsx'];

/** File extension to format. */
export const EXTENSIONS: Readonly<Record<string, string>> = {
    md: 'markdown',
    markdown: 'markdown',
    mdx: 'mdx',
    txt: 'text',
    text: 'text',
    html: 'html',
    htm: 'html',
    xhtml: 'html',
    rst: 'rst',
    adoc: 'asciidoc',
    asciidoc: 'asciidoc',
    org: 'org',
    tex: 'latex',
    ipynb: 'ipynb',
    vtt: 'vtt',
    srt: 'srt',
    docx: 'docx',
    yaml: 'yaml',
    yml: 'yaml',
    json: 'json',
    jsonl: 'jsonl',
    ndjson: 'jsonl',
    xml: 'xml',
    toml: 'toml',
    csv: 'csv',
    xlsx: 'xlsx',
};

const READERS: Readonly<Record<string, (text: string) => Block[]>> = {
    markdown: parseMarkdown,
    mdx: parseMdx,
    text: parseText,
    html: parseHtml,
    rst: parseRst,
    asciidoc: parseAsciidoc,
    org: parseOrg,
    latex: parseLatex,
    ipynb: parseNotebook,
    vtt: parseVtt,
    srt: parseSrt,
    chat: parseChat,
    blocks: parseBlocks,
};

/** The format a file's extension names (what follows the last dot of the file name), or null. */
export function formatOf(path: string): string | null {
    const name = path.split(/[\\/]/).at(-1) ?? '';
    const dot = name.lastIndexOf('.');
    const extension = dot === -1 ? '' : name.slice(dot + 1).toLowerCase();

    return Object.hasOwn(EXTENSIONS, extension) ? EXTENSIONS[extension]! : null;
}

/**
 * A document read into blocks.
 *
 * @throws {ContextAtlasError} on a format that is not a document format, on DOCX (read from a
 *   file with `parseDocx`), or on a document its reader refuses
 */
export function readBlocks(text: string, format: string): Block[] {
    if (format === 'docx') {
        throw new ContextAtlasError('DOCX is read from a file: use ingestFile.');
    }

    const reader = Object.hasOwn(READERS, format) ? READERS[format] : undefined;

    if (reader === undefined) {
        throw new ContextAtlasError(`"${format}" is not a document format; use one of: ${DOCUMENT_FORMATS.join(', ')}.`);
    }

    return reader(text);
}
