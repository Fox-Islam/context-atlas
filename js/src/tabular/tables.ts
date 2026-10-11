import { phpMax, phpMin, phpValueString, strnatcmp } from '../support/php.js';
import { length } from '../text.js';
import { BLANKS, cellNotes, dateOrder, parseCell, period, squashCell } from './cells.js';
import type { Cells } from './grid.js';
import { numberKey, type Value } from './number.js';

export interface RawRow {
    source: number;
    cells: string[];
}

export interface RawTable {
    title: string | null;
    caption: string[];
    header: string[];
    rows: RawRow[];
}

export interface Row {
    source: string;
    values: Record<string, Value>;
}

export type Role = 'constant' | 'period' | 'id' | 'text' | 'measure' | 'category' | 'label' | 'alias';

export interface Column {
    name: string;
    type: 'number' | 'date' | 'text';
    role: Role;
    blank: number;
    distinct: number;
    top: string[];
    unit: string | null;
    min?: number | string;
    max?: number | string;
    values?: string[];
    alias_of?: string;
}

/** A cleaned, profiled table, as it travels in a stored document's `data.tables`. */
export interface Table {
    id: string;
    sheet: string;
    title: string;
    caption: string[];
    melted: string | null;
    joined: string[];
    rows: Row[];
    row_notes: Record<string, number[]>;
    column_notes: Record<string, number[]>;
    notes: Record<string, string>;
    units: Record<string, string[]>;
    /** In column order. JSON objects keyed by name would put a name such as `2019` first in JavaScript. */
    columns: Column[];
}

/** The column of that name. */
export function columnNamed(table: Table, name: string): Column | undefined {
    return table.columns.find((column) => column.name === name);
}

const CATEGORY_LIMIT = 60;

const JOIN_OVERLAP = 0.9;

const LISTED: readonly Role[] = ['category', 'period', 'label', 'id'];

/**
 * A sheet's text lines and its tables. A table starts at a row of two or more cells and runs
 * while consecutive rows keep two or more; one-cell rows before it are its caption, and the last
 * `Table X` line among them is its title. A header with no rows under it is folded into the next
 * table's caption.
 */
export function split(rows: ReadonlyMap<number, Cells>): { text: string[]; tables: RawTable[] } {
    const text: string[] = [];
    const tables: RawTable[] = [];
    let current: RawTable | null = null;
    let pending: string[] = [];
    let previous: number | null = null;

    for (const [number, cells] of rows) {
        const wide = cells.size >= 2;

        if (current !== null && wide && previous !== null && number === previous + 1) {
            current.rows.push({ source: number, cells: row(cells, current.header.length) });
        } else if (wide) {
            if (current !== null) {
                tables.push(current);
            }
            current = { title: titleOf(pending), caption: pending, header: row(cells, Math.max(...cells.keys()) + 1), rows: [] };
            pending = [];
        } else {
            if (current !== null) {
                tables.push(current);
                current = null;
            }
            const line = squashCell([...cells.values()][0] ?? '');
            pending.push(line);
            text.push(line);
        }
        previous = number;
    }

    if (current !== null) {
        tables.push(current);
    }

    return { text, tables: foldEmpty(tables) };
}

/**
 * The table with its header cleaned, note markers split out, cells typed and, when there are at
 * least five period columns making up at least half the header, those melted into rows.
 */
export function normalise(id: string, sheet: string, title: string, raw: RawTable, notes: Record<string, string>): Table {
    const header: string[] = [];
    const columnNotes: Record<string, number[]> = {};

    raw.header.forEach((cell, i) => {
        let [name, refs] = cellNotes(cell);

        if (name === '') {
            name = `Column ${i + 1}`;
        } else if (name === '£' || name === '$' || name === '€') {
            name = `Amount (${name})`;
        }
        header[i] = name;
        if (refs.length > 0) {
            columnNotes[name] = refs;
        }
    });

    const periods = new Map<number, [string, number, number | null]>();
    raw.header.forEach((cell, i) => {
        const found = i > 0 ? period(cell) : null;

        if (found !== null) {
            periods.set(i, found);
        }
    });

    const melt = periods.size >= 5 && periods.size >= 0.5 * header.length;
    const quarters = [...periods.values()].some((found) => found[2] !== null);
    const valueName = valueNameOf(raw);
    const order = dateOrder(raw.rows.flatMap((each) => each.cells));

    let built: { source: string; values: Map<string, Value> }[] = [];
    const rowNotes: Record<string, number[]> = {};
    const units = new Map<string, Set<string>>();

    for (const each of raw.rows) {
        const base = new Map<string, Value>();

        header.forEach((name, i) => {
            if (melt && periods.has(i)) {
                return;
            }

            const [label, refs] = cellNotes(each.cells[i] ?? '');

            if (refs.length > 0) {
                rowNotes[label] = refs;
            }

            const [value, unit] = parseCell(label, order);

            if (unit !== null && unit !== 'date') {
                const seen = units.get(name) ?? new Set<string>();
                seen.add(unit);
                units.set(name, seen);
            }
            base.set(name, value);
        });

        if (!melt) {
            built.push({ source: `${sheet}!${each.source}`, values: base });
            continue;
        }

        for (const [i, [label, year, quarter]] of periods) {
            const values = new Map(base);

            if (!values.has('Year')) {
                values.set('Year', year);
            }
            if (quarters) {
                values.set('Quarter', quarter);
                values.set('Period', label);
            }
            values.set(valueName, parseCell(each.cells[i] ?? '', order)[0]);
            built.push({ source: `${sheet}!${each.source}`, values });
        }
    }

    if (melt) {
        built = nameValueByIndicator(built, valueName);
    }

    const labels = [...periods.values()];
    const rows = built.map((each) => ({ source: each.source, values: Object.fromEntries(each.values) }));

    return profile({
        id,
        sheet,
        title,
        caption: raw.caption,
        melted: melt ? `${periods.size} period columns (${labels[0]![0]} to ${labels[labels.length - 1]![0]}) turned into rows` : null,
        joined: [],
        rows,
        row_notes: rowNotes,
        column_notes: columnNotes,
        notes,
        units: Object.fromEntries([...units].map(([name, seen]) => [name, [...seen]])),
        columns: [],
    }, [...(built[0]?.values.keys() ?? [])]);
}

/**
 * Gives each column a type and a role: constant, period, id, measure, category (at most 60
 * values), label (more), text (long), or alias (another name for a label column).
 */
export function profile(table: Table, names: readonly string[]): Table {
    const columns = names.map((name) => columnOf(
        name,
        table.rows.filter((each) => Object.hasOwn(each.values, name)).map((each) => each.values[name] ?? null),
        table.rows.length,
        table.units[name]?.[0] ?? null,
    ));

    return markAliases({ ...table, columns });
}

/**
 * Copies the category columns of a lookup table, one whose label, id or alias column is unique,
 * onto the other tables of the document that have that column, when nine in ten of their values
 * are found in it. Lookups are the tables as they were before any copy; targets are the latest.
 */
export function join(tables: readonly Table[]): Table[] {
    const latest = [...tables];

    tables.forEach((lookup, l) => {
        for (const column of lookup.columns) {
            const key = column.name;

            if (!['label', 'id', 'alias'].includes(column.role) || column.distinct !== lookup.rows.length) {
                continue;
            }

            const index = new Map<string, Record<string, Value>>();

            for (const each of lookup.rows) {
                index.set(phpValueString(each.values[key] ?? null), each.values);
            }

            const copy = lookup.columns.filter((c) => c.role === 'category' && c.distinct < column.distinct).map((c) => c.name);

            latest.forEach((table, t) => {
                if (t !== l && copy.length > 0 && columnNamed(table, key) !== undefined && overlap(table, key, index) >= JOIN_OVERLAP) {
                    latest[t] = copyColumns(table, key, index, copy, lookup.title);
                }
            });
        }
    });

    return latest;
}

function row(cells: Cells, width: number): string[] {
    return Array.from({ length: width }, (_, i) => squashCell(cells.get(i) ?? ''));
}

function titleOf(lines: readonly string[]): string | null {
    return lines.filter((line) => /^Table \S+/i.test(line)).at(-1) ?? null;
}

function foldEmpty(tables: readonly RawTable[]): RawTable[] {
    const kept: RawTable[] = [];
    let carry: string[] = [];

    for (const table of tables) {
        if (table.rows.length === 0) {
            carry.push(...table.caption, table.header.filter((cell) => cell !== '' && cell !== '0').join(': '));
            continue;
        }
        kept.push(dropEmptyColumns({ ...table, caption: [...carry, ...table.caption] }));
        carry = [];
    }

    return kept;
}

function dropEmptyColumns(table: RawTable): RawTable {
    const keep = table.header
        .map((name, i) => (name !== '' || table.rows.some((each) => !BLANKS.has(each.cells[i] ?? '')) ? i : -1))
        .filter((i) => i !== -1);

    return {
        ...table,
        header: keep.map((i) => table.header[i]!),
        rows: table.rows.map((each) => ({ ...each, cells: keep.map((i) => each.cells[i]!) })),
    };
}

function valueNameOf(raw: RawTable): string {
    const text = `${raw.title ?? ''} ${raw.caption.join(' ')}`;

    return /\(([A-Za-z%£$€]{1,10})\)/u.exec(text)?.[1] ?? 'Value';
}

/** World Bank style tables name the measure in a constant "Indicator Name" column; the melted values take that name. */
function nameValueByIndicator(rows: { source: string; values: Map<string, Value> }[], valueName: string): { source: string; values: Map<string, Value> }[] {
    const first = rows[0]?.values ?? new Map<string, Value>();
    let indicator: string | null = null;

    for (const [name, value] of first) {
        if (/indicator|series/i.test(name) && typeof value === 'string'
            && new Set(rows.filter((each) => each.values.has(name)).map((each) => phpValueString(each.values.get(name) ?? null))).size === 1) {
            indicator = value;
            break;
        }
    }

    if (indicator === null) {
        return rows;
    }

    const name = indicator;

    return rows.map((each) => {
        const values = new Map(each.values);
        values.set(name, each.values.get(valueName) ?? null);
        values.delete(valueName);

        return { ...each, values };
    });
}

function columnOf(name: string, values: readonly Value[], count: number, unit: string | null): Column {
    const present = values.filter((value): value is number | string => value !== null);
    const counts = new Map<string, number>();

    for (const value of present) {
        const key = numberKey(value);
        counts.set(key, (counts.get(key) ?? 0) + 1);
    }

    const distinct = [...counts].sort(([, a], [, b]) => b - a).map(([key]) => key);
    const numbers = present.filter((value) => typeof value === 'number').length;
    const dates = present.filter((value) => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)).length;
    const type = present.length > 0 && numbers >= 0.9 * present.length
        ? 'number'
        : present.length > 0 && dates >= 0.9 * present.length ? 'date' : 'text';
    const average = distinct.reduce((sum, key) => sum + length(key), 0) / Math.max(1, distinct.length);
    const role: Role = distinct.length === 1 && count > 1 && present.length === count
        ? 'constant'
        : ['Year', 'Quarter', 'Period'].includes(name) || type === 'date'
            ? 'period'
            : distinct.length > 0.6 * present.length && /\b(no|number|id|code)\b/i.test(name)
                ? 'id'
                : type === 'text' && average > 80
                    ? 'text'
                    : type === 'number' ? 'measure' : distinct.length <= CATEGORY_LIMIT ? 'category' : 'label';

    const column: Column = {
        name,
        type,
        role,
        blank: count - present.length,
        distinct: distinct.length,
        top: distinct.slice(0, 6),
        unit: unit ?? unitFromName(name),
    };

    if (type === 'number' || type === 'date') {
        column.min = phpMin(present)!;
        column.max = phpMax(present)!;
    }
    if (LISTED.includes(role)) {
        column.values = role === 'period' ? [...distinct].sort(strnatcmp) : [...distinct];
    }

    return column;
}

function unitFromName(name: string): string | null {
    const match = /\((GWh|MWh|kWh|tonnes|km|kg|%|£|\$|€)\)/u.exec(name);

    if (match !== null) {
        return match[1]!;
    }

    return name.toLowerCase().includes('per cent') ? '%' : null;
}

/** A label, category or id column that maps one to one onto another with longer top values is an alias of it. */
function markAliases(table: Table): Table {
    const columns = table.columns.map((column) => ({ ...column }));
    const current = (name: string): Column => columns.find((column) => column.name === name)!;
    const labels = table.columns.filter((c) => ['label', 'category', 'id'].includes(c.role));

    for (const a of labels) {
        for (const b of labels) {
            if (a.name === b.name || a.distinct !== b.distinct || current(b.name).role === 'alias') {
                continue;
            }

            const map = new Map<string, Set<string>>();

            for (const each of table.rows) {
                const from = phpValueString(each.values[a.name] ?? null);
                const targets = map.get(from) ?? new Set<string>();
                targets.add(phpValueString(each.values[b.name] ?? null));
                map.set(from, targets);
            }

            const oneToOne = [...map.values()].every((targets) => targets.size <= 1);
            const size = (top: readonly string[]): number => top.reduce((sum, value) => sum + length(value), 0);

            if (oneToOne && size(a.top) < size(b.top)) {
                current(a.name).role = 'alias';
                current(a.name).alias_of = b.name;
                break;
            }
        }
    }

    return { ...table, columns };
}

function overlap(table: Table, key: string, index: ReadonlyMap<string, unknown>): number {
    const values = new Set(table.rows.map((each) => phpValueString(each.values[key] ?? null)));
    const hits = [...values].filter((value) => index.has(value)).length;

    return values.size === 0 ? 0 : hits / values.size;
}

function copyColumns(table: Table, key: string, index: ReadonlyMap<string, Record<string, Value>>, copy: readonly string[], from: string): Table {
    const rows = table.rows.map((each) => {
        const values = { ...each.values };

        for (const name of copy) {
            values[name] = index.get(phpValueString(each.values[key] ?? null))?.[name] ?? null;
        }

        return { ...each, values };
    });

    const names = table.columns.map((column) => column.name);

    return profile(
        { ...table, rows, joined: [...table.joined, `${copy.join(', ')} joined from "${from}" on ${key}`] },
        [...names, ...copy.filter((name) => !names.includes(name))],
    );
}
