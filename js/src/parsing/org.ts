import type { Block } from '../block.js';
import { squash, trim } from '../text.js';
import { BlockBuilder, dedent, leadingBlanks } from './blockBuilder.js';

/** PCRE without the `u` flag: ASCII classes, and `.` any character but a line feed. */
const HEADING = /^(\*+)[ \t]+([^\n]*)$/;

const BLOCK = /^[ \t]*#\+begin_([^ \t]+)(?:[ \t][^\n]*)?$/i;

const KEYWORD = /^[ \t]*#\+([^ \t]+?):(?:[ \t]+([^\n]*))?$/;

const COMMENT = /^[ \t]*#(?:[ \t]|$)/;

const DRAWER = /^[ \t]*:[A-Za-z0-9_-]+:$/;

const DRAWER_END = /^[ \t]*:end:$/i;

const PLANNING = /^[ \t]*(?:(?:SCHEDULED|DEADLINE|CLOSED):[ \t]*[<[][^>\]]*[>\]][ \t]*)+$/;

const FIXED_WIDTH = /^[ \t]*:(?: |$)/;

const TABLE = /^[ \t]*\|/;

const RULE = /^[ \t]*-{5,}$/;

const ITEM = /^([ \t]*)(?:([-+])|(\d+[.)])|(?<=[ \t])(\*))(?:[ \t]+|$)/;

const FOOTNOTE = /^\[fn:[^\]]+\]/;

const TAGS = /[ \t]+:(?:[\p{L}\p{N}_@#%]+:)+$/u;

const COOKIE = /[ \t]*\[\d*(?:\/\d*|%)\]/g;

/**
 * Reads the block structure of Org: headings with TODO keywords, priorities, tags and cookies
 * removed, paragraphs, lists, source and example blocks, fixed-width lines and tables.
 * Keywords, comments, drawers and planning lines are dropped. Inline markup is left in the
 * text. The rules are stated in spec/formats/org.md.
 */
export function parseOrg(text: string): Block[] {
    const lines = text.replace(/\r\n?/g, '\n').split('\n').map((line) => line.replace(/[ \t]+$/, ''));
    const reader = new OrgReader();

    for (const line of lines) {
        const todo = /^#\+(?:seq_|typ_)?todo:[ \t]*([^\n]*)$/i.exec(line);

        if (todo !== null) {
            for (const raw of todo[1]!.split(/[ \t]+/)) {
                const word = raw.replace(/\([^\n]*\)$/, '');

                if (word !== '' && word !== '|') {
                    reader.keywords.push(word);
                }
            }
        }
        if (reader.shift === 0 && /^#\+title:[ \t]*[^ \t]/i.test(line)) {
            reader.shift = 1;
        }
    }
    reader.run(lines);

    return reader.out.result();
}

class OrgReader {
    readonly out = new BlockBuilder();

    readonly keywords = ['TODO', 'DONE'];

    shift = 0;

    private titled = false;

    /** The `#+caption:` waiting for the next table; cleared by anything else. */
    private caption = '';

    run(lines: readonly string[]): void {
        const n = lines.length;
        let listBase = 0;
        let listKind = '';
        const caption = this.caption;
        this.caption = '';

        for (let i = 0; i < n; i++) {
            const line = lines[i]!;

            if (line === '') {
                if (!this.out.inList()) {
                    this.out.close();
                }
                continue;
            }

            const indent = leadingBlanks(line);
            const item = itemOf(line);
            const continues = item !== null && (indent > listBase || (indent === listBase && item === listKind));

            if (this.out.inList() && (item !== null ? !continues : indent <= listBase)) {
                this.out.close();
            }

            const next = this.element(lines, i);

            if (next !== null) {
                i = next;
                continue;
            }
            this.caption = '';

            if (item !== null) {
                if (!this.out.inList()) {
                    listBase = indent;
                    listKind = item;
                }
                this.out.item(itemText(line));
            } else if (this.out.inList()) {
                this.out.extend(line);
            } else {
                if (FOOTNOTE.test(line)) {
                    this.out.close();
                }
                this.out.text(line);
            }
        }

        this.out.close();
        this.caption = caption;
    }

    /**
     * An element other than paragraph text or a list item: a heading, block, keyword, comment,
     * drawer, planning line, fixed-width area, table or rule. The last line it used, or null.
     */
    private element(lines: readonly string[], i: number): number | null {
        const line = lines[i]!;
        const heading = HEADING.exec(line);

        if (heading !== null) {
            this.caption = '';

            return this.heading(lines, i, heading[1]!.length, heading[2]!);
        }

        const block = BLOCK.exec(line);

        if (block !== null) {
            const end = blockEnd(lines, i, block[1]!);
            this.out.close();
            if (end !== null) {
                this.block(block[1]!.toLowerCase(), lines.slice(i + 1, end));
            }

            return end ?? i;
        }

        const keyword = KEYWORD.exec(line);

        if (keyword !== null) {
            this.keyword(keyword[1]!.toLowerCase(), trim(keyword[2] ?? ''));

            return i;
        }
        if (COMMENT.test(line)) {
            return i;
        }
        if (DRAWER.test(line)) {
            for (let j = i + 1; j < lines.length && !HEADING.test(lines[j]!); j++) {
                if (DRAWER_END.test(lines[j]!)) {
                    return j;
                }
            }
        }
        if (PLANNING.test(line)) {
            return i;
        }
        if (FIXED_WIDTH.test(line)) {
            return this.fixedWidth(lines, i);
        }
        if (TABLE.test(line)) {
            const end = this.table(lines, i, this.caption);
            this.caption = '';

            return end;
        }
        if (RULE.test(line)) {
            this.out.close();

            return i;
        }

        return null;
    }

    private heading(lines: readonly string[], i: number, level: number, raw: string): number {
        const words = this.keywords.map(escape).join('|');
        let text = raw.replace(new RegExp(`^(?:${words})(?:[ \\t]+|$)`), '');
        text = text.replace(/^\[#[A-Za-z0-9]\](?:[ \t]+|$)/, '');

        if (/^COMMENT(?:[ \t]|$)/.test(text)) {
            this.out.close();
            for (let j = i + 1; j < lines.length; j++) {
                const next = HEADING.exec(lines[j]!);

                if (next !== null && next[1]!.length <= level) {
                    return j - 1;
                }
            }

            return lines.length - 1;
        }

        text = text.replace(TAGS, '');
        this.out.heading(squash(text.replace(COOKIE, '')), level + this.shift);

        return i;
    }

    private keyword(name: string, value: string): void {
        if (name === 'title' && !this.titled && value !== '') {
            this.titled = true;
            this.out.heading(value, 1);
        } else if (name === 'caption') {
            this.caption = value;
        }
        if (name !== 'caption' && !name.startsWith('attr_') && name !== 'name') {
            this.out.close();
        }
    }

    private block(name: string, content: readonly string[]): void {
        this.out.close();
        if (name === 'src' || name === 'example') {
            this.out.code(dedent(content).map((line) => line.replace(/^([ \t]*),(\*|#\+)/, '$1$2')));
        } else if (name !== 'export' && name !== 'comment') {
            this.run(dedent(content));
        }
    }

    private fixedWidth(lines: readonly string[], i: number): number {
        const code: string[] = [];
        let j = i;

        for (; j < lines.length && FIXED_WIDTH.test(lines[j]!); j++) {
            code.push(lines[j]!.replace(/^[ \t]*: ?/, ''));
        }
        this.out.code(code);

        return j - 1;
    }

    private table(lines: readonly string[], i: number, caption: string): number {
        const rows: string[][] = [];
        let headerRows: number | null = null;
        let j = i;

        for (; j < lines.length && TABLE.test(lines[j]!); j++) {
            const line = trim(lines[j]!);

            if (line.startsWith('|-')) {
                headerRows ??= rows.length === 0 ? null : rows.length;
                continue;
            }

            const inner = line.endsWith('|') && line.length > 1 ? line.slice(1, -1) : line.slice(1);
            const cells = inner.split('|').map(trim);

            if (!cookies(cells)) {
                rows.push(cells);
            }
        }
        if (headerRows === null || headerRows === rows.length) {
            headerRows = 0;
        }

        this.out.table(caption, rows.slice(0, headerRows), rows.slice(headerRows));

        return j - 1;
    }
}

function blockEnd(lines: readonly string[], i: number, name: string): number | null {
    const end = new RegExp(`^[ \\t]*#\\+end_${escape(name)}$`, 'i');

    for (let j = i + 1; j < lines.length; j++) {
        if (end.test(lines[j]!)) {
            return j;
        }
    }

    return null;
}

/** Whether a row holds only column-width cookies (`<10>`, `<l>`, `<r5>`) and empty cells. */
function cookies(cells: readonly string[]): boolean {
    return cells.every((cell) => cell === '' || /^<[lrc]?\d*>$/.test(cell)) && cells.join('') !== '';
}

/** An item's marker, one space and the rest of its line. */
function itemText(line: string): string {
    const marker = ITEM.exec(line)?.[0] ?? '';

    return trim(`${trim(marker)} ${line.slice(marker.length)}`);
}

/** The kind of list item a line opens, `unordered` or `ordered`, or null. */
function itemOf(line: string): string | null {
    const match = ITEM.exec(line);

    if (match === null || RULE.test(line)) {
        return null;
    }

    return (match[3] ?? '') !== '' ? 'ordered' : 'unordered';
}

function escape(text: string): string {
    return text.replace(/[.*+?^${}()|[\]\\/-]/g, '\\$&');
}
