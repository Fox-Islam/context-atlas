import { Block, BlockType } from '../block.js';
import { squash, trim } from '../text.js';
import { argument, convert, directArguments, group, optional, paragraph as paragraphText, space } from './latexInline.js';
import { readTable } from './latexTable.js';

const VERBATIM_BEGIN = /\\begin\{(verbatim|Verbatim|lstlisting|minted)(\*?)\}/;

/**
 * PCRE without the `u` flag: `.` is any character but a line feed. A `\verb` delimiter is one
 * character, an astral one its surrogate pair.
 */
const TOKEN = /(?<!\\)\\(?:[vV]erb\*?((?:[^A-Za-z \t\n*\uD800-\uDBFF]|[\uD800-\uDBFF][\uDC00-\uDFFF]))[^\n]*?\1|(begin|end)\{([^{}]*)\}|(part|chapter|section|subsection|subsubsection|paragraph|subparagraph)(?![A-Za-z])|(item|bibitem|caption|title|maketitle|par)(?![A-Za-z])|(\[))|(\n[ \t]*\n)|\x00(\d+)\x00/g;

const VERB_AT = /\\[vV]erb\*?((?:[^A-Za-z \t\n*\uD800-\uDBFF]|[\uD800-\uDBFF][\uDC00-\uDFFF]))/y;

const TITLE = /(?<!\\)\\title(?![A-Za-z])/;

const SECTIONS: Readonly<Record<string, number>> = { part: 0, chapter: 1, section: 2, subsection: 3, subsubsection: 4, paragraph: 5, subparagraph: 6 };

const MATH: ReadonlySet<string> = new Set(['equation', 'equation*', 'align', 'align*', 'gather', 'gather*', 'multline', 'multline*', 'flalign', 'flalign*', 'alignat', 'alignat*', 'eqnarray', 'eqnarray*', 'displaymath', 'math']);

const LISTS: ReadonlySet<string> = new Set(['itemize', 'itemize*', 'enumerate', 'enumerate*', 'description', 'description*', 'thebibliography']);

/** Tabular environment to its number of required arguments. */
const TABULARS: Readonly<Record<string, number>> = { tabular: 1, 'tabular*': 2, tabularx: 2, tabulary: 2, longtable: 1 };

const FLOATS: Readonly<Record<string, string>> = { table: 'table', 'table*': 'table', figure: 'figure', 'figure*': 'figure' };

const DROPPED: ReadonlySet<string> = new Set(['comment', 'tikzpicture', 'pgfpicture', 'picture', 'filecontents', 'filecontents*']);

type Event = { kind: 'block'; block: Block } | { kind: 'heading'; rank: number; text: string };

interface Frame {
    name: string;
    kind: string;
    count: number;
    caption: string | null;
    tables: string[][][];
}

/**
 * Reads LaTeX source: the body of the document environment, sectioning commands as headings,
 * verbatim and display maths as code, tabulars as tables and lists as list paragraphs. Inline
 * commands are unwrapped by `convert` (spec/formats/latex.md).
 */
export function parseLatex(text: string): Block[] {
    return new LatexReader().read(text);
}

class LatexReader {
    private readonly events: Event[] = [];

    private readonly codes: string[] = [];

    private paragraph = '';

    private frames: Frame[] = [];

    private listLines: string[] = [];

    private item: string | null = null;

    private marker = '';

    private label: string | null = null;

    private description = false;

    private indent = '';

    private maketitle = false;

    read(source: string): Block[] {
        const text = source.replace(/\r\n|\r/g, '\n').replaceAll('\x00', '');
        const clean = this.clean(text.startsWith('﻿') ? text.slice(1) : text);
        const begin = clean.indexOf('\\begin{document}');
        let body = clean;

        if (begin !== -1) {
            body = clean.slice(begin + '\\begin{document}'.length);
            const end = body.indexOf('\\end{document}');
            body = end === -1 ? body : body.slice(0, end);
        }

        this.walk(body);

        return this.blocks(titleOf(clean));
    }

    /** Removes comments and lifts verbatim environments out as numbered placeholder lines. */
    private clean(text: string): string {
        const out: string[] = [];
        let code: string[] = [];
        let verbatim: string | null = null;

        for (const line of text.split('\n')) {
            let rest = line;
            let fresh = true;

            for (;;) {
                if (verbatim !== null) {
                    const close = `\\end{${verbatim}}`;
                    const end = rest.indexOf(close);

                    if (end === -1) {
                        code.push(rest);
                        break;
                    }
                    if (trim(rest.slice(0, end)) !== '') {
                        code.push(rest.slice(0, end));
                    }
                    out.push(`\x00${this.codes.length}\x00`);
                    this.codes.push(code.join('\n'));
                    code = [];
                    verbatim = null;
                    rest = rest.slice(end + close.length);
                    fresh = false;
                    continue;
                }

                const [stripped, commented] = uncomment(rest);
                const match = VERBATIM_BEGIN.exec(stripped);

                if (match !== null) {
                    const before = stripped.slice(0, match.index);

                    if (trim(before) !== '') {
                        out.push(before);
                    }
                    verbatim = match[1]! + match[2]!;
                    rest = skipVerbatimArguments(rest.slice(match.index + match[0].length), match[1]!);
                    fresh = false;
                    if (trim(rest) === '') {
                        break;
                    }
                    continue;
                }

                if (trim(stripped) !== '' || (!commented && fresh)) {
                    out.push(stripped);
                }
                break;
            }
        }

        if (verbatim !== null) {
            out.push(`\x00${this.codes.length}\x00`);
            this.codes.push(code.join('\n'));
        }

        return out.join('\n');
    }

    private walk(body: string): void {
        let at = 0;
        const n = body.length;

        while (at < n) {
            TOKEN.lastIndex = at;
            const match = TOKEN.exec(body);

            if (match === null) {
                break;
            }

            this.append(body.slice(at, match.index));
            at = match.index + match[0].length;

            if (match[1] !== undefined) {
                this.append(match[0]);
            } else if (match[2] !== undefined) {
                at = match[2] === 'begin' ? this.begin(body, match[3]!, at) : this.end(match[3]!, at);
            } else if (match[4] !== undefined) {
                at = this.section(body, SECTIONS[match[4]]!, at);
            } else if (match[5] !== undefined) {
                at = this.command(body, match[5], at);
            } else if (match[6] !== undefined) {
                const end = body.indexOf('\\]', at);
                this.math(end === -1 ? body.slice(at) : body.slice(at, end));
                at = end === -1 ? n : end + 2;
            } else if (match[7] !== undefined) {
                this.breakParagraph();
            } else {
                this.code(this.codes[Number(match[8])] ?? '');
            }
        }

        if (at < n) {
            this.append(body.slice(at));
        }
        this.closeLists();
        this.flush();
    }

    private begin(body: string, name: string, at: number): number {
        if (MATH.has(name) || DROPPED.has(name)) {
            at = name.startsWith('alignat') && body[at] === '{' ? group(body, at)[1] : at;
            let content: string;
            [content, at] = capture(body, name, at);
            if (MATH.has(name)) {
                this.math(content);
            }

            return at;
        }
        if (Object.hasOwn(TABULARS, name)) {
            return this.tabular(body, name, at);
        }

        const kind = LISTS.has(name) ? 'list' : Object.hasOwn(FLOATS, name) ? FLOATS[name]! : 'other';

        if (kind === 'list') {
            this.listOpening();
            at = name === 'thebibliography' ? argument(body, at)[1] : at;
        } else {
            if (this.listDepth() === 0) {
                this.flush();
            }
            at = directArguments(body, at)[1];
        }
        this.frames.push({ name, kind, count: 0, caption: null, tables: [] });

        return at;
    }

    private end(name: string, at: number): number {
        let found = -1;

        this.frames.forEach((frame, i) => {
            if (frame.name === name) {
                found = i;
            }
        });
        if (found === -1) {
            return at;
        }

        while (this.frames.length > found) {
            const frame = this.frames.pop()!;

            if (frame.kind === 'list') {
                this.finishItem();
                if (this.listDepth() === 0) {
                    this.emitList();
                }
            } else if (frame.kind === 'table') {
                this.flush();
                this.emitFloat(frame.caption, frame.tables);
            } else if (this.listDepth() === 0) {
                this.flush();
            }
        }

        return at;
    }

    private section(body: string, rank: number, at: number): number {
        at = body[at] === '*' ? at + 1 : at;
        let value: string | null;
        [value, at] = argument(body, at);
        this.closeLists();
        this.flush();

        const text = value === null ? '' : squash(convert(value));

        if (text !== '') {
            this.events.push({ kind: 'heading', rank, text });
        }

        return at;
    }

    private command(body: string, name: string, at: number): number {
        if (name === 'par') {
            this.breakParagraph();
        } else if (name === 'maketitle') {
            this.maketitle = true;
        } else if (name === 'title') {
            at = argument(body, at)[1];
        } else if (name === 'caption') {
            at = body[at] === '*' ? at + 1 : at;
            let value: string | null;
            [value, at] = argument(body, at);
            this.caption(value === null ? '' : squash(convert(value)));
        } else {
            at = this.itemCommand(body, name, at);
        }

        return at;
    }

    private itemCommand(body: string, name: string, at: number): number {
        let label: string | null = null;
        const i = space(body, at);

        if (body[i] === '[') {
            let raw: string;
            [raw, at] = optional(body, i);
            label = squash(convert(raw));
        }
        if (name === 'bibitem') {
            at = argument(body, at)[1];
        }

        const index = this.listIndex();

        if (index < 0) {
            return at;
        }

        this.finishItem();
        const frame = this.frames[index]!;
        const enumerated = frame.name === 'enumerate' || frame.name === 'enumerate*';

        if (label === null && enumerated) {
            frame.count++;
        }
        this.indent = '  '.repeat(this.listDepth() - 1);
        this.description = frame.name === 'description' || frame.name === 'description*';
        this.label = label;
        this.marker = enumerated ? `${frame.count}.` : '-';
        this.item = '';

        return at;
    }

    private caption(text: string): void {
        for (let i = this.frames.length - 1; i >= 0; i--) {
            const frame = this.frames[i]!;

            if (frame.kind === 'table') {
                frame.caption = text;

                return;
            }
            if (frame.kind === 'figure') {
                this.interrupt();
                this.emitParagraph(text === '' ? '' : `Figure: ${text}`);

                return;
            }
        }

        this.interrupt();
        this.emitParagraph(text);
    }

    private tabular(body: string, name: string, at: number): number {
        let i = at;

        for (let required = TABULARS[name]!; required > 0;) {
            i = space(body, i, false);
            if (body[i] === '[') {
                i = optional(body, i)[1];
            } else if (body[i] === '{') {
                i = group(body, i)[1];
                required--;
            } else {
                break;
            }
        }

        const [content, after] = capture(body, name, i);
        const [caption, rows] = readTable(content, name === 'longtable');

        for (let f = this.frames.length - 1; f >= 0; f--) {
            const frame = this.frames[f]!;

            if (frame.kind === 'table') {
                frame.tables.push(rows);
                if (caption !== null) {
                    frame.caption = caption;
                }

                return after;
            }
        }

        this.interrupt();
        this.emitTable(caption ?? '', rows);

        return after;
    }

    private math(content: string): void {
        const lines = content.replace(/\\label\{[^{}]*\}/g, '').split('\n').map(trim).filter((line) => line !== '');

        if (lines.length > 0) {
            this.code(lines.join('\n'));
        }
    }

    private code(code: string): void {
        this.interrupt();
        if (trim(code) !== '') {
            this.events.push({ kind: 'block', block: new Block(BlockType.Code, code) });
        }
    }

    private append(raw: string): void {
        if (this.listDepth() === 0) {
            this.paragraph += raw;
        } else if (this.item !== null) {
            this.item += raw;
        }
    }

    private breakParagraph(): void {
        if (this.listDepth() === 0) {
            this.flush();
        } else {
            this.append(' ');
        }
    }

    private flush(): void {
        this.emitParagraph(squash(paragraphText(this.paragraph)));
        this.paragraph = '';
    }

    private emitParagraph(text: string): void {
        if (text !== '') {
            this.events.push({ kind: 'block', block: Block.paragraph(text) });
        }
    }

    /**
     * Closes the open paragraph, and the list lines so far, before a block that interrupts them;
     * an open item goes on after the block as an unmarked line.
     */
    private interrupt(): void {
        this.flush();
        if (this.listDepth() > 0) {
            const open = this.item !== null;
            this.finishItem();
            this.emitList();
            if (open) {
                this.item = '';
                this.marker = '';
                this.label = null;
                this.description = false;
            }
        }
    }

    private listOpening(): void {
        if (this.listDepth() === 0) {
            this.flush();
        } else {
            this.finishItem();
        }
    }

    private finishItem(): void {
        if (this.item === null) {
            return;
        }

        const text = squash(convert(this.item));
        this.item = null;
        let line: string;

        if (this.description) {
            const parts = [this.label ?? '', text].filter((part) => part !== '');
            line = parts.length === 0 ? '' : `- ${parts.join(': ')}`;
        } else if (this.label === null) {
            line = text === '' ? '' : trim(`${this.marker} ${text}`);
        } else {
            line = [this.label, text].filter((part) => part !== '').join(' ');
        }

        if (line !== '') {
            this.listLines.push(this.indent + line);
        }
    }

    private emitList(): void {
        if (this.listLines.length > 0) {
            this.events.push({ kind: 'block', block: Block.paragraph(this.listLines.join('\n')) });
        }
        this.listLines = [];
    }

    private closeLists(): void {
        if (this.listDepth() > 0) {
            this.finishItem();
            this.emitList();
            this.frames = this.frames.filter((frame) => frame.kind !== 'list');
        }
    }

    private emitFloat(caption: string | null, tables: readonly string[][][]): void {
        if (tables.length === 0) {
            this.emitParagraph(caption === null || caption === '' ? '' : `Table: ${caption}`);

            return;
        }
        for (const rows of tables) {
            this.emitTable(caption ?? '', rows);
        }
    }

    private emitTable(caption: string, rows: readonly string[][]): void {
        if (rows.length === 0) {
            return;
        }

        const lines = rows.map((row) => row.join(' | '));

        if (caption !== '') {
            lines.unshift(`Table: ${caption}`);
        }
        this.events.push({
            kind: 'block',
            block: new Block(BlockType.Table, lines.join('\n'), null, { caption, header: rows[0], rows: rows.slice(1) }),
        });
    }

    private listDepth(): number {
        return this.frames.filter((frame) => frame.kind === 'list').length;
    }

    private listIndex(): number {
        for (let i = this.frames.length - 1; i >= 0; i--) {
            if (this.frames[i]!.kind === 'list') {
                return i;
            }
        }

        return -1;
    }

    private blocks(title: string): Block[] {
        const ranks = this.events.flatMap((event) => (event.kind === 'heading' ? [event.rank] : []));
        const withTitle = title !== '' && (this.maketitle || ranks.length === 0);
        const top = ranks.length === 0 ? 0 : Math.min(...ranks);
        const blocks = withTitle ? [Block.heading(title, 1)] : [];

        for (const event of this.events) {
            blocks.push(event.kind === 'block' ? event.block : Block.heading(event.text, event.rank - top + (withTitle ? 2 : 1)));
        }

        return blocks;
    }
}

/** The line before its first unescaped `%`, and whether there was one. */
function uncomment(line: string): [string, boolean] {
    for (let i = 0; i < line.length; i++) {
        if (line[i] === '\\') {
            VERB_AT.lastIndex = i;
            const verb = VERB_AT.exec(line);

            if (verb !== null) {
                const close = line.indexOf(verb[1]!, i + verb[0].length);
                i = close === -1 ? line.length : close;
            } else {
                i++;
            }
        } else if (line[i] === '%') {
            return [line.slice(0, i), true];
        }
    }

    return [line, false];
}

function skipVerbatimArguments(rest: string, name: string): string {
    let i = space(rest, 0);

    if (rest[i] === '[') {
        i = space(rest, optional(rest, i)[1]);
    }
    if (name === 'minted' && rest[i] === '{') {
        i = group(rest, i)[1];
    }

    return rest.slice(i);
}

function titleOf(clean: string): string {
    const match = TITLE.exec(clean);

    if (match === null) {
        return '';
    }

    const [value] = argument(clean, match.index + match[0].length);

    return value === null ? '' : squash(convert(value));
}

/** The environment's content and the offset after its `\end`, counting nested environments of the same name. */
function capture(body: string, name: string, at: number): [string, number] {
    const open = `\\begin{${name}}`;
    const close = `\\end{${name}}`;
    let depth = 1;
    let from = at;

    for (;;) {
        const nextOpen = body.indexOf(open, from);
        const nextClose = body.indexOf(close, from);

        if (nextClose === -1) {
            return [body.slice(at), body.length];
        }
        if (nextOpen !== -1 && nextOpen < nextClose) {
            depth++;
            from = nextOpen + open.length;
            continue;
        }
        if (--depth === 0) {
            return [body.slice(at, nextClose), nextClose + close.length];
        }
        from = nextClose + close.length;
    }
}
