import type { Block } from '../block.js';
import { length, squash, trim } from '../text.js';
import { BlockBuilder, dedent, delimited } from './blockBuilder.js';

/** PCRE without the `u` flag: ASCII classes, and `.` any character but a line feed. */
const HEADING = /^(={1,6}|#{1,6})[ \t]+([^\n]+?)(?:[ \t]+\1)?$/;

const SETEXT = /^(=+|-+|~+|\^+|\++)$/;

const DELIMITER = /^(?:-{4,}|\.{4,}|\+{4,}|_{4,}|={4,}|\*{4,}|--|[|,:]={3,})$/;

const ATTRIBUTE_ENTRY = /^:!?[A-Za-z0-9_][A-Za-z0-9_-]*!?:(?:[ \t][^\n]*)?$/;

const BLOCK_ATTRIBUTES = /^\[(|[#.%A-Za-z0-9_{,"'[][^\n]*)\]$/;

const BLOCK_TITLE = /^\.([^. \t][^\n]*)$/;

const PREPROCESSOR = /^(?:ifdef|ifndef|ifeval|endif)::[^ \t]*\[[^\n]*\]$/;

const INCLUDE = /^include(?:-code)?::([^[]*)\[[^\n]*\]$/;

const ATTRIBUTE_DEFINITION = /^:(!?)([A-Za-z0-9_][A-Za-z0-9_-]*)(!?):(?:[ \t]+([^\n]*))?$/;

const BLOCK_MACRO = /^[a-z][A-Za-z0-9_-]*::[^ \t]*\[[^\n]*\]$/;

const LIST_ITEM = /^[ \t]*(\*{1,5}|-|\.{1,5}|\d+\.|[A-Za-z]\.|[ivxIVX]+\)|<(?:\d+|\.)>)[ \t]+(?=[^ \t])/;

const DESCRIPTION = /^[ \t]*([^ \t][^\n]*?)(?::{2,4}|;;)(?:[ \t]+([^\n]*))?$/;

const CELL_SPEC = /^(?:(\d+)\*)?(?:(\d+)?(?:\.\d+)?\+)?[<^>]?(?:\.[<^>])?[adehlmsv]?$/;

const CELL_SPEC_END = /[ \t\n]((?:\d+\*)?(?:\d*(?:\.\d+)?\+)?[<^>]?(?:\.[<^>])?[adehlmsv]?)$/;

/** The built-in character attributes. */
const BUILT_IN: readonly (readonly [string, string])[] = [
    ['asterisk', '*'], ['nbsp', '\u00A0'], ['sp', ' '], ['empty', ''], ['blank', ''], ['zwsp', '\u200B'],
    ['amp', '&'], ['lt', '<'], ['gt', '>'], ['quot', '"'], ['apos', "'"], ['plus', '+'], ['caret', '^'],
    ['tilde', '~'], ['backtick', '`'], ['startsb', '['], ['endsb', ']'], ['vbar', '|'], ['pp', '++'],
    ['cpp', 'C++'], ['deg', '\u00B0'], ['two-colons', '::'], ['two-semicolons', ';;'], ['brvbar', '\u00A6'],
    ['ldquo', '\u201C'], ['rdquo', '\u201D'], ['lsquo', '\u2018'], ['rsquo', '\u2019'], ['wj', '\u2060'],
];

const SETEXT_LEVELS: Readonly<Record<string, number>> = { '=': 1, '-': 2, '~': 3, '^': 4, '+': 5 };

/**
 * Reads the block structure of AsciiDoc: section titles, paragraphs, lists, listing and literal
 * blocks, compound blocks read for their content, and tables. Attribute entries, block
 * attributes, comments and block macros are dropped. Inline markup is left in the text. The
 * rules are stated in spec/formats/asciidoc.md.
 */
export function parseAsciidoc(text: string): Block[] {
    const lines = text.replace(/\r\n?/g, '\n').split('\n').map((line) => line.replace(/[ \t]+$/, ''));
    const reader = new AsciidocReader();
    reader.run(lines, true);

    return reader.out.result();
}

class AsciidocReader {
    readonly out = new BlockBuilder();

    /** Attributes defined so far, by lowercased name, starting from the built-ins. */
    private readonly attributes = new Map<string, string>(BUILT_IN);

    run(lines: readonly string[], document: boolean): void {
        const n = lines.length;
        let blank = true;
        let attached = false;
        let attributes = '';
        let title = '';
        let listKind: string | null = null;

        for (let i = 0; i < n; i++) {
            const line = lines[i]!;

            if (line === '') {
                if (!this.out.inList()) {
                    this.out.close();
                }
                blank = true;
                attached = false;
                continue;
            }
            if (/^\/{4,}$/.test(line)) {
                i = closing(lines, i);
                continue;
            }
            if (line.startsWith('//') || PREPROCESSOR.test(line)) {
                continue;
            }

            const include = INCLUDE.exec(line);

            if (include !== null) {
                this.out.paragraph(`[include: ${this.inline(trim(include[1]!))}]`);
                blank = true;
                continue;
            }

            const kind = kindOf(line);
            const isItem = kind !== null;

            if (this.out.inList() && blank && kind !== listKind) {
                this.out.close();
            }

            const setext = this.setext(line);

            if (setext !== null) {
                if (document && setext[1] === 1 && this.out.isEmpty()) {
                    i = this.header(lines, i);
                }
                this.out.heading(this.inline(setext[0]), setext[1]);
                blank = true;
                continue;
            }

            const start = blank || attached || !this.out.isOpen();
            blank = false;
            attached = false;

            if (DELIMITER.test(line)) {
                const end = closing(lines, i);
                this.delimited(line, lines.slice(i + 1, end), attributes, title);
                [i, attributes, title, blank] = [end, '', '', true];
                continue;
            }

            const blockAttributes = BLOCK_ATTRIBUTES.exec(line);

            if (blockAttributes !== null) {
                this.out.close();
                attributes = blockAttributes[1]!;
                blank = true;
                continue;
            }
            if (line === '+') {
                attached = true;
                continue;
            }

            if (start) {
                if (ATTRIBUTE_ENTRY.test(line)) {
                    i = this.define(lines, i);
                    blank = true;
                    continue;
                }

                const blockTitle = BLOCK_TITLE.exec(line);

                if (blockTitle !== null) {
                    title = blockTitle[1]!;
                    blank = true;
                    continue;
                }

                const heading = HEADING.exec(line);

                if (heading !== null) {
                    const level = heading[1]!.length;

                    if (document && level === 1 && heading[1] === '=' && this.out.isEmpty()) {
                        i = this.header(lines, i);
                    }
                    this.out.heading(this.inline(heading[2]!), level);
                    [attributes, title, blank] = ['', '', true];
                    continue;
                }
                if (BLOCK_MACRO.test(line) || /^(?:'{3,}|<{3,})$/.test(line)) {
                    this.out.close();
                    blank = true;
                    continue;
                }

                if (title !== '') {
                    this.out.paragraph(this.inline(title));
                }
                title = '';

                const style = styleOf(attributes);
                attributes = '';

                if (!isItem && (['source', 'listing', 'literal'].includes(style) || line[0] === ' ' || line[0] === '\t')) {
                    let end = i;

                    while (end < n && lines[end] !== '') {
                        end++;
                    }
                    this.out.code(dedent(lines.slice(i, end)));
                    i = end - 1;
                    blank = true;
                    continue;
                }
            }

            if (isItem && (start || this.out.inList())) {
                listKind = this.out.inList() ? listKind : kind;
                this.out.item(this.inline(kind === 'description' ? description(line) : itemText(line)));
            } else if (this.out.inList()) {
                this.out.extend(this.inline(line));
            } else {
                this.out.text(this.inline(line));
            }
        }

        this.out.close();
    }

    /**
     * A two-line section title: an open paragraph of one line starting with a letter, digit or
     * `_`, underlined by one repeated character whose length is within one of the title's.
     */
    private setext(line: string): [string, number] | null {
        const title = this.out.inList() ? null : this.out.last();

        if (title === null || this.out.count() !== 1 || !SETEXT.test(line) || !/^[\p{L}\p{N}_]/u.test(title)) {
            return null;
        }
        if (Math.abs(length(line) - length(title)) > 1) {
            return null;
        }
        this.out.replaceLast('');

        return [title, SETEXT_LEVELS[line[0]!]!];
    }

    /** Skips the document header after the document title: the lines up to the first blank one. */
    private header(lines: readonly string[], i: number): number {
        let end = i;

        while ((lines[end + 1] ?? '') !== '') {
            end++;
            if (ATTRIBUTE_ENTRY.test(lines[end]!)) {
                end = this.define(lines, end);
            }
        }
        this.out.close();

        return end;
    }

    /**
     * Records the attribute entry at line `i` (a value continued by lines ending ` \\` is joined
     * with spaces; `:name!:` or `:!name:` removes it) and returns its last line.
     */
    private define(lines: readonly string[], start: number): number {
        let i = start;
        const match = ATTRIBUTE_DEFINITION.exec(lines[i]!);
        let value = match?.[4] ?? '';

        while (value.endsWith(' \\') && i + 1 < lines.length) {
            value = `${value.slice(0, -2)} ${trim(lines[++i]!)}`;
        }

        const name = (match?.[2] ?? '').toLowerCase();

        if ((match?.[1] ?? '') !== '' || (match?.[3] ?? '') !== '') {
            this.attributes.delete(name);
        } else {
            this.attributes.set(name, squash(this.inline(value)));
        }

        return i;
    }

    /** Inline substitutions: defined attribute references, then cross references and links reduced to their text. */
    private inline(text: string): string {
        return text
            .replace(/\{([A-Za-z0-9_][A-Za-z0-9_-]*)\}/g, (whole, name: string) => this.attributes.get(name.toLowerCase()) ?? whole)
            .replace(/xref:([^ \t[]+)\[([^\]]*)\]/g, (_, target: string, label: string) => (trim(label) !== '' ? trim(label) : target))
            .replace(/<<[^,>]+,[ \t]*([^>]+?)[ \t]*>>/g, '$1')
            .replace(
                /(?:link:([^ \t[]+)|\b((?:https?|ftp|irc):\/\/[^ \t[]+|mailto:[^ \t[]+))\[([^\]]*)\]/g,
                (_, link: string | undefined, url: string | undefined, label: string) => {
                    const target = link !== undefined && link !== '' ? link : url ?? '';

                    return trim(label) !== '' ? `${trim(label)} (${target})` : target;
                },
            );
    }

    private delimited(delimiter: string, content: readonly string[], attributes: string, title: string): void {
        this.out.close();

        const char = delimiter[0]!;

        if (/^[|,:]=/.test(delimiter)) {
            this.table(char, content, attributes, title);

            return;
        }
        if (char === '+') {
            return;
        }

        this.out.paragraph(title);
        if ((char === '-' && delimiter !== '--') || char === '.') {
            this.out.code(content);

            return;
        }
        this.run(content, false);
    }

    private table(char: string, content: readonly string[], attributes: string, title: string): void {
        const named = /\bformat="?(csv|dsv|tsv)\b/.exec(attributes);
        const format = named !== null ? named[1]! : ({ '|': 'psv', ',': 'csv', ':': 'dsv' } as Record<string, string>)[char]!;
        const implicit = (content[0] ?? '') !== '' && content[1] === '';
        const header = /%header\b|\b(?:options|opts)="?[^"\]]*\bheader\b/.test(attributes)
            || (implicit && !/%noheader\b|\b(?:options|opts)="?[^"\]]*\bnoheader\b/.test(attributes));
        let rows: string[][];

        if (format === 'psv') {
            rows = psvRows(content, attributes);
        } else {
            const separator = ({ csv: ',', dsv: ':', tsv: '\t' } as Record<string, string>)[format]!;
            rows = [];
            for (const line of content) {
                if (line.replace(/^[ \t]+|[ \t]+$/g, '') !== '') {
                    rows.push(format === 'csv' ? delimited(line, ',') : line.split(separator).map(trim));
                }
            }
        }

        rows = rows.map((row) => row.map((cell) => this.inline(cell)));
        this.out.table(this.inline(title), header ? rows.slice(0, 1) : [], header ? rows.slice(1) : rows);
    }
}

/** The index of the line closing the delimited block opened at `i`: the next line equal to it, or the line count. */
function closing(lines: readonly string[], i: number): number {
    for (let j = i + 1; j < lines.length; j++) {
        if (lines[j] === lines[i]) {
            return j;
        }
    }

    return lines.length;
}

function psvRows(content: readonly string[], attributes: string): string[][] {
    const first = content.find((line) => line.replace(/^[ \t]+|[ \t]+$/g, '') !== '') ?? '';
    const cells = cellsOf(content.join('\n'));
    const size = Math.max(1, columns(attributes) ?? cellsOf(first).length);
    const rows: string[][] = [];

    for (let offset = 0; offset < cells.length; offset += size) {
        rows.push(cells.slice(offset, offset + size));
    }

    return rows;
}

/**
 * The cells of PSV text: each unescaped `|` opens a cell, and a cell spec just before it (after
 * whitespace or at the start) applies to that cell; text before the first `|` is dropped.
 */
function cellsOf(text: string): string[] {
    const pieces = text.split(/(?<!\\)\|/);
    const last = pieces.length - 1;
    const opening = trim(pieces[0] ?? '');
    let spec = CELL_SPEC.test(opening) ? opening : '';
    const cells: string[] = [];

    for (let k = 1; k <= last; k++) {
        const [cell, next] = k < last ? cellSpec(pieces[k]!) : [pieces[k]!, ''];
        cells.push(...expand(cell.replaceAll('\\|', '|'), spec));
        spec = next;
    }

    return cells;
}

/** Splits a cell spec (`2+`, `3*`, `a`, `^.^`...) off the end of a cell's text. */
function cellSpec(text: string): [string, string] {
    const match = CELL_SPEC_END.exec(text);

    if (match !== null && match[1] !== '') {
        return [text.slice(0, text.length - match[1]!.length), match[1]!];
    }

    return [text, ''];
}

/** A cell repeated by a `n*` spec, each copy followed by empty cells for the columns a `n+` spec spans. */
function expand(text: string, spec: string): string[] {
    const match = CELL_SPEC.exec(spec);
    const copies = Math.max(1, Number((match?.[1] ?? '') !== '' ? match![1] : 1));
    const span = Math.max(1, Number((match?.[2] ?? '') !== '' ? match![2] : 1));
    const cell = [squash(text), ...Array.from({ length: span - 1 }, () => '')];

    return Array.from({ length: copies }, () => cell).flat();
}

/** The column count a `cols` attribute gives: a number, or a list of specs each counting one column or its `n*` multiplier. */
function columns(attributes: string): number | null {
    const match = /\bcols="([^"]*)"|\bcols=([^,\]]*)/.exec(attributes);

    if (match === null) {
        return null;
    }

    const value = trim((match[1] ?? '') !== '' ? match[1]! : match[2] ?? '');

    if (/^\d+$/.test(value)) {
        return Math.max(1, Number(value));
    }

    let count = 0;

    for (const entry of value.split(/[,;]/)) {
        const multiplier = /^[ \t\n\v\f\r]*(\d+)\*/.exec(entry);
        count += multiplier !== null ? Number(multiplier[1]) : 1;
    }

    return Math.max(1, count);
}

/** The block style: the first positional attribute, up to a `#`, `.`, `%` shorthand or comma. */
function styleOf(attributes: string): string {
    const match = /^([A-Za-z][A-Za-z0-9_-]*)/.exec(attributes);

    return match === null ? '' : match[1]!.toLowerCase();
}

/** The kind of list a line is an item of: unordered, ordered, callout or description; or null. */
function kindOf(line: string): string | null {
    const match = LIST_ITEM.exec(line);

    if (match !== null) {
        const marker = match[1]!;

        return marker[0] === '*' || marker === '-' ? 'unordered' : marker[0] === '<' ? 'callout' : 'ordered';
    }

    return DESCRIPTION.test(line) ? 'description' : null;
}

/** An item's marker, one space and the rest of its line. */
function itemText(line: string): string {
    const marker = LIST_ITEM.exec(line)?.[0] ?? '';

    return trim(`${trim(marker)} ${line.slice(marker.length)}`);
}

function description(line: string): string {
    const match = DESCRIPTION.exec(line);
    const definition = trim(match?.[2] ?? '');

    return `${trim(match?.[1] ?? line)}:${definition !== '' ? ` ${definition}` : ''}`;
}
