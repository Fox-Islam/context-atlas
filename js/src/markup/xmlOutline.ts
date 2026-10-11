import { ContextAtlasError } from '../errors.js';
import type { Outline, OutlineEntry } from './outline.js';

const LABELS: readonly string[] = ['@name', 'name', '@id', 'id', '@title', 'title', '@key', 'key'];

const LT = 0x3c;

const GT = 0x3e;

interface Element {
    tag: string;
    attributes: [string, string][];
    content: (Element | string)[];
}

/**
 * The element tree of an XML file, read by its own tokenizer over the UTF-8 bytes (no DOM or
 * XML library, so every package reads a file the same way) and printed in one fixed layout
 * so that minified and pretty-printed files cut the same way: one element per line, a text-only
 * element on one line, children indented two spaces, text with its whitespace collapsed
 * (spec/markup.md). Offsets in errors are byte offsets.
 *
 * @throws {ContextAtlasError} on XML the tokenizer cannot read
 */
export function readXml(text: string): Outline {
    return new XmlReader(Buffer.from(text, 'utf8')).read();
}

class XmlReader {
    private at = 0;

    private readonly lines: string[] = [];

    private readonly entries: OutlineEntry[] = [];

    private readonly anchors = new Map<string, string[]>();

    constructor(private readonly bytes: Buffer) {}

    read(): Outline {
        if (this.bytes[0] === 0xef && this.bytes[1] === 0xbb && this.bytes[2] === 0xbf) {
            this.at = 3;
        }
        this.misc(true);
        if (this.bytes[this.at] !== LT) {
            this.fail();
        }

        const root = this.element();
        this.misc(false);
        if (this.at !== this.bytes.length) {
            this.fail();
        }

        this.entries.push({ path: [], start: 0, end: 0, value: null, alias: null });
        this.print(root, [root.tag], 0);
        this.entries[0] = { path: [], start: 0, end: this.lines.length, value: null, alias: null };

        return { lines: this.lines, entries: this.entries, anchors: this.anchors, labels: LABELS };
    }

    /** Skips whitespace, comments and processing instructions, and a doctype before the root. */
    private misc(prolog: boolean): void {
        for (;;) {
            this.skipSpace();
            if (this.startsWith('<?')) {
                this.skipPast('?>');
            } else if (this.startsWith('<!--')) {
                this.skipPast('-->', 4);
            } else if (prolog && this.startsWith('<!DOCTYPE')) {
                this.doctype();
            } else {
                return;
            }
        }
    }

    /** Moves past the first `end` after the construct starting here; returns where its content starts. */
    private skipPast(end: string, from = 2): number {
        const close = this.bytes.indexOf(end, this.at + from, 'latin1');

        if (close === -1) {
            this.fail();
        }

        const start = this.at + from;
        this.at = close + end.length;

        return start;
    }

    /** Skips a doctype: up to the `>` outside quotes and outside its `[...]` internal subset, where comments are skipped whole. */
    private doctype(): void {
        let depth = 0;

        for (let i = this.at + 9; i < this.bytes.length; i++) {
            const char = this.bytes[i];

            if (char === 0x22 || char === 0x27) {
                const close = this.bytes.indexOf(char, i + 1);

                if (close === -1) {
                    break;
                }
                i = close;
            } else if (depth > 0 && this.startsWith('<!--', i)) {
                const close = this.bytes.indexOf('-->', i + 4, 'latin1');

                if (close === -1) {
                    break;
                }
                i = close + 2;
            } else if (char === 0x5b) {
                depth++;
            } else if (char === 0x5d) {
                depth--;
            } else if (char === GT && depth <= 0) {
                this.at = i + 1;

                return;
            }
        }
        this.fail();
    }

    /** Reads the element whose `<` is here, and its content. */
    private element(): Element {
        this.at++;

        const tag = this.name();
        const attributes: [string, string][] = [];

        for (;;) {
            const space = this.skipSpace();

            if (this.startsWith('/>')) {
                this.at += 2;

                return { tag, attributes, content: [] };
            }
            if (this.bytes[this.at] === GT) {
                this.at++;

                return { tag, attributes, content: this.content(tag) };
            }
            if (space === 0) {
                this.fail();
            }
            attributes.push(this.attribute());
        }
    }

    private attribute(): [string, string] {
        const name = this.name();
        this.skipSpace();
        if (this.bytes[this.at] !== 0x3d) {
            this.fail();
        }
        this.at++;
        this.skipSpace();

        const quote = this.bytes[this.at];

        if (quote !== 0x22 && quote !== 0x27) {
            this.fail();
        }

        const close = this.bytes.indexOf(quote, this.at + 1);

        if (close === -1) {
            this.fail();
        }

        const value = this.slice(this.at + 1, close);
        this.at = close + 1;

        return [name, collapse(decode(value))];
    }

    /**
     * An element's content up to its end tag: child elements and runs of text, where a run is the
     * character data (text with entities decoded, CDATA as written) between two tags.
     */
    private content(tag: string): (Element | string)[] {
        const content: (Element | string)[] = [];
        let run = '';

        for (;;) {
            const next = this.bytes.indexOf(LT, this.at);

            if (next === -1) {
                this.at = this.bytes.length;
                this.fail();
            }
            run += decode(this.slice(this.at, next));
            this.at = next;

            if (this.startsWith('<!--')) {
                this.skipPast('-->', 4);
            } else if (this.startsWith('<![CDATA[')) {
                const start = this.skipPast(']]>', 9);
                run += this.slice(start, this.at - 3);
            } else if (this.startsWith('<?')) {
                this.skipPast('?>');
            } else if (this.startsWith('<!')) {
                this.fail();
            } else {
                const text = collapse(run);

                if (text !== '') {
                    content.push(text);
                }
                run = '';
                if (this.startsWith('</')) {
                    this.endTag(tag);

                    return content;
                }
                content.push(this.element());
            }
        }
    }

    private endTag(tag: string): void {
        const start = this.at;
        this.at += 2;

        const name = this.matchName();
        this.at += Buffer.byteLength(name, 'utf8');
        this.skipSpace();
        if (name !== tag || this.bytes[this.at] !== GT) {
            this.at = start;
            this.fail();
        }
        this.at++;
    }

    /** Prints an element and records its entry, its attributes' entries, then its children's. */
    private print(node: Element, path: string[], depth: number): void {
        const index = this.entries.length;
        const start = this.lines.length;
        this.entries.push({ path, start, end: 0, value: null, alias: null });

        const pad = '  '.repeat(depth);
        let open = `<${node.tag}`;

        for (const [name, value] of node.attributes) {
            open += ` ${name}="${value.replaceAll('"', '&quot;')}"`;
            this.attributeEntry(path, start, name, value);
        }

        const texts = node.content.filter((item): item is string => typeof item === 'string');
        const hasChildren = node.content.some((item) => typeof item !== 'string');

        if (node.content.length === 0) {
            this.lines.push(`${pad}${open}/>`);
        } else if (!hasChildren) {
            this.lines.push(`${pad}${open}>${texts.join(' ')}</${node.tag}>`);
        } else {
            this.lines.push(`${pad}${open}>`);
            this.printContent(node.content, path, depth + 1);
            this.lines.push(`${pad}</${node.tag}>`);
        }
        this.entries[index] = { path, start, end: this.lines.length, value: texts.length === 0 ? null : texts.join(' '), alias: null };
    }

    private printContent(content: readonly (Element | string)[], path: readonly string[], depth: number): void {
        const counts = new Map<string, number>();

        for (const item of content) {
            if (typeof item !== 'string') {
                counts.set(item.tag, (counts.get(item.tag) ?? 0) + 1);
            }
        }

        const seen = new Map<string, number>();

        for (const item of content) {
            if (typeof item === 'string') {
                this.lines.push('  '.repeat(depth) + item);
                continue;
            }

            let segment = item.tag;

            if (counts.get(item.tag)! > 1) {
                const n = (seen.get(item.tag) ?? -1) + 1;
                seen.set(item.tag, n);
                segment = `${item.tag}[${n}]`;
            }
            this.print(item, [...path, segment], depth);
        }
    }

    private attributeEntry(path: readonly string[], line: number, name: string, value: string): void {
        let alias: string | null = null;

        if (value !== '') {
            if (name === 'ref' || name === 'idref') {
                alias = value;
            } else if ((name === 'href' || name === 'xlink:href') && Buffer.byteLength(value, 'utf8') > 1 && value.startsWith('#')) {
                alias = value.slice(1);
            }
        }
        this.entries.push({ path: [...path, `@${name}`], start: line, end: line, value, alias });
        if ((name === 'id' || name === 'xml:id') && value !== '') {
            this.anchors.set(value, [...path]);
        }
    }

    /** `[A-Za-z_:\x80-\xff][A-Za-z0-9_:.\x80-\xff-]*` at the cursor, as bytes. */
    private matchName(): string {
        let end = this.at;
        const first = this.bytes[end];

        if (first === undefined || !(isLetter(first) || first === 0x5f || first === 0x3a || first >= 0x80)) {
            return '';
        }
        end++;
        while (end < this.bytes.length) {
            const byte = this.bytes[end]!;

            if (!(isLetter(byte) || (byte >= 0x30 && byte <= 0x39) || byte === 0x5f || byte === 0x3a || byte === 0x2e || byte === 0x2d || byte >= 0x80)) {
                break;
            }
            end++;
        }

        return this.slice(this.at, end);
    }

    private name(): string {
        const name = this.matchName();

        if (name === '') {
            this.fail();
        }
        this.at += Buffer.byteLength(name, 'utf8');

        return name;
    }

    private skipSpace(): number {
        const from = this.at;

        while (this.at < this.bytes.length && isSpace(this.bytes[this.at]!)) {
            this.at++;
        }

        return this.at - from;
    }

    private startsWith(literal: string, at = this.at): boolean {
        for (let i = 0; i < literal.length; i++) {
            if (this.bytes[at + i] !== literal.charCodeAt(i)) {
                return false;
            }
        }

        return true;
    }

    private slice(start: number, end: number): string {
        return this.bytes.toString('utf8', start, end);
    }

    private fail(): never {
        throw new ContextAtlasError(`Invalid XML at byte ${this.at}.`);
    }
}

function isLetter(byte: number): boolean {
    return (byte >= 0x41 && byte <= 0x5a) || (byte >= 0x61 && byte <= 0x7a);
}

function isSpace(byte: number): boolean {
    return byte === 0x20 || byte === 0x09 || byte === 0x0d || byte === 0x0a;
}

/** The predefined entities and numeric references; any other `&` stays as written, as does a reference to a code point that is not a Unicode scalar value. */
function decode(text: string): string {
    if (!text.includes('&')) {
        return text;
    }

    const named: Readonly<Record<string, string>> = { lt: '<', gt: '>', amp: '&', quot: '"', apos: "'" };

    return text.replace(/&(lt|gt|amp|quot|apos|#[0-9]+|#x[0-9A-Fa-f]+);/g, (whole, reference: string) => {
        if (Object.hasOwn(named, reference)) {
            return named[reference]!;
        }

        const hex = reference[1] === 'x';
        const digits = reference.slice(hex ? 2 : 1).replace(/^0+/, '');
        const code = digits.length > 7 ? -1 : Number.parseInt(digits === '' ? '0' : digits, hex ? 16 : 10);

        return code < 1 || code > 0x10ffff || (code >= 0xd800 && code <= 0xdfff) ? whole : String.fromCodePoint(code);
    });
}

function collapse(text: string): string {
    return text.replace(/[ \t\r\n]+/g, ' ').replace(/^ +| +$/g, '');
}
