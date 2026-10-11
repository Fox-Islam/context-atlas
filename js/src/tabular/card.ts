import { ordered } from '../support/ordered.js';
import { formatNumber } from './number.js';
import type { Table } from './tables.js';

export interface Card {
    title: string;
    sheet: string;
    records: number;
    columns: Record<string, string>;
    about?: string;
    layout?: string;
    joined?: string;
}

/**
 * A table described for Jev and for the reader: title, caption, record count and each column's
 * role, unit and values. Every category value is listed, because a card that shows only some
 * leads Jev to judge the others absent.
 */
export function cardOf(table: Table, examples: number): Card {
    return cardWith(table, ordered(describe(table, examples)));
}

/** The card as plain text, for embedding and for questions about what the data means, its columns in column order. */
export function cardText(table: Table, examples: number): string {
    return Object.entries(cardWith(table, {})).map(([key, value]) => (typeof value === 'object'
        ? `${key}:\n${describe(table, examples).map(([name, description]) => `  - ${name}: ${description}`).join('\n')}`
        : `${key}: ${String(value)}`)).join('\n');
}

function describe(table: Table, examples: number): [string, string][] {
    const columns: [string, string][] = [];

    for (const column of table.columns) {
        const values = column.values ?? [];
        let description: string;

        switch (column.role) {
            case 'measure':
                description = `number${column.unit !== null ? ` (${column.unit})` : ''}, ${formatNumber(column.min ?? null)} to ${formatNumber(column.max ?? null)}`;
                break;
            case 'period':
                description = `${column.type === 'date' ? 'date' : 'period'}, ${column.distinct} values from ${values[0] ?? ''} to ${lastOf(values)}`;
                break;
            case 'constant':
                description = `same in every record: ${column.top[0] ?? ''}`;
                break;
            case 'alias':
                description = `another name for each ${column.alias_of ?? ''}, e.g. ${column.top.slice(0, 3).join('; ')}`;
                break;
            case 'category':
                description = `one of ${column.distinct} values: ${values.join('; ')}`;
                break;
            default:
                description = `${column.role === 'id' ? 'identifier' : 'text'}, ${column.distinct} distinct, e.g. ${column.top.slice(0, examples).map((value) => Array.from(value).slice(0, 60).join('')).join('; ')}`;
        }
        if (column.blank > 0 && column.role !== 'constant') {
            description += `; blank in ${column.blank} records`;
        }
        columns.push([column.name, description]);
    }

    return columns;
}

function cardWith(table: Table, columns: Record<string, string>): Card {
    const card: Card = { title: table.title, sheet: table.sheet, records: table.rows.length, columns };

    if (table.caption.length > 0) {
        card.about = table.caption.slice(0, 4).join(' ');
    }
    if (table.melted !== null) {
        card.layout = table.melted;
    }
    if (table.joined.length > 0) {
        card.joined = table.joined.join('; ');
    }

    return card;
}

/** PHP's `end($values) ?: ''`: the last value, or '' when there is none or it is falsy. */
function lastOf(values: readonly string[]): string {
    const last = values.at(-1);

    return last === undefined || last === '' || last === '0' ? '' : last;
}
