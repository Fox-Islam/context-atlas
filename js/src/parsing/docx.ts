import { Block } from '../block.js';
import { squash } from '../text.js';
import { BlockBuilder } from './blockBuilder.js';
import { run, type Frame } from './docx/frames.js';
import { Numbering } from './docx/numbering.js';
import { integer, isW, openPackage, packageParts, wAttribute, wChild, wValue, type Parts } from './docx/package.js';
import { isContents, Runs, type Item } from './docx/runs.js';
import { Styles } from './docx/styles.js';
import { textContent, type XmlElement, type XmlNode } from './docx/xml.js';

const MC = 'http://schemas.openxmlformats.org/markup-compatibility/2006';

const DC = 'http://purl.org/dc/elements/1.1/';

interface Cell {
    text: string;
    items: Item[];
    continued: boolean;
}

/**
 * Reads a Word document (.docx) from a path: the body in order, with headings, lists and code
 * from paragraph styles and numbering, tables, text boxes where they appear, and footnotes and
 * endnotes appended (spec/formats/docx.md).
 *
 * @throws {ContextAtlasError} with the spec's fixed messages
 */
export function parseDocx(path: string): Block[] {
    return new DocxReader(openPackage(path)).read();
}

/** A DOCX package already in memory, named `name` in messages. */
export function parseDocxBytes(bytes: Uint8Array, name = 'document.docx'): Block[] {
    return new DocxReader(packageParts(bytes, name)).read();
}

class DocxReader {
    private readonly styles: Styles;

    private readonly numbering: Numbering;

    private readonly runs: Runs;

    constructor(private readonly parts: Parts) {
        this.styles = new Styles(parts.styles);
        this.numbering = new Numbering(parts.numbering);
        this.runs = new Runs(parts.footnotes, parts.endnotes, (container) => this.body(container));
    }

    read(): Block[] {
        const body = wChild(this.parts.document, 'body');

        return this.render(body === null ? [] : run(this.body(body)), titleOf(this.parts.core));
    }

    /** Block-level items of a container, in order; a table of one column gives the items of its cells instead. */
    private *body(container: XmlElement): Frame<Item[]> {
        const items: Item[] = [];

        for (const child of container.children) {
            if (typeof child === 'string') {
                continue;
            }

            const node: XmlElement = child;

            let inner: Frame<Item[]> | null = null;

            if (node.ns === MC && node.local === 'AlternateContent') {
                const choice = choiceOf(node);
                inner = choice === null ? null : this.body(choice);
            } else if (isW(node, 'p')) {
                inner = this.paragraph(node);
            } else if (isW(node, 'tbl')) {
                inner = this.table(node);
            } else if (isW(node, 'sdt') && !isContents(node)) {
                const content = wChild(node, 'sdtContent');
                inner = content === null ? null : this.body(content);
            } else if (isW(node, 'customXml') || isW(node, 'ins') || isW(node, 'moveTo')) {
                inner = this.body(node);
            }
            if (inner !== null) {
                append(items, (yield inner) as Item[]);
            }
        }

        return items;
    }

    private *paragraph(paragraph: XmlElement): Frame<Item[]> {
        const properties = wChild(paragraph, 'pPr');
        const style = this.styles.resolve(wValue(properties, 'pStyle'));
        const name = this.styles.name(style).toLowerCase();
        const numbering = wChild(properties, 'numPr');
        const numId = wValue(numbering, 'numId') ?? this.styles.inherited(style, 'numId');
        const ilvl = integer(wValue(numbering, 'ilvl')) ?? this.styles.inherited(style, 'ilvl') ?? 0;
        const outline = integer(wValue(properties, 'outlineLvl')) ?? this.styles.inherited(style, 'outline');
        const [text, boxes] = (yield this.runs.read(paragraph)) as [string, Item[]];
        const heading = /^heading ([1-9])$/.exec(name);
        let item: Item = { kind: 'paragraph', text };

        if (name === 'title') {
            item = { kind: 'title', text };
        } else if (heading !== null || (outline !== null && outline >= 0 && outline <= 8)) {
            item = { kind: 'heading', text, level: Math.min(6, heading !== null ? Number(heading[1]) : outline! + 1) };
        } else if (numId !== null && numId !== '0' && this.numbering.has(numId)) {
            const level = Math.max(0, Math.min(8, ilvl));
            item = { kind: 'item', text, ilvl: level, marker: this.numbering.marker(numId, level) };
        } else if (/code|source|preformatted/.test(name)) {
            item = { kind: 'code', text };
        } else if (name === 'caption') {
            item = { kind: 'caption', text };
        }

        return append([item], boxes);
    }

    private *table(table: XmlElement): Frame<Item[]> {
        const rows: Cell[][] = [];
        const heads: boolean[] = [];
        let width = 0;
        let above: Cell[] = [];

        for (const tr of childrenOf(table, 'tr')) {
            const properties = wChild(tr, 'trPr');
            const before = Math.max(0, Math.min(100, integer(wValue(properties, 'gridBefore')) ?? 0));
            const grid: Cell[] = Array.from({ length: before }, () => ({ text: '', items: [], continued: false }));

            for (const tc of childrenOf(tr, 'tc')) {
                const cellProperties = wChild(tc, 'tcPr');
                const span = Math.max(1, Math.min(100, integer(wValue(cellProperties, 'gridSpan')) ?? 1));
                const merge = wChild(cellProperties, 'vMerge');
                const merged = merge === null ? null : wAttribute(merge, 'val');
                const continues = merge !== null && (merged === null || merged === 'continue');
                const items = (yield this.body(tc)) as Item[];

                for (let k = 0; k < span; k++) {
                    grid.push(continues
                        ? { text: above[grid.length]?.text ?? '', items: [], continued: true }
                        : { text: flatten(items), items: k === 0 ? items : [], continued: false });
                }
            }

            const header = wChild(properties, 'tblHeader');
            heads.push(header !== null && !['0', 'false', 'off'].includes(wAttribute(header, 'val') ?? ''));
            rows.push(grid);
            width = Math.max(width, grid.length);
            above = grid;
        }

        if (width === 1) {
            const items: Item[] = [];

            for (const cell of rows.flat()) {
                append(items, cell.items);
            }

            return items;
        }

        let count = 0;

        while (count < heads.length && heads[count]) {
            count++;
        }
        count = rows.length === 0 ? 0 : Math.max(1, count);

        const texts = rows.map((row, r) => row.map((cell) => (r < count && cell.continued ? '' : cell.text)));

        return [{ kind: 'table', header: texts.slice(0, count), rows: texts.slice(count) }];
    }

    private render(given: Item[], title: string): Block[] {
        const items = captions(given);
        let shift = 0;

        for (const item of items) {
            shift = item.kind === 'title' && squash(String(item['text'])) !== '' ? 1 : shift;
        }

        const out = new BlockBuilder();
        let list: string[] = [];
        let code: string[] | null = null;

        for (const item of items) {
            const kind = item.kind;
            const text = squash(String(item['text'] ?? ''));

            if (kind !== 'code' && code !== null && !(kind === 'paragraph' && text === '')) {
                out.code(code);
                code = null;
            }
            if (kind !== 'item' && list.length > 0 && !(kind === 'paragraph' && text === '')) {
                out.paragraph(list.join('\n'));
                list = [];
            }

            if (kind === 'code') {
                code = [...(code ?? []), ...String(item['text']).split('\n')];
            } else if (kind === 'item') {
                const marker = String(item['marker']);

                if (text !== '') {
                    list.push('  '.repeat(Number(item['ilvl'])) + (marker === '' ? text : `${marker} ${text}`));
                }
            } else if (kind === 'title') {
                out.heading(text, 1);
            } else if (kind === 'heading') {
                out.heading(text, Math.min(6, Number(item['level']) + shift));
            } else if (kind === 'table') {
                out.table(String(item['caption'] ?? ''), item['header'] as string[][], item['rows'] as string[][]);
            } else if (kind !== 'taken') {
                out.paragraph(text);
            }
        }
        if (code !== null) {
            out.code(code);
        }
        if (list.length > 0) {
            out.paragraph(list.join('\n'));
        }
        for (const note of run(this.runs.noteLines())) {
            out.paragraph(note);
        }

        const blocks = out.result();

        return title === '' || blocks.some((block) => block.isHeading() && block.level === 1) ? blocks : [Block.heading(title, 1), ...blocks];
    }
}

/** Child elements `w:<local>`, also inside `w:sdt` content and `w:customXml`, in document order. */
function childrenOf(parent: XmlElement, local: string): XmlElement[] {
    const found: XmlElement[] = [];
    const stack: XmlNode[] = [...parent.children].reverse();

    while (stack.length > 0) {
        const node = stack.pop()!;
        const inside = isW(node, 'sdt') ? wChild(node, 'sdtContent') : isW(node, 'customXml') ? node : null;

        if (isW(node, local)) {
            found.push(node);
        } else if (inside !== null) {
            for (let i = inside.children.length - 1; i >= 0; i--) {
                stack.push(inside.children[i]!);
            }
        }
    }

    return found;
}

/** Appends `items` to `target` without spreading them as arguments, which a long list would overflow. */
function append<T>(target: T[], items: readonly T[]): T[] {
    for (const item of items) {
        target.push(item);
    }

    return target;
}

/** A cell's text: the squashed text of its items (a table's cell texts in order), non-empty ones joined by spaces. */
function flatten(items: readonly Item[]): string {
    const parts: string[] = [];

    for (const item of items) {
        if (item.kind === 'table') {
            for (const row of [...(item['header'] as string[][]), ...(item['rows'] as string[][])]) {
                append(parts, row);
            }
        } else {
            parts.push(squash(String(item['text'])));
        }
    }

    return parts.filter((part) => part !== '').join(' ');
}

function choiceOf(alternate: XmlElement): XmlElement | null {
    let fallback: XmlElement | null = null;

    for (const child of alternate.children) {
        if (typeof child !== 'string' && child.ns === MC) {
            if (child.local === 'Choice') {
                return child;
            }
            fallback ??= child.local === 'Fallback' ? child : null;
        }
    }

    return fallback;
}

function titleOf(core: XmlElement | null): string {
    const title = core?.children.find((node): node is XmlElement => typeof node !== 'string' && node.ns === DC && node.local === 'title');

    return title === undefined ? '' : squash(textContent(title));
}

/**
 * Gives each table the text of a caption-styled paragraph next to it (before it first, then after
 * it, skipping paragraphs whose text is empty); that paragraph is then not emitted.
 */
function captions(given: readonly Item[]): Item[] {
    const items = given.map((item) => ({ ...item }));

    items.forEach((item, i) => {
        if (item.kind !== 'table') {
            return;
        }
        for (const step of [-1, 1]) {
            let j = i + step;

            while (j >= 0 && j < items.length && ['paragraph', 'caption'].includes(items[j]!.kind) && squash(String(items[j]!['text'])) === '') {
                j += step;
            }
            if (j >= 0 && j < items.length && items[j]!.kind === 'caption') {
                item['caption'] = squash(String(items[j]!['text']));
                items[j]!.kind = 'taken';
                break;
            }
        }
    });

    return items;
}
