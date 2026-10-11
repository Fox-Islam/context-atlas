import { phpTrim } from '../support/php.js';
import type { Outline, OutlineEntry } from './outline.js';

/** PCRE without the `u` flag: `\s` is ASCII whitespace and `.` any character but a line feed. */
const S = '[ \\t\\n\\v\\f\\r]';

const PLAIN_KEY = new RegExp(`^([^ \\t\\n\\v\\f\\r#'"\\[\\]{},&*!|>%@\`-][^#]*?|-[^ \\t\\n\\v\\f\\r#][^#]*?)${S}*:(?:${S}+([^\\n]*))?$`);

const AFTER_QUOTED_KEY = new RegExp(`^${S}*:(?:${S}+([^\\n]*))?$`);

const ANCHOR = new RegExp(`^&([^ \\t\\n\\v\\f\\r,\\[\\]{}]+)${S}*([^\\n]*)$`);

const ALIAS = /^\*([^ \t\n\v\f\r,[\]{}]+)/;

const BLOCK_SCALAR = new RegExp(`^[|>][0-9+-]*(${S}+#[^\\n]*)?$`);

const COMMENT_LINE = new RegExp(`^${S}*#`);

const BLANK_OR_COMMENT = new RegExp(`^${S}*(#[^\\n]*)?$`);

const DOCUMENT_MARKER = new RegExp(`^(?:---|\\.\\.\\.)(?:${S}|$)`);

interface Open {
    indent: number;
    entry: number;
    kind: 'root' | 'item' | 'key';
    items: number;
}

/**
 * The key tree of a block-style YAML file with the line span of every node, read from
 * indentation alone, with no YAML library, so the three packages read a file the same way
 * (spec/markup.md). Block scalars and unclosed flow collections belong to their key and their
 * lines are not parsed; lines that open nothing belong to the node above them.
 */
export function readYaml(text: string): Outline {
    return new YamlReader(text.replace(/\r\n?/g, '\n').split('\n')).read();
}

class YamlReader {
    private readonly entries: OutlineEntry[];

    private readonly stack: Open[] = [{ indent: -1, entry: 0, kind: 'root', items: 0 }];

    private readonly anchors = new Map<string, string[]>();

    constructor(private readonly lines: string[]) {
        this.entries = [{ path: [], start: 0, end: lines.length, value: null, alias: null }];
    }

    read(): Outline {
        let block: number | null = null;
        let flow = 0;

        this.lines.forEach((line, n) => {
            const trimmedStart = line.replace(/^ +/, '');
            const indent = line.length - trimmedStart.length;
            const content = trimmedStart.replace(/[ \t\n\r\0\x0B]+$/, '');

            if (content === '' || content[0] === '#') {
                return;
            }
            if (block !== null && indent > block) {
                return;
            }
            block = null;
            if (flow > 0) {
                flow += depth(content);

                return;
            }
            if (DOCUMENT_MARKER.test(content)) {
                return;
            }
            [block, flow] = this.node(n, indent, content);
        });
        this.close(-2, this.lines.length);

        return { lines: this.lines, entries: this.entries, anchors: this.anchors };
    }

    /** Reads a line that opens a node: the indent past which lines belong to a block scalar, and the depth of an unclosed flow collection. */
    private node(n: number, indent: number, content: string): [number | null, number] {
        if (content === '-' || content.startsWith('- ')) {
            this.close(indent, n, true);
            const parent = this.stack[this.stack.length - 1]!;
            const path = [...this.entries[parent.entry]!.path, `[${parent.items++}]`];
            this.open(n, indent, 'item', path);

            const after = content.slice(1).replace(/^ +/, '');
            const column = indent + content.length - after.length;
            const [anchor, rest] = splitAnchor(after);
            this.remember(anchor);

            if (rest === '') {
                return [null, 0];
            }

            return matchKey(rest) !== null && !isFlow(rest) ? this.node(n, column, rest) : this.value(rest, indent);
        }

        if (content[0] === '&' && this.stack.length > 1) {
            const [anchor, rest] = splitAnchor(content);
            this.remember(anchor);

            return rest === '' ? [null, 0] : this.value(rest, this.stack[this.stack.length - 1]!.indent);
        }

        const match = matchKey(content);

        if (match === null) {
            return [null, 0];
        }

        const key = (match[1] ?? '') !== '' ? unescape(match[1]!) : (match[2] ?? '') !== '' ? match[2]!.replaceAll("''", "'") : match[3] ?? '';
        this.close(indent, n);
        this.open(n, indent, 'key', [...this.entries[this.stack[this.stack.length - 1]!.entry]!.path, key]);

        const [anchor, rest] = splitAnchor(phpTrim(match[4] ?? ''));
        this.remember(anchor);

        return this.value(rest, indent);
    }

    private value(rest: string, indent: number): [number | null, number] {
        const entry = this.entries[this.stack[this.stack.length - 1]!.entry]!;
        const alias = ALIAS.exec(rest);

        if (alias !== null) {
            entry.alias = alias[1]!;

            return [null, 0];
        }
        if (BLOCK_SCALAR.test(rest)) {
            return [indent, 0];
        }
        if (isFlow(rest)) {
            return [null, Math.max(0, depth(rest))];
        }
        if (rest !== '' && rest[0] !== '#') {
            entry.value = scalar(rest);
        }

        return [null, 0];
    }

    private open(n: number, indent: number, kind: 'item' | 'key', path: string[]): void {
        let start = n;

        while (start > 0 && COMMENT_LINE.test(this.lines[start - 1]!)) {
            start--;
        }
        this.entries.push({ path, start, end: this.lines.length, value: null, alias: null });
        this.stack.push({ indent, entry: this.entries.length - 1, kind, items: 0 });
    }

    /** Ends the nodes a line at this indent closes: deeper ones, and those at the same indent, except that a key keeps the sequence written under it at its own indent. */
    private close(indent: number, line: number, item = false): void {
        while (this.stack.length > 1) {
            const top = this.stack[this.stack.length - 1]!;

            if (top.indent < indent || (top.indent === indent && item && top.kind === 'key')) {
                break;
            }
            this.stack.pop();

            const entry = this.entries[top.entry]!;
            let end = line;

            while (end > entry.start + 1 && BLANK_OR_COMMENT.test(this.lines[end - 1]!)) {
                end--;
            }
            entry.end = end;
        }
    }

    private remember(name: string | null): void {
        if (name !== null) {
            this.anchors.set(name, [...this.entries[this.stack[this.stack.length - 1]!.entry]!.path]);
        }
    }
}

/**
 * A key line's groups as PHP's key pattern gives them: the double-quoted key, the single-quoted
 * key, the plain key, and the value text; null when the line is not a key line.
 */
function matchKey(content: string): [string, string | undefined, string | undefined, string | undefined, string | undefined] | null {
    const quote = content[0];

    if (quote === '"' || quote === "'") {
        const close = quotedEnd(content, quote);
        const after = close === null ? null : AFTER_QUOTED_KEY.exec(content.slice(close + 1));

        if (close === null || after === null) {
            return null;
        }

        const inner = content.slice(1, close);

        return [content, quote === '"' ? inner : undefined, quote === "'" ? inner : undefined, undefined, after[1]];
    }

    const plain = PLAIN_KEY.exec(content);

    return plain === null ? null : [content, undefined, undefined, plain[1], plain[2]];
}

/**
 * The index of the quote closing the quoted string that opens `text`, or null. The scan never
 * gives back what it took, as PHP's possessive patterns do: in a single-quoted string `''` is a
 * quote, so `'a'' b` has no closing quote; in a double-quoted one a backslash takes the next
 * character, except a line feed.
 */
function quotedEnd(text: string, quote: '"' | "'"): number | null {
    if (text[0] !== quote) {
        return null;
    }

    let i = 1;

    for (;;) {
        while (i < text.length && text[i] !== quote && (quote === "'" || text[i] !== '\\')) {
            i++;
        }
        if (i >= text.length) {
            return null;
        }
        if (quote === '"' && text[i] === '\\') {
            if (i + 1 >= text.length || text[i + 1] === '\n') {
                return null;
            }
            i += 2;
            continue;
        }
        if (quote === "'" && text[i + 1] === "'") {
            i += 2;
            continue;
        }

        return i;
    }
}

function splitAnchor(text: string): [string | null, string] {
    const match = ANCHOR.exec(text);

    return match === null ? [null, text] : [match[1]!, match[2]!];
}

function isFlow(text: string): boolean {
    return text.startsWith('[') || text.startsWith('{');
}

/** Opening minus closing brackets outside quotes, stopping at a comment. */
function depth(text: string): number {
    let count = 0;
    let quote: string | null = null;

    for (let i = 0; i < text.length; i++) {
        const char = text[i]!;

        if (quote !== null) {
            quote = char === quote ? null : quote;
        } else if (char === '"' || char === "'") {
            quote = char;
        } else if (char === '[' || char === '{') {
            count++;
        } else if (char === ']' || char === '}') {
            count--;
        } else if (char === '#' && (i === 0 || text[i - 1] === ' ')) {
            break;
        }
    }

    return count;
}

/** A scalar's text: quotes removed, or a plain scalar without its trailing comment. */
function scalar(text: string): string {
    const double = quotedEnd(text, '"');

    if (double !== null) {
        return unescape(text.slice(1, double));
    }

    const single = quotedEnd(text, "'");

    if (single !== null) {
        return text.slice(1, single).replaceAll("''", "'");
    }

    return phpTrim(text.replace(new RegExp(`${S}+#[^\\n]*$`), ''));
}

/** `\n` and `\t` are a newline and a tab; a backslash before any other character stands for that character. */
function unescape(text: string): string {
    return text.replace(/\\([\s\S])/gu, (_, char: string) => (char === 'n' ? '\n' : char === 't' ? '\t' : char));
}
