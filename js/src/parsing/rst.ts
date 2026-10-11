import type { Block } from '../block.js';
import { length, trim, WHITESPACE } from '../text.js';
import { BlockBuilder, dedent, delimited } from './blockBuilder.js';

/** PCRE without the `u` flag: ASCII classes, and `.` any character but a line feed. */
const ADORNMENT = /^([!-/:-@[-`{-~])\1*$/;

const EXPLICIT = /^ *\.\.(?: |$)/;

const DIRECTIVE = /^ *\.\. +([A-Za-z0-9]+(?:[-_.:+][A-Za-z0-9]+)*)::(?: +([^\n]*))?$/;

const FOOTNOTE = /^ *\.\. +\[([^\]]+)\](?: +([^\n]*))?$/;

const BULLET = /^ *[-*+•‣⁃](?: +|$)/u;

const ENUMERATOR = /^ *(?:\(?(?:\d+|#|[A-Za-z]|[ivxlcdmIVXLCDM]+)[.)]|\((?:\d+|#|[A-Za-z]|[ivxlcdmIVXLCDM]+)\))(?: +|$)/;

const FIELD = /^ *:(?:[^: ]|[^: ][^:]*[^: ]):(?: +|$)/;

const LINE_BLOCK = /^( *)\|(?: +|$)/;

const OPTION = /^ +:([^: ]|[^: ][^:]*[^: ]):(?: +([^\n]*))?$/;

const GRID_BORDER = /^\+(?:-+\+)+$/;

const GRID_SEPARATOR = /^\+(?:[-=]+\+)+$/;

const SIMPLE_BORDER = /^=+(?: +=+)+$/;

/** PCRE's `\s` with the `u` flag before a closing `::`. */
const SPACED_MARKER = new RegExp(`${WHITESPACE}::$`, 'u');

const CODE = ['code', 'code-block', 'sourcecode', 'parsed-literal', 'math', 'doctest', 'testcode', 'testoutput'];

const ADMONITIONS = ['attention', 'caution', 'danger', 'error', 'hint', 'important', 'note', 'tip', 'warning', 'seealso'];

const TITLED = ['admonition', 'rubric', 'topic', 'sidebar'];

const DROPPED = ['toctree', 'index', 'image', 'include', 'literalinclude', 'raw', 'contents', 'highlight', 'sectnum', 'header', 'footer', 'meta', 'title', 'default-role', 'role', 'class', 'target-notes', 'module', 'currentmodule', 'testsetup', 'testcleanup'];

const SIGNATURES = ['function', 'class', 'method', 'staticmethod', 'classmethod', 'attribute', 'property', 'data', 'exception', 'decorator', 'describe', 'object', 'envvar', 'option', 'confval'];

type Item = [text: string, column: number, kind: string];

/**
 * Reads the block structure of reStructuredText: section titles, paragraphs, lists, field and
 * definition lists, literal blocks, directives, and simple, grid, list and CSV tables. Inline
 * markup is left in the text. The rules are stated in spec/formats/rst.md.
 */
export function parseRst(text: string): Block[] {
    const lines = text.replace(/\r\n?/g, '\n').split('\n').map((line) => expandTabs(line).replace(/[ \t]+$/, ''));
    const reader = new RstReader();
    reader.run(lines);

    return reader.out.result();
}

class RstReader {
    readonly out = new BlockBuilder();

    /** Adornment styles (character, then `o` with an overline or `u`) in order of first appearance. */
    private readonly styles: string[] = [];

    private caption = '';

    run(lines: readonly string[]): void {
        const n = lines.length;
        let blank = true;
        let literal: number | null = null;
        let listBase = 0;
        let listKind = '';
        let paraIndent = 0;

        for (let i = 0; i < n;) {
            const line = lines[i]!;

            if (line === '') {
                if (this.endsWithLiteralMarker()) {
                    literal = paraIndent;
                }
                if (!this.out.inList()) {
                    this.out.close();
                }
                blank = true;
                i++;
                continue;
            }

            const indent = spaces(line);

            if (literal !== null && indent > literal) {
                i = this.literal(lines, i, literal);
                literal = null;
                continue;
            }
            literal = null;

            const termAllowed = blank || !this.out.isOpen() || (this.out.inList() && listKind === 'term' && indent === listBase);
            const item: Item | null = itemOf(line) ?? (termAllowed && isTerm(lines, i) ? [line, 0, 'term'] : null);
            const continues = item !== null && (indent > listBase || (indent === listBase && item[2] === listKind));

            if (this.out.inList() && ((item !== null && !continues) || (item === null && blank && indent <= listBase))) {
                this.out.close();
            }

            const start = blank || !this.out.isOpen();
            const next = start ? this.block(lines, i, indent) : null;

            if (next !== null) {
                i = next;
                blank = true;
                continue;
            }

            if (item !== null && (start || this.out.inList())) {
                if (!this.out.inList()) {
                    listBase = indent;
                    listKind = item[2];
                }
                if (item[2] === 'term') {
                    this.out.item(line.endsWith(':') ? line : `${line}:`);
                    this.out.extend(lines[++i]!);
                    paraIndent = spaces(lines[i]!);
                } else {
                    this.out.item(item[0]);
                    paraIndent = item[1];
                }
            } else if (this.out.inList()) {
                if (blank) {
                    paraIndent = indent;
                }
                this.out.extend(line);
            } else {
                if (!this.out.isOpen()) {
                    paraIndent = indent;
                }
                this.out.text(line);
            }

            blank = false;
            i++;
        }

        this.endsWithLiteralMarker();
        this.out.close();
    }

    /** A construct that only opens a block: a section title, transition, explicit markup, anonymous target, table or doctest block. The next line to read, or null. */
    private block(lines: readonly string[], i: number, indent: number): number | null {
        const line = lines[i]!;

        if (indent === 0) {
            const title = this.title(lines, i);

            if (title !== null) {
                return title;
            }
            if (ADORNMENT.test(line) && length(line) >= 4) {
                this.out.close();

                return i + 1;
            }
        }

        const body = line.slice(indent);

        if (EXPLICIT.test(line)) {
            return this.explicit(lines, i, indent);
        }
        if (/^__(?: |$)/.test(body)) {
            return extentEnd(lines, i, indent);
        }
        if (GRID_BORDER.test(body)) {
            return this.grid(lines, i, indent);
        }
        if (SIMPLE_BORDER.test(body)) {
            return this.simple(lines, i, indent);
        }
        if (/^>>>(?: |$)/.test(body)) {
            return this.doctest(lines, i);
        }

        return null;
    }

    private title(lines: readonly string[], i: number): number | null {
        const line = lines[i]!;
        const over = ADORNMENT.test(line);
        const text = spaceTrim(lines[i + 1] ?? '');

        if (over && text !== '' && lines[i + 2] === line && length(text) <= length(line)) {
            this.out.heading(text, this.level(`${line[0]}o`));

            return i + 3;
        }

        const under = lines[i + 1] ?? '';

        if (!over && ADORNMENT.test(under) && (length(under) >= length(line) || length(under) >= 4)) {
            this.out.heading(line, this.level(`${under[0]}u`));

            return i + 2;
        }

        return null;
    }

    private level(style: string): number {
        const level = this.styles.indexOf(style);

        if (level === -1) {
            this.styles.push(style);

            return this.styles.length;
        }

        return level + 1;
    }

    /** Rewrites a trailing `::` on the open text, and whether there was one. */
    private endsWithLiteralMarker(): boolean {
        const last = this.out.last();

        if (last === null || !last.endsWith('::')) {
            return false;
        }

        let replacement: string;

        if (last === '::') {
            replacement = '';
        } else if (SPACED_MARKER.test(last)) {
            replacement = last.slice(0, -2).replace(/[ \t\n\r\0\x0B]+$/, '');
        } else {
            replacement = last.slice(0, -1);
        }
        this.out.replaceLast(replacement);

        return true;
    }

    private literal(lines: readonly string[], i: number, indent: number): number {
        const end = extentEnd(lines, i - 1, indent);
        this.out.code(dedent(lines.slice(i, end)));

        return end;
    }

    private doctest(lines: readonly string[], i: number): number {
        let end = i;

        while (end < lines.length && lines[end] !== '') {
            end++;
        }
        this.out.code(dedent(lines.slice(i, end)));

        return end;
    }

    private explicit(lines: readonly string[], i: number, indent: number): number {
        const line = lines[i]!;

        if (spaceTrim(line) === '..' && (lines[i + 1] ?? '') === '') {
            return i + 1;
        }

        const end = extentEnd(lines, i, indent);
        const body = lines.slice(i + 1, end);
        const directive = DIRECTIVE.exec(line);

        if (directive !== null) {
            this.directive(directive[1]!.toLowerCase(), spaceTrim(directive[2] ?? ''), body);
        } else {
            const footnote = FOOTNOTE.exec(line);

            if (footnote !== null) {
                this.nested([`[${footnote[1]}] ${footnote[2] ?? ''}`, ...dedent(body)]);
            }
        }

        return end;
    }

    private directive(name: string, argument: string, body: readonly string[]): void {
        const options = new Map<string, string>();
        let optionIndent: number | null = null;
        let k = 0;

        for (; k < body.length; k++) {
            const option = OPTION.exec(body[k]!);

            if (option !== null) {
                optionIndent = spaces(body[k]!);
                options.set(option[1]!.toLowerCase(), option[2] ?? '');
            } else if (optionIndent === null || body[k] === '' || spaces(body[k]!) <= optionIndent) {
                break;
            }
        }

        const content = dedent(body.slice(k));
        const kind = name.includes(':') ? name.slice(name.lastIndexOf(':') + 1) : name;

        if (CODE.includes(name)) {
            this.out.code(content);
        } else if (ADMONITIONS.includes(name)) {
            this.nested([argument, ...content]);
        } else if (TITLED.includes(name) || SIGNATURES.includes(kind)) {
            this.out.paragraph(argument);
            this.nested(content);
        } else if (name === 'table') {
            this.caption = argument;
            this.nested(content);
            this.caption = '';
        } else if (name === 'list-table') {
            this.table(listTable(content), phpInt(options.get('header-rows') ?? '0'), argument);
        } else if (name === 'csv-table') {
            this.csvTable(argument, options, content);
        } else if (!DROPPED.includes(name)) {
            this.nested(content);
        }
    }

    private nested(lines: readonly string[]): void {
        this.out.close();
        this.run(lines);
    }

    private table(rows: readonly string[][], headerRows: number, caption = ''): void {
        this.out.table(caption !== '' ? caption : this.caption, phpSlice(rows, 0, headerRows), phpSlice(rows, headerRows));
    }

    private grid(lines: readonly string[], i: number, indent: number): number {
        const border = lines[i]!.slice(indent);
        const bounds: number[] = [];

        for (let c = 0; c < border.length; c++) {
            if (border[c] === '+') {
                bounds.push(c);
            }
        }

        const rows: string[][] = [];
        let cells: string[] = [];
        let headerRows = 0;
        let end = i + 1;

        for (; end < lines.length; end++) {
            const raw = lines[end]!;

            if (spaces(raw) !== indent || !['+', '|'].includes(raw[indent] ?? '')) {
                break;
            }

            const line = raw.slice(indent);

            if (GRID_SEPARATOR.test(line)) {
                if (cells.length > 0) {
                    rows.push(cells);
                }
                cells = [];
                if (line.includes('=')) {
                    headerRows = rows.length;
                }
                continue;
            }
            cells = gridLine(line, bounds, cells);
        }
        if (cells.length > 0) {
            rows.push(cells);
        }

        this.table(rows, headerRows);

        return end;
    }

    private simple(lines: readonly string[], i: number, indent: number): number | null {
        let end = i + 1;

        while ((lines[end] ?? '') !== '') {
            end++;
        }

        const borders: number[] = [];

        for (let j = i + 1; j < end; j++) {
            if (spaces(lines[j]!) === indent && SIMPLE_BORDER.test(lines[j]!.slice(indent))) {
                borders.push(j);
            }
        }
        if (borders.length === 0 || borders.at(-1) !== end - 1) {
            return null;
        }

        const starts = [...lines[i]!.slice(indent).matchAll(/=+/g)].map((run) => run.index);
        const rows: string[][] = [];
        let headerRows: number | null = borders.length > 1 ? null : 0;

        for (let j = i + 1; j < end - 1; j++) {
            const line = mbSubstr(lines[j]!, indent);

            if (borders.includes(j)) {
                headerRows ??= rows.length;
                continue;
            }
            if (/^[- ]*$/.test(line)) {
                continue;
            }

            const cells = starts.map((start, c) => spaceTrim(c + 1 < starts.length ? mbSubstr(line, start, starts[c + 1]! - start) : mbSubstr(line, start)));

            if (cells[0] === '' && rows.length > 0) {
                const previous = rows.pop()!;
                rows.push(previous.map((before, c) => spaceTrim(`${before} ${cells[c] ?? ''}`)));
            } else {
                rows.push(cells);
            }
        }

        this.table(rows, headerRows ?? 0);

        return end;
    }

    private csvTable(caption: string, options: ReadonlyMap<string, string>, content: readonly string[]): void {
        const named = options.get('delim') ?? ',';
        const delim = named === 'tab' ? '\t' : named === 'space' ? ' ' : Buffer.byteLength(named, 'utf8') === 1 ? named : ',';
        const header = options.get('header');
        const rows = header !== undefined ? [delimited(header, ',')] : [];

        for (const line of content) {
            if (spaceTrim(line) !== '') {
                rows.push(delimited(line, delim));
            }
        }

        const headerRows = phpInt(options.get('header-rows') ?? '0');
        this.table(rows, rows.length > 0 && header !== undefined ? 1 + headerRows : headerRows, caption);
    }
}

/** A list item's text (its marker, one space and the rest of the line), the column its content starts at and its kind, or null. */
function itemOf(line: string): Item | null {
    for (const [kind, pattern] of [['bullet', BULLET], ['enumerated', ENUMERATOR], ['field', FIELD]] as const) {
        const match = pattern.exec(line);

        if (match !== null) {
            const marker = match[0];

            return [
                trim(`${trim(marker)} ${line.slice(marker.length)}`),
                length(marker) + (marker.endsWith(' ') ? 0 : 1),
                kind === 'bullet' ? `${kind} ${trim(marker)}` : kind,
            ];
        }
    }

    const block = LINE_BLOCK.exec(line);

    return block === null ? null : [line.slice(block[0].length), block[1]!.length, 'line'];
}

/** A line opening a definition: not a list item or explicit markup, and followed directly by a more indented line. */
function isTerm(lines: readonly string[], i: number): boolean {
    const next = lines[i + 1] ?? '';

    return next !== '' && spaces(next) > spaces(lines[i]!) && itemOf(lines[i]!) === null && !EXPLICIT.test(lines[i]!);
}

/** The line after the block that line `i` opens: the lines after it that are blank or indented more than `indent`, without trailing blank lines. */
function extentEnd(lines: readonly string[], i: number, indent: number): number {
    let end = i + 1;

    for (let j = i + 1; j < lines.length; j++) {
        if (lines[j] === '') {
            continue;
        }
        if (spaces(lines[j]!) <= indent) {
            break;
        }
        end = j + 1;
    }

    return end;
}

/**
 * Adds one line of a grid row to its cells. The line is cut at the border's `+` columns where
 * it has a `|` or `+`, so text spanning columns lands in the first of them. A piece of only
 * `-`, `=` and `+` is a partial row separator and is skipped; a line-block `| ` opening a piece
 * is removed.
 */
function gridLine(line: string, bounds: readonly number[], previous: readonly string[]): string[] {
    const cells = new Map<number, string>(previous.map((cell, c) => [c, cell]));
    const last = bounds.length - 1;
    let from = 0;

    for (let b = 1; b <= last; b++) {
        const char = mbSubstr(line, bounds[b]!, 1);

        if (b < last && char !== '|' && char !== '+') {
            continue;
        }

        const segment = spaceTrim(mbSubstr(line, bounds[from]! + 1, bounds[b]! - bounds[from]! - 1)).replace(/^\| +/, '');

        if (!cells.has(from)) {
            cells.set(from, '');
        }
        if (!/^[-=+]+$/.test(segment)) {
            cells.set(from, spaceTrim(`${cells.get(from)} ${segment}`));
        }
        for (let c = from + 1; c < b; c++) {
            if (!cells.has(c)) {
                cells.set(c, '');
            }
        }
        from = b;
    }

    return [...cells].sort(([a], [b]) => a - b).map(([, cell]) => cell);
}

/** The rows of a list-table: each top-level bullet is a row, and each bullet at the column of the first inner bullet a cell; other lines continue the cell before them. */
function listTable(content: readonly string[]): string[][] {
    const rows: string[][] = [];
    let cellIndent: number | null = null;

    for (const raw of content) {
        let line = raw;
        let indent = spaces(line);
        const top = indent === 0 ? /^[-*+](?: +|$)/.exec(line) : null;

        if (top !== null) {
            rows.push([]);
            line = ' '.repeat(top[0].length) + line.slice(top[0].length);
            indent = top[0].length;
        }

        const row = rows.at(-1);

        if (row === undefined || spaceTrim(line) === '') {
            continue;
        }

        const cell = /^ *[-*+](?: +|$)([^\n]*)$/.exec(line);

        if (cell !== null && (cellIndent ?? indent) === indent) {
            cellIndent = indent;
            row.push(cell[1]!);
        } else if (row.length > 0) {
            row.push(spaceTrim(`${row.pop()} ${spaceTrim(line)}`));
        }
    }

    return rows;
}

function expandTabs(line: string): string {
    if (!line.includes('\t')) {
        return line;
    }

    const [first = '', ...pieces] = line.split('\t');
    let out = first;

    for (const piece of pieces) {
        out += ' '.repeat(8 - (length(out) % 8)) + piece;
    }

    return out;
}

function spaces(line: string): number {
    return /^ */.exec(line)![0].length;
}

/** PHP's `trim($text, ' ')`. */
function spaceTrim(text: string): string {
    return text.replace(/^ +| +$/g, '');
}

/** PHP's `mb_substr` with a start and length that are not negative. */
function mbSubstr(text: string, start: number, count?: number): string {
    const points = Array.from(text);

    return points.slice(start, count === undefined ? undefined : start + Math.max(0, count)).join('');
}

/** PHP's `array_slice` with a start that is not negative and a length that may be. */
function phpSlice<T>(items: readonly T[], start: number, count?: number): T[] {
    if (count === undefined) {
        return items.slice(start);
    }

    return count < 0 ? items.slice(start, items.length + count) : items.slice(start, start + count);
}

/** PHP's `(int)` on a string: its leading integer, or 0. */
function phpInt(text: string): number {
    const match = /^[ \t\n\r\v\f]*([+-]?\d+)/.exec(text);

    return match === null ? 0 : Number(match[1]);
}
