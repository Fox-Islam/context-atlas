import { Block, BlockType } from '../block.js';
import { squash, trim } from '../text.js';

/**
 * Collects the blocks of a line-based markup reader (reST, AsciiDoc, Org): an open paragraph
 * or list gathers lines until it is closed, and headings, code and tables close it first. The
 * shared rules are stated in spec/formats/rst.md under "Output".
 */
export class BlockBuilder {
    private readonly blocks: Block[] = [];

    private lines: string[] = [];

    private list = false;

    /** Adds a paragraph line, closing an open list first. */
    text(line: string): void {
        if (this.list) {
            this.close();
        }
        this.lines.push(trim(line));
    }

    /** Adds a list item, closing an open paragraph first. */
    item(line: string): void {
        if (!this.list) {
            this.close();
        }
        this.list = true;
        this.lines.push(trim(line));
    }

    /** Continues the last line or item, joined with a space. */
    extend(raw: string): void {
        const line = trim(raw);

        if (this.lines.length === 0) {
            this.lines.push(line);

            return;
        }

        const last = this.lines.pop()!;
        this.lines.push(last === '' ? line : line === '' ? last : `${last} ${line}`);
    }

    isOpen(): boolean {
        return this.lines.length > 0;
    }

    /** Whether nothing has been emitted and no line is open. */
    isEmpty(): boolean {
        return this.blocks.length === 0 && this.lines.length === 0;
    }

    inList(): boolean {
        return this.list && this.lines.length > 0;
    }

    count(): number {
        return this.lines.length;
    }

    last(): string | null {
        return this.lines.at(-1) ?? null;
    }

    /** Replaces the last line or item; an empty replacement removes it. */
    replaceLast(line: string): void {
        this.lines.pop();
        if (line !== '') {
            this.lines.push(line);
        }
    }

    paragraph(raw: string): void {
        this.close();

        const text = trim(raw);

        if (text !== '') {
            this.blocks.push(Block.paragraph(text));
        }
    }

    heading(raw: string, level: number): void {
        this.close();

        const text = trim(raw);

        if (text !== '') {
            this.blocks.push(Block.heading(text, level));
        }
    }

    code(raw: readonly string[]): void {
        this.close();

        const lines = [...raw];

        while (lines.length > 0 && trim(lines[0]!) === '') {
            lines.shift();
        }
        while (lines.length > 0 && trim(lines.at(-1)!) === '') {
            lines.pop();
        }
        if (lines.length > 0) {
            this.blocks.push(new Block(BlockType.Code, lines.join('\n')));
        }
    }

    /** `headerRows` are merged column by column into one header. */
    table(rawCaption: string, headerRows: readonly (readonly string[])[], rawRows: readonly (readonly string[])[]): void {
        this.close();

        const caption = squash(rawCaption);
        const header = merge(headerRows);
        const rows = rawRows.map((row) => row.map(squash)).filter((row) => row.join('') !== '');

        if (header.length === 0 && rows.length === 0) {
            return;
        }

        const lines = caption !== '' ? [`Table: ${caption}`] : [];

        if (header.length > 0) {
            lines.push(header.join(' | '));
        }
        for (const row of rows) {
            lines.push(row.join(' | '));
        }

        this.blocks.push(new Block(BlockType.Table, lines.map((line) => line.replace(/ +$/, '')).join('\n'), null, { caption, header, rows }));
    }

    close(): void {
        const lines = this.lines.filter((line) => line !== '');

        if (lines.length > 0) {
            this.blocks.push(Block.paragraph(lines.join(this.list ? '\n' : ' ')));
        }
        this.lines = [];
        this.list = false;
    }

    result(): Block[] {
        this.close();

        return this.blocks;
    }
}

/** Lines with the indentation they all share removed; blank lines do not count. */
export function dedent(lines: readonly string[]): string[] {
    let min: number | null = null;

    for (const line of lines) {
        if (line.replace(/^[ \t]+|[ \t]+$/g, '') !== '') {
            min = Math.min(min ?? Number.MAX_SAFE_INTEGER, leadingBlanks(line));
        }
    }

    return lines.map((line) => line.slice(Math.min(min ?? 0, leadingBlanks(line))));
}

/**
 * Splits one line of delimited values: a field opening with `"` (after spaces) runs to the next
 * `"` not doubled, `""` inside it standing for `"`; other fields are trimmed.
 */
export function delimited(line: string, separator: string): string[] {
    const fields: string[] = [];
    const length = line.length;
    let i = 0;

    for (;;) {
        while (i < length && (line[i] === ' ' || line[i] === '\t') && line[i] !== separator) {
            i++;
        }

        let field = '';

        if (i < length && line[i] === '"') {
            for (i++; i < length; i++) {
                if (line[i] === '"' && line[i + 1] === '"') {
                    field += '"';
                    i++;
                } else if (line[i] === '"') {
                    i++;
                    break;
                } else {
                    field += line[i];
                }
            }

            const end = line.indexOf(separator, i);
            i = end === -1 ? length : end;
        } else {
            const end = line.indexOf(separator, i);
            field = trim(line.slice(i, end === -1 ? length : end));
            i = end === -1 ? length : end;
        }

        fields.push(field);
        if (i >= length) {
            return fields;
        }
        i += separator.length;
    }
}

/** The count of spaces and tabs a line opens with. */
export function leadingBlanks(line: string): number {
    return /^[ \t]*/.exec(line)![0].length;
}

function merge(rows: readonly (readonly string[])[]): string[] {
    const header: string[] = [];

    for (const row of rows) {
        row.forEach((raw, c) => {
            const cell = squash(raw);
            const before = header[c] ?? '';
            header[c] = before === '' ? cell : cell === '' ? before : `${before} ${cell}`;
        });
    }

    return header.map((cell) => cell ?? '');
}
