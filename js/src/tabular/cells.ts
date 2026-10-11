import { phpTrim } from '../support/php.js';
import { WHITESPACE } from '../text.js';
import type { Value } from './number.js';

export const BLANKS: ReadonlySet<string> = new Set(['', '#', '[x]', '[c]', '[z]', '[u]', '-', '–', '..', 'n/a', 'N/A', 'NA', ':']);

export type DateOrder = 'dmy' | 'mdy';

const SPACE_RUNS = new RegExp(`${WHITESPACE}+`, 'gu');

const DATE = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/;

const CURRENCY = new RegExp(`^(-?)${WHITESPACE}*([£$€])${WHITESPACE}*([^\n]*)$`, 'u');

const NUMBER = /^-?\d+(\.\d+)?([eE][-+]?\d+)?$/;

/** Whitespace runs to one space, then PHP's `trim()`. */
export function squashCell(text: string): string {
    return phpTrim(text.replace(SPACE_RUNS, ' '));
}

/**
 * A cell's value and the unit it carried: blanks and placeholders become null; currency,
 * percentages and thousands separators become numbers; `d/m/yyyy` dates become ISO dates with
 * the unit `date`. Anything else is the text as given.
 */
export function parseCell(raw: string, dateOrder: DateOrder): [Value, string | null] {
    let text = phpTrim(raw);

    if (BLANKS.has(text)) {
        return [null, null];
    }

    let unit: string | null = null;
    const currency = CURRENCY.exec(text);

    if (currency !== null) {
        unit = currency[2]!;
        text = currency[1]! + currency[3]!;
    }
    if (text.endsWith('%')) {
        unit = '%';
        text = text.replace(/[% ]+$/, '');
    }

    const number = text.replace(/[, ]/g, '');

    if (NUMBER.test(number)) {
        return [Number(number), unit];
    }

    const date = DATE.exec(text);

    if (date !== null) {
        const [day, month] = dateOrder === 'mdy' ? [date[2]!, date[1]!] : [date[1]!, date[2]!];

        return [`${date[3]}-${pad(month)}-${pad(day)}`, 'date'];
    }

    return [raw, null];
}

/** Day first, unless a first field above 12 is not met before a second field above 12. */
export function dateOrder(cells: Iterable<string>): DateOrder {
    for (const cell of cells) {
        const date = DATE.exec(phpTrim(cell));

        if (date === null) {
            continue;
        }
        if (Number(date[1]) > 12) {
            return 'dmy';
        }
        if (Number(date[2]) > 12) {
            return 'mdy';
        }
    }

    return 'dmy';
}

/** The text without `[note N]`, `[provisional]` and `[revised]` markers, and the note numbers. */
export function cellNotes(text: string): [string, number[]] {
    const numbers = [...text.matchAll(/\[note (\d+)\]/gi)].map((match) => Number(match[1]));

    return [squashCell(text.replace(/\[note \d+\]|\[provisional\]|\[revised\]/gi, '')), numbers];
}

/** The label, year and quarter of a period header: `2019`, `2019 1st quarter` or `2019Q1`. */
export function period(header: string): [string, number, number | null] | null {
    const [label] = cellNotes(header);
    let match = /^(\d{4})$/.exec(label);

    if (match !== null) {
        return [label, Number(match[1]), null];
    }

    match = /^(\d{4}) (1st|2nd|3rd|4th) quarter$/i.exec(label);

    if (match !== null) {
        return [label, Number(match[1]), Number(match[2]![0])];
    }

    match = /^(\d{4}) ?Q([1-4])$/i.exec(label);

    return match === null ? null : [label, Number(match[1]), Number(match[2])];
}

function pad(field: string): string {
    return String(Number(field)).padStart(2, '0');
}
