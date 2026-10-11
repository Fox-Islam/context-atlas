import { squash, trim } from '../../text.js';
import type { Frame } from './frames.js';
import { W, isW, wAttribute, wChild, wValue } from './package.js';
import { textContent, type XmlElement } from './xml.js';

const MC = 'http://schemas.openxmlformats.org/markup-compatibility/2006';

const MATH: readonly string[] = ['http://schemas.openxmlformats.org/officeDocument/2006/math', 'http://purl.oclc.org/ooxml/officeDocument/math'];

/** PCRE without the `u` flag: `\s` is ASCII whitespace. */
const TOC = /^[ \t\n\v\f\r]*TOC(?![A-Za-z])/i;

const NOTE_TYPES_SKIPPED: readonly string[] = ['separator', 'continuationSeparator', 'continuationNotice'];

const DROPPED: readonly string[] = ['del', 'moveFrom', 'delText', 'delInstrText', 'softHyphen'];

/** A body item: a paragraph-like item or a table. */
export type Item = Record<string, unknown> & { kind: string };

/**
 * The text of a paragraph's runs: `w:t` text, tabs and breaks as characters, field instructions
 * dropped and field results kept (except a table of contents'), deleted text dropped, note
 * references as `[n]`. Text boxes met on the way go to the body reader and come back beside the
 * text. Field state carries across paragraphs, as fields may (spec/formats/docx.md, Run text).
 */
export class Runs {
    /** Per open field: whether its text is dropped, and its instruction so far. */
    private fields: [boolean, string][] = [];

    private readonly notes = new Map<string, XmlElement>();

    private readonly numbers = new Map<string, number>();

    private inNote = false;

    private boxes: Item[] = [];

    constructor(
        footnotes: XmlElement | null,
        endnotes: XmlElement | null,
        private readonly body: (container: XmlElement) => Frame<Item[]>,
    ) {
        for (const [kind, root] of [['footnote', footnotes], ['endnote', endnotes]] as const) {
            for (const note of root?.children ?? []) {
                if (isW(note, kind) && !NOTE_TYPES_SKIPPED.includes(wAttribute(note, 'type') ?? '')) {
                    const key = `${kind}:${wAttribute(note, 'id') ?? ''}`;

                    if (!this.notes.has(key)) {
                        this.notes.set(key, note);
                    }
                }
            }
        }
    }

    /** The paragraph's text and the items of the text boxes inside it. */
    *read(paragraph: XmlElement): Frame<[string, Item[]]> {
        const outer = this.boxes;
        this.boxes = [];
        const text = (yield this.walk(paragraph)) as string;
        const found = this.boxes;
        this.boxes = outer;

        return [text, found];
    }

    /** The referenced notes in marker order, each `[n] text`; a note whose text is empty gives `[n]`. */
    *noteLines(): Frame<string[]> {
        this.inNote = true;
        this.fields = [];
        const lines: string[] = [];

        for (const [key, number] of this.numbers) {
            const parts: string[] = [];

            for (const child of this.notes.get(key)!.children) {
                if (isW(child, 'p')) {
                    const [text] = (yield this.read(child)) as [string, Item[]];
                    parts.push(squash(text));
                }
            }
            lines.push(trim(`[${number}] ${parts.filter((part) => part !== '').join(' ')}`));
        }

        return lines;
    }

    private *walk(element: XmlElement): Frame<string> {
        let out = '';

        for (const node of element.children) {
            if (typeof node === 'string') {
                continue;
            }
            if (W.includes(node.ns ?? '')) {
                out += (yield this.word(node)) as string;
            } else if (node.ns === MC && node.local === 'AlternateContent') {
                out += (yield this.alternate(node)) as string;
            } else if (MATH.includes(node.ns ?? '') && node.local === 't') {
                out += this.dropping() ? '' : textContent(node);
            } else {
                out += (yield this.walk(node)) as string;
            }
        }

        return out;
    }

    private *word(node: XmlElement): Frame<string> {
        const name = node.local;

        switch (name) {
            case 't':
                return this.dropping() ? '' : textContent(node);
            case 'tab':
            case 'ptab':
                return this.dropping() ? '' : '\t';
            case 'br':
            case 'cr':
                return this.dropping() ? '' : '\n';
            case 'noBreakHyphen':
                return this.dropping() ? '' : '-';
            case 'sym':
                return this.dropping() ? '' : symbol(node);
            case 'fldChar':
                return this.field(wAttribute(node, 'fldCharType'));
            case 'footnoteReference':
            case 'endnoteReference':
                return this.reference(node, name === 'footnoteReference' ? 'footnote' : 'endnote');
            case 'ruby': {
                const base = wChild(node, 'rubyBase');

                return base === null ? '' : ((yield this.walk(base)) as string);
            }
            case 'txbxContent':
                if (!this.inNote) {
                    for (const item of (yield this.body(node)) as Item[]) {
                        this.boxes.push(item);
                    }
                }

                return '';
            case 'instrText':
                return this.instruction(textContent(node));
            default:
                break;
        }

        if (name === 'fldSimple' && TOC.test(wAttribute(node, 'instr') ?? '')) {
            return '';
        }
        if (name === 'sdt' && isContents(node)) {
            return '';
        }
        if (DROPPED.includes(name) || name.endsWith('Pr')) {
            return '';
        }

        return (yield this.walk(node)) as string;
    }

    private *alternate(node: XmlElement): Frame<string> {
        let fallback: XmlElement | null = null;

        for (const child of node.children) {
            if (typeof child !== 'string' && child.ns === MC) {
                if (child.local === 'Choice') {
                    return (yield this.walk(child)) as string;
                }
                fallback ??= child.local === 'Fallback' ? child : null;
            }
        }

        return fallback === null ? '' : ((yield this.walk(fallback)) as string);
    }

    private dropping(): boolean {
        return this.fields.some(([dropped]) => dropped);
    }

    private field(type: string | null): string {
        if (type === 'begin') {
            this.fields.push([true, '']);
        } else if (type === 'separate' && this.fields.length > 0) {
            const [, instruction] = this.fields.pop()!;
            this.fields.push([TOC.test(instruction), instruction]);
        } else if (type === 'end') {
            this.fields.pop();
        }

        return '';
    }

    private instruction(text: string): string {
        if (this.fields.length > 0) {
            const [dropped, instruction] = this.fields.pop()!;
            this.fields.push([dropped, instruction + text]);
        }

        return '';
    }

    private reference(node: XmlElement, kind: string): string {
        const key = `${kind}:${wAttribute(node, 'id') ?? ''}`;

        if (this.inNote || this.dropping() || !this.notes.has(key)) {
            return '';
        }
        if (!this.numbers.has(key)) {
            this.numbers.set(key, this.numbers.size + 1);
        }

        return `[${this.numbers.get(key)}]`;
    }
}

/** Whether a content control is a table of contents: its `w:sdtPr/w:docPartObj/w:docPartGallery` value is `Table of Contents`. */
export function isContents(sdt: XmlElement): boolean {
    return wValue(wChild(wChild(sdt, 'sdtPr'), 'docPartObj'), 'docPartGallery') === 'Table of Contents';
}

/** The character a `w:sym` stands for, or `''` for a symbol font or an invalid code. */
function symbol(node: XmlElement): string {
    const font = (wAttribute(node, 'font') ?? '').toLowerCase();
    const char = wAttribute(node, 'char') ?? '';

    if (!/^[0-9A-Fa-f]{1,6}$/.test(char) || /symbol|wingdings|webdings/.test(font)) {
        return '';
    }

    const parsed = Number.parseInt(char, 16);
    const code = parsed >= 0xf000 ? parsed - 0xf000 : parsed;

    return code >= 0x20 && code <= 0x10ffff && (code < 0xd800 || code > 0xdfff) ? String.fromCodePoint(code) : '';
}
