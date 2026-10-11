import { BlockType, type Block } from '../block.js';
import { phpArray, phpString } from '../support/php.js';
import { length, squash, trim, WHITESPACE } from '../text.js';

const HEAD_MAX = 60;

/** PCRE without the `u` flag: `\w` and `\s` are ASCII. */
const SIGNATURE = /^[A-Za-z_][\w.]*\(.*\)\s*(?:->.*)?$|^[A-Z]\w*\.\w+$/;

/** PCRE with the `u` flag: `\s` is Unicode whitespace and `\d` any decimal digit. */
const NON_SPACE = `[^${WHITESPACE.slice(1, -1)}]`;

const LIST_ITEM = new RegExp(`^${WHITESPACE}*(?:[-*+]|\\p{Nd}{1,9}[.)])${WHITESPACE}+(${NON_SPACE}[^\\n]*)$`, 'u');

const ITEM_NAME = new RegExp(`^([^\\n]{1,${HEAD_MAX}}?)(?::${WHITESPACE}| – | - | \\()`, 'u');

const GLOSSARY_ITEM = new RegExp(`^[^\\n]{1,${HEAD_MAX}}?(?::${WHITESPACE}| – | - )${NON_SPACE}`, 'u');

const VERSIONS: readonly [string, string][] = [
    ['added', '(?:added in version|versionadded::?|new in version)'],
    ['changed', '(?:changed in version|versionchanged::?)'],
    ['deprecated', '(?:deprecated since version|deprecated::?)'],
];

export interface SectionBlock {
    block: Block;
    path: readonly string[];
}

export interface Entry {
    name: string;
    text: string;
    path: readonly string[];
    kind: 'entry' | 'row';
    group?: string;
    /** Field names with their values, both in first-seen order. */
    fields: [string, string[]][];
    /** Positions in the section's block list. */
    blocks: number[];
}

interface Open {
    name: string;
    signature: string;
    lines: string[];
    path: readonly string[];
    blocks: number[];
}

/**
 * The entries of a reference section: one per documented member, glossary term or table row,
 * each with its name and the fields a filter can match. An entry starts at a block whose meta
 * names it (`entry`), or by layout: a short line with no sentence end followed by a paragraph
 * more than twice its length, or a call signature such as `Path.walk(top_down=True)`. The
 * blocks after it belong to it until the next entry, table or heading.
 */
export function buildEntries(blocks: readonly SectionBlock[]): Entry[] {
    const entries: Entry[] = [];
    let current: Open | null = null;
    const byMeta = blocks.some((item) => typeof item.block.meta['entry'] === 'string');
    const close = (): void => {
        if (current !== null) {
            const text = current.lines.join('\n\n');
            entries.push({ name: current.name, text, path: current.path, kind: 'entry', fields: fieldsOf(current.signature, text), blocks: current.blocks });
        }
        current = null;
    };

    blocks.forEach((item, i) => {
        const block = item.block;

        if (block.isHeading()) {
            close();

            const next = blocks[i + 1]?.block;

            if (next === undefined || (next.isHeading() && (next.level ?? 0) <= (block.level ?? 0))) {
                entries.push({ name: squash(block.text), text: squash(block.text), path: item.path, kind: 'entry', fields: [], blocks: [i] });
            }

            return;
        }
        if (block.type === BlockType.Table) {
            close();
            entries.push(...rows(block, item.path, i));

            return;
        }

        const name = headOf(blocks, i, byMeta);

        if (name !== null) {
            close();
            const signature = block.meta['signature'];
            current = { name, signature: typeof signature === 'string' ? signature : name, lines: [trim(block.text)], path: item.path, blocks: [i] };

            return;
        }
        if (current !== null) {
            current.lines.push(trim(block.text));
            current.blocks.push(i);

            return;
        }

        const items = listItems(block);

        if (items.length > 0 && isGlossary(items)) {
            for (const line of items) {
                entries.push({ name: itemName(line), text: line, path: item.path, kind: 'entry', fields: fieldsOf('', line), blocks: [i] });
            }

            return;
        }

        current = { name: item.path.at(-1) ?? 'section', signature: '', lines: [trim(block.text)], path: item.path, blocks: [i] };
    });

    close();

    return entries;
}

/** The entry name a block starts, or null. Where meta names entries, only those start one. */
function headOf(blocks: readonly SectionBlock[], i: number, byMeta: boolean): string | null {
    const block = blocks[i]!.block;
    const text = squash(block.text);

    if (byMeta) {
        const entry = block.meta['entry'];

        return typeof entry === 'string' && (i === 0 || blocks[i - 1]!.block.meta['entry'] !== entry) ? entry : null;
    }
    if (block.type !== BlockType.Paragraph || !/\p{L}/u.test(text)) {
        return null;
    }
    if (SIGNATURE.test(text) && length(text) <= 2 * HEAD_MAX) {
        return text;
    }

    const next = blocks[i + 1]?.block ?? null;
    const short = length(text) <= HEAD_MAX && !/[.!?:;,]$/u.test(text);

    return short && next !== null && next.type === BlockType.Paragraph && length(trim(next.text)) > 2 * length(text) ? text : null;
}

/** The items of a list paragraph (every line opening with `- `, `* `, `+ ` or `1.`/`1)`), markers removed; empty for any other block. */
function listItems(block: Block): string[] {
    if (block.type !== BlockType.Paragraph) {
        return [];
    }

    const items: string[] = [];

    for (const line of trim(block.text).split('\n')) {
        const match = LIST_ITEM.exec(line);

        if (match === null) {
            return [];
        }
        items.push(trim(match[1]!));
    }

    return items;
}

/** Whether more than half the items open with a name followed by `: `, ` – ` or ` - ` within 60 characters. */
function isGlossary(items: readonly string[]): boolean {
    return 2 * items.filter((line) => GLOSSARY_ITEM.test(line)).length > items.length;
}

/** An item's name: its text up to the first `: `, ` – `, ` - ` or ` (` within 60 characters, else its first 60 characters. */
function itemName(item: string): string {
    const match = ITEM_NAME.exec(item);

    return trim(match !== null ? match[1]! : Array.from(item).slice(0, HEAD_MAX).join(''));
}

/** Parameters from a call signature, versions from "Added in version 3.12" notes and reST directives, and exception names. */
function fieldsOf(signature: string, text: string): [string, string[]][] {
    const fields: [string, string[]][] = [];
    const brackets = /\((.*)\)/.exec(signature);

    if (brackets !== null) {
        const parameters = brackets[1]!.split(/[ \t\n\v\f\r]*,[ \t\n\v\f\r]*/)
            .map((parameter) => trim(parameter).replace(/[=:][^\n]*$/, '').replace(/^\*+/, ''))
            .filter((parameter) => parameter !== '' && parameter !== '/' && parameter !== 'self');

        if (parameters.length > 0) {
            fields.push(['parameter', parameters]);
        }
    }
    for (const [field, pattern] of VERSIONS) {
        const versions = [...text.matchAll(new RegExp(`${pattern}[ \\t\\n\\v\\f\\r]*(\\d+(?:\\.\\d+)*)`, 'gi'))].map((match) => match[1]!);

        if (versions.length > 0) {
            fields.push([field, [...new Set(versions)]]);
        }
    }

    const exceptions = [...text.matchAll(/\b([A-Z][A-Za-z]*(?:Error|Exception|Warning))\b/g)].map((match) => match[1]!);

    if (exceptions.length > 0) {
        fields.push(['exception', [...new Set(exceptions)]]);
    }

    return fields;
}

/**
 * One entry per table row. When the row key is an index (one or two digits, or a Roman numeral)
 * and two or more columns are headed by a one- or two-letter group ("A series ..."), each group
 * is its own entry named group + key, since a reader asks for "C5", which no cell holds; when
 * only the caption names a series, the entry is named series + key.
 */
function rows(table: Block, path: readonly string[], index: number): Entry[] {
    const [caption, header, body] = tableOf(table);
    const groups = new Map<string, number[]>();

    header.forEach((label, c) => {
        const match = c > 0 ? /^([A-Z]{1,2})(?: series)?\b/.exec(label) : null;

        if (match !== null) {
            groups.set(match[1]!, [...(groups.get(match[1]!) ?? []), c]);
        }
    });

    const series = /\b([A-Z]{1,3}) series\b/.exec(caption)?.[1] ?? null;
    const section = path.join(' > ');
    const context = trim((section !== '' ? `[${section}] ` : '') + (caption !== '' ? `Table: ${caption}` : ''));
    const entries: Entry[] = [];

    for (const row of body) {
        const key = trim(row[0] ?? '');

        if (key === '' || row.filter((cell) => trim(cell) !== '').length < 2) {
            continue;
        }

        const isIndex = /^(\d{1,2}|[IVX]{1,5})$/.test(key);
        const all = Array.from({ length: Math.max(1, row.length - 1) }, (_, i) => i + 1);
        const names: [string, number[]][] = isIndex && groups.size >= 2
            ? [...groups].map(([group, columns]) => [group + key, columns])
            : isIndex && series !== null ? [[series + key, all]] : [[key, all]];

        for (const [name, columns] of names) {
            const fields: [string, string[]][] = [];
            const labels: string[] = [];

            for (const c of columns) {
                const value = trim(row[c] ?? '');

                if (value !== '') {
                    const head = trim(header[c] ?? `column ${c}`);
                    const existing = fields.find(([field]) => field === head);

                    if (existing === undefined) {
                        fields.push([head, [value]]);
                    } else {
                        existing[1].push(value);
                    }
                    labels.push(`${head}: ${value}`);
                }
            }

            entries.push({
                name,
                text: `${context}\n${header.join(' | ')}\n${row.join(' | ')}\nEntry ${name}: ${labels.join('; ')}`,
                path,
                kind: 'row',
                group: caption !== '' ? caption : section,
                fields,
                blocks: [index],
            });
        }
    }

    return entries;
}

/**
 * A table's caption, header and rows: from the block's meta when given, otherwise from its text
 * (lines split on `|`, a leading `Table: ...` line as the caption, Markdown separator rows
 * dropped, the first row as the header).
 */
function tableOf(table: Block): [string, string[], string[][]] {
    const header = table.meta['header'];
    const body = table.meta['rows'];

    if (Array.isArray(header) && Array.isArray(body)) {
        const caption = table.meta['caption'];

        return [
            phpString(caption ?? ''),
            header.map(phpString),
            body.map((row) => phpArray(row).map(([, cell]) => phpString(cell))),
        ];
    }

    let caption = '';
    const lines: string[][] = [];

    for (const raw of table.text.split('\n')) {
        const line = trim(raw);
        const titled = /^Table:[ \t\n\v\f\r]*([^\n]*)$/.exec(line);

        if (titled !== null) {
            caption = titled[1]!;
        } else if (line.includes('|') && !/^\|?[ \t\n\v\f\r:|-]+\|?$/.test(line)) {
            lines.push(line.replace(/^\|+|\|+$/g, '').split('|').map(trim));
        }
    }

    return [caption, lines.shift() ?? [], lines];
}
