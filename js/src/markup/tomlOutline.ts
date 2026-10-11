import type { Outline, OutlineEntry } from './outline.js';

/** PCRE without the `u` flag: `.` is any character but a line feed. */
const SEGMENT = `(?:[A-Za-z0-9_-]+|"(?:[^"\\\\]|\\\\[^\\n])*"|'[^']*')`;

const KEY = `${SEGMENT}(?:[ \\t]*\\.[ \\t]*${SEGMENT})*`;

const ARRAY_HEADER = new RegExp(`^\\[\\[[ \\t]*(${KEY})[ \\t]*\\]\\][ \\t]*(?:#[^\\n]*)?$`);

const TABLE_HEADER = new RegExp(`^\\[[ \\t]*(${KEY})[ \\t]*\\][ \\t]*(?:#[^\\n]*)?$`);

const KEY_LINE = new RegExp(`^(${KEY})[ \\t]*=[ \\t]*([^\\n]*)$`);

const SEGMENTS = new RegExp(SEGMENT, 'g');

const COMMENT_LINE = /^[ \t\n\v\f\r]*#/;

const BLANK_OR_COMMENT = /^[ \t\n\v\f\r]*(#[^\n]*)?$/;

/**
 * The key tree of a TOML file with the line span of every node, read line by line from the
 * original text, with no TOML library, so the three packages read a file the same way
 * (spec/markup.md). Only headers and keys are nodes: `[tool.black]` is one node at `tool >
 * black`, with no node for `tool`. Arrays and inline tables are one node with no value and no
 * children, and their lines, like those of a multi-line string, belong to their key.
 */
export function readToml(text: string): Outline {
    return new TomlReader(text.replace(/\r\n?/g, '\n').split('\n')).read();
}

class TomlReader {
    private readonly entries: OutlineEntry[];

    private readonly stack: number[] = [0];

    private table: string[] = [];

    private readonly arrays = new Map<string, number>();

    constructor(private readonly lines: string[]) {
        this.entries = [{ path: [], start: 0, end: lines.length, value: null, alias: null }];
    }

    read(): Outline {
        const count = this.lines.length;

        for (let n = 0; n < count; n++) {
            const line = this.lines[n]!;
            const content = line.replace(/^[ \t]+|[ \t]+$/g, '');

            if (content === '' || content[0] === '#') {
                continue;
            }

            let match = ARRAY_HEADER.exec(content);

            if (match !== null) {
                this.header(n, keysOf(match[1]!), true);
                continue;
            }
            match = TABLE_HEADER.exec(content);
            if (match !== null) {
                this.header(n, keysOf(match[1]!), false);
                continue;
            }
            match = KEY_LINE.exec(content);
            if (match !== null) {
                this.open(n, [...this.table, ...keysOf(match[1]!)]);
                const leading = line.length - line.replace(/^[ \t]+/, '').length;
                n = this.value(n, leading + content.length - match[2]!.length);
            }
        }
        this.close([], count, true);

        return { lines: this.lines, entries: this.entries, anchors: new Map() };
    }

    private header(n: number, keys: readonly string[], item: boolean): void {
        const path: string[] = [];

        keys.forEach((key, i) => {
            path.push(key);

            const items = this.arrays.get(keyOf(path));

            if (i < keys.length - 1 && items !== undefined) {
                path.push(`[${items - 1}]`);
            }
        });
        if (item) {
            const index = keyOf(path);
            const items = (this.arrays.get(index) ?? 0) + 1;
            this.arrays.set(index, items);
            path.push(`[${items - 1}]`);
        }
        this.table = path;
        this.open(n, path);
    }

    /** Closes the open nodes whose path does not lead to `path`, then opens its node. */
    private open(n: number, path: string[]): void {
        this.close(path, n);

        let start = n;

        while (start > 0 && COMMENT_LINE.test(this.lines[start - 1]!)) {
            start--;
        }
        this.entries.push({ path, start, end: this.lines.length, value: null, alias: null });
        this.stack.push(this.entries.length - 1);
    }

    /**
     * Ends the open nodes whose path is not a proper prefix of `path` (all of them when `all`),
     * at `line` moved up over trailing blank and comment lines but never to the node's own first
     * line.
     */
    private close(path: readonly string[], line: number, all = false): void {
        while (this.stack.length > 1) {
            const top = this.entries[this.stack[this.stack.length - 1]!]!;
            const open = top.path;

            if (!all && open.length < path.length && open.every((segment, i) => segment === path[i])) {
                break;
            }
            this.stack.pop();

            let end = line;

            while (end > top.start + 1 && BLANK_OR_COMMENT.test(this.lines[end - 1]!)) {
                end--;
            }
            top.end = end;
        }
    }

    /** Reads the value starting at `column` of line `n` into the top node; returns the line it ends on. */
    private value(n: number, column: number): number {
        const line = this.lines[n]!;
        const rest = line.slice(column);
        const entry = this.entries[this.stack[this.stack.length - 1]!]!;
        const first = rest[0] ?? '';

        if (first === '"' || first === "'" || first === '[' || first === '{') {
            const [endLine, endColumn] = this.extent(n, column);

            if (first === '[' || first === '{') {
                return endLine;
            }

            const raw = endLine === n
                ? line.slice(column, endColumn)
                : [line.slice(column), ...this.lines.slice(n + 1, endLine), (this.lines[endLine] ?? '').slice(0, endColumn)].join('\n');
            entry.value = stringOf(raw);

            return endLine;
        }

        const hash = rest.indexOf('#');
        const plain = (hash === -1 ? rest : rest.slice(0, hash)).replace(/^[ \t]+|[ \t]+$/g, '');
        entry.value = plain === '' ? null : plain;

        return n;
    }

    /**
     * Where the string, array or inline table starting at `column` of line `n` ends: the line and
     * the position after its closing delimiter. Brackets count outside strings and comments; a
     * single-line string left open at the end of its line closes there; an unclosed value runs
     * to the end of the text.
     */
    private extent(n: number, column: number): [number, number] {
        let depth = 0;
        let mode = '';
        const count = this.lines.length;

        for (let l = n; l < count; l++) {
            const text = this.lines[l]!;

            for (let i = l === n ? column : 0; i < text.length; i++) {
                const char = text[i]!;

                if (mode === '' && char === '#') {
                    break;
                }
                if (mode === '') {
                    if (char === '"' || char === "'") {
                        mode = text.startsWith(char.repeat(3), i) ? char.repeat(3) : char;
                        i += mode.length - 1;
                    } else if (char === '[' || char === '{') {
                        depth++;
                    } else if (char === ']' || char === '}') {
                        depth--;
                    }
                } else if (char === '\\' && (mode === '"' || mode === '"""')) {
                    i++;
                } else if (char === mode[0]) {
                    let run = 0;

                    while (text[i + run] === char) {
                        run++;
                    }
                    if (mode.length === 1 || run >= 3) {
                        i += (mode.length === 1 ? 1 : Math.min(run, 5)) - 1;
                        mode = '';
                    } else {
                        i += run - 1;
                    }
                }
                if (mode === '' && depth <= 0) {
                    return [l, i + 1];
                }
            }
            if (mode === '"' || mode === "'") {
                mode = '';
                if (depth <= 0) {
                    return [l, text.length];
                }
            }
        }

        return [count - 1, this.lines[count - 1]!.length];
    }
}

/** A string's text: a literal string as written, a basic string unescaped; a multi-line one without the newline right after its opening delimiter. */
function stringOf(raw: string): string | null {
    for (const delimiter of ['"""', "'''"]) {
        if (raw.startsWith(delimiter)) {
            if (Buffer.byteLength(raw, 'utf8') < 6 || !raw.endsWith(delimiter)) {
                return null;
            }

            let body = raw.slice(3, -3);
            body = body.startsWith('\n') ? body.slice(1) : body;

            return delimiter === "'''" ? body : unescape(body);
        }
    }

    const basic = /^"((?:[^"\\]|\\[^\n])*)"$/.exec(raw);

    if (basic !== null) {
        return unescape(basic[1]!);
    }

    return /^'([^']*)'$/.exec(raw)?.[1] ?? null;
}

/**
 * Basic-string escapes: `\b \t \n \f \r \" \\`, `\uXXXX` and `\UXXXXXXXX`, and a backslash at
 * the end of a line, which removes itself and the whitespace and newlines after it. Any other
 * escape, and one naming no Unicode scalar value, stays as written.
 */
function unescape(text: string): string {
    return text.replace(/\\(?:[ \t]*\n[ \t\n]*|u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|[\s\S])/g, (whole) => {
        const code = whole[1]!;

        if (whole.includes('\n')) {
            return '';
        }
        if (code === 'u' || code === 'U') {
            const point = Number.parseInt(whole.slice(2), 16);

            return point > 0x10ffff || (point >= 0xd800 && point <= 0xdfff) ? whole : String.fromCodePoint(point);
        }

        const escapes: Readonly<Record<string, string>> = { b: '\x08', t: '\t', n: '\n', f: '\x0C', r: '\r', '"': '"', '\\': '\\' };

        return Object.hasOwn(escapes, code) ? escapes[code]! : whole;
    });
}

/** A dotted key's segments: bare, or quoted (basic unescaped, literal as written). */
function keysOf(key: string): string[] {
    return [...key.matchAll(SEGMENTS)].map(([segment]) => {
        if (segment.startsWith('"')) {
            return unescape(segment.slice(1, -1));
        }

        return segment.startsWith("'") ? segment.slice(1, -1) : segment;
    });
}

function keyOf(path: readonly string[]): string {
    return path.join('\x1f');
}
