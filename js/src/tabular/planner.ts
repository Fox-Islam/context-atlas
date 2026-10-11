import { choice, noul, type ChoiceCriteria, type Questions, type SystemOneRequest } from '@typesafe-ai/sdk';
import { ordered } from '../support/ordered.js';
import { phpFloat } from '../support/php.js';
import { length, trim, WHITESPACE } from '../text.js';
import { send, type Answers, type Jev } from '../typesafe/jev.js';
import { cardOf, type Card } from './card.js';
import { relax } from './executor.js';
import { formatNumber, numberKey } from './number.js';
import { columnNamed, type Column, type Table } from './tables.js';

const QUESTION = 'the question being asked';

const OPERATIONS: Readonly<Record<string, string>> = {
    list: 'show the matching records themselves (one value, a few records, or the records that meet a condition)',
    sum: 'add the values up into a total',
    average: 'the average (mean) of the values',
    count: 'count how many records there are',
    max: 'the single highest value, and the record it belongs to',
    min: 'the single lowest value, and the record it belongs to',
};

const CHANGES: Readonly<Record<string, string>> = {
    change: 'how much the value rose or fell from the start to the end of a span of periods (the difference), or which rose or fell the most',
    percent: 'by what percentage the value rose or fell from the start to the end of a span of periods, or which rose or fell the most in percentage terms',
};

const NUMBER_WORDS: Readonly<Record<string, number>> = { a: 1, one: 1, two: 2, three: 3, four: 4, five: 5, six: 6, seven: 7, eight: 8, nine: 9, ten: 10, twelve: 12, twenty: 20, fifty: 50, hundred: 100 };

const SHORTLIST = 12;

const STOP_WORDS: ReadonlySet<string> = new Set(['the', 'and', 'for', 'what', 'was', 'were', 'how', 'much', 'many', 'did', 'does', 'which', 'with', 'from', 'that', 'this', 'each', 'all', 'any', 'are', 'who', 'its', 'their', 'than', 'more', 'less', 'over', 'under', 'between', 'total', 'show', 'every', 'list', 'compare']);

/** A filter: values by key, a period range with either end open, or a threshold on a number. */
export type Filter = ['in', string[]] | ['range', string | null, string | null] | ['threshold', string, number];

/** The one constraint left out when the plan selects no record and leaving it out lets some through. */
export interface Relaxed {
    drop: 'filter' | 'blank';
    column: string;
    matched: number;
}

export interface Plan {
    measure: string | null;
    operation: string;
    group: string | null;
    /** By column, in the order the columns were asked about. */
    filters: Record<string, Filter>;
    drop_blank: string[];
    /** Value columns the question filtered on where no offered value was chosen. */
    unmatched?: string[];
    relaxed?: Relaxed | null;
}

export interface Planned {
    table: Table | null;
    meaning: boolean;
    plan: Plan | null;
    trace: Record<string, unknown>;
}

type Ask =
    | { kind: 'range'; options: string[] }
    | { kind: 'threshold'; options: Record<string, string>; numbers: [number, string][] }
    | { kind: 'values'; options: Record<string, string> };

/**
 * Turns a question into a query plan over tables in up to three Jev requests: which table
 * (and whether the question asks what the data means, and whether it is answerable at all);
 * the measure, operation, grouping, filtered columns and blank rows to leave out; and the
 * filter values. Every option is built from the table's schema, so Jev picks and never writes.
 */
export class Planner {
    constructor(private readonly jev: Jev) {}

    /** Null when Jev did not answer. */
    async plan(question: string, tables: readonly Table[]): Promise<Planned | null> {
        const route = await this.route(question, tables);

        if (route === null) {
            return null;
        }
        if (route.table === null || route.meaning) {
            return { ...route, plan: null };
        }

        const columns = await this.columns(route.table, question);

        if (columns === null) {
            return null;
        }

        const values = await this.values(route.table, question, columns.filterColumns);

        if (values === null) {
            return null;
        }

        const [filters, unmatched] = values;
        const plan: Plan = { measure: columns.measure, operation: columns.operation, group: columns.group, filters, drop_blank: columns.dropBlank, unmatched, relaxed: null };
        plan.relaxed = relax(route.table, plan, named(question, filters));

        const { filters: _, ...rest } = plan;

        return { table: route.table, meaning: false, plan, trace: { ...route.trace, filters, ...rest } };
    }

    private async route(question: string, tables: readonly Table[]): Promise<Omit<Planned, 'plan'> | null> {
        const labels = new Map(tables.map((table, i) => [`t${i + 1}`, table]));
        const cards: Record<string, Record<string, unknown>> = {};
        const criteria: ChoiceCriteria = {};

        for (const [label, table] of labels) {
            cards[label] = wire(withHints(cardOf(table, 6), table, question));
            criteria[label] = `${table.title} (sheet ${table.sheet})`;
        }
        criteria['none'] = 'none of the tables holds the data the question needs';

        const answers = await send(this.jev, request({ [QUESTION]: question, 'the tables in the workbook': cards }, {
            table: choice('Which table holds the data needed to answer the question?', criteria),
            meaning: noul('Is the question about what the data means or covers (what a column, label or term means, what is recorded, the unit, the period covered), rather than asking for values from it?', {
                true: 'the question asks what something means, what is recorded, the unit or the coverage',
                false: 'the question asks for one or more values, records, totals, counts or comparisons',
            }),
            answerable: noul('Do the tables hold the data needed to answer the question?', {
                true: 'the measure, and the things, places and periods the question names, are all within what the tables record',
                false: 'the question needs a measure, period, place or detail that none of the tables records',
            }),
        }));

        if (answers === null) {
            return null;
        }

        const pick = answers.choice('table') ?? 'none';
        const answerable = answers.noul('answerable') ?? 0;
        const meaning = answers.noul('meaning') ?? 0;
        const table = pick !== 'none' && answerable >= 0.5 ? labels.get(pick) ?? null : null;

        return { table, meaning: table !== null && meaning >= 0.5, trace: { table: table?.id ?? null, answerable, meaning } };
    }

    private async columns(table: Table, question: string): Promise<{ measure: string | null; operation: string; group: string | null; filterColumns: string[]; dropBlank: string[] } | null> {
        const all = table.columns;
        const measures = labelled(all.filter((c) => c.role === 'measure'), 'm');
        const groups = labelled(all.filter((c) => ['category', 'period', 'label'].includes(c.role)), 'g');
        const filterable = all.filter((c) => !['constant', 'text', 'alias'].includes(c.role));
        const blanks = all.filter((c) => c.role === 'category' && c.blank > 0);
        const periods = all.some((c) => c.role === 'period');
        const questions: Questions = {};

        if (measures.size > 0) {
            const criteria: ChoiceCriteria = {};

            for (const [label, column] of measures) {
                criteria[label] = column.name + (column.unit !== null ? ` (${column.unit})` : '');
            }
            criteria['none'] = 'no number column is needed: the question counts or lists records';
            questions['measure'] = choice('Which number column does the answer come from?', criteria);
        }

        questions['operation'] = choice('What does the answer need done with the matching records?', { ...OPERATIONS, ...(measures.size > 0 && periods ? CHANGES : {}) });

        const groupCriteria: ChoiceCriteria = { none: 'one answer overall, or the records themselves' };

        for (const [label, column] of groups) {
            groupCriteria[label] = `a separate figure for each ${column.name}`;
        }
        questions['group'] = choice('Does the question want a separate figure for each value of a column (for example each organisation, each region or each year)? Pick that column, or none.', groupCriteria);

        if (groups.size > 0) {
            const rankCriteria: ChoiceCriteria = { none: 'no: it does not ask which one comes first or last' };

            for (const [label, column] of groups) {
                rankCriteria[label] = `which ${column.name} comes first or last`;
            }
            questions['rank'] = choice('Does the question ask which value of a column comes first or last (has the most or fewest records, the highest or lowest total, or rose or fell the most)? Pick that column, or none.', rankCriteria);
        }

        filterable.forEach((column, i) => {
            const what = column.role === 'measure' ? 'a threshold on' : 'particular values or a range of';
            questions[`f${i}`] = noul(`Does the question limit which records count by \`${column.name}\`?`, {
                true: `the question names ${what} \`${column.name}\` (directly or in other words)`,
                false: `the question does not restrict \`${column.name}\``,
            });
        });
        blanks.forEach((column, i) => {
            questions[`b${i}`] = noul(`Should the records with no \`${column.name}\` be left out? They are: ${blankExamples(table, column.name).join('; ')}`, {
                true: 'the question is about a kind of thing those records are not (for example individual countries, not groups of countries)',
                false: 'those records are among what the question asks about, or it makes no difference',
            });
        });

        const answers = await send(this.jev, request({ [QUESTION]: question, 'the table': wire(cardOf(table, 8)) }, questions));

        if (answers === null) {
            return null;
        }

        const measure = measures.get(answers.choice('measure') ?? '') ?? null;
        const operation = answers.choice('operation') ?? 'list';
        let group = answers.choice('group');

        if ((group ?? 'none') === 'none' && ranks(operation, measure)) {
            group = answers.choice('rank');
        }

        return {
            measure: measure?.name ?? null,
            operation,
            group: groups.get(group ?? '')?.name ?? null,
            filterColumns: filterable.filter((_, i) => (answers.noul(`f${i}`) ?? 0) >= 0.5).map((c) => c.name),
            dropBlank: blanks.filter((_, i) => (answers.noul(`b${i}`) ?? 0) >= 0.5).map((c) => c.name),
        };
    }

    /** The filters, and the value columns where no offered value was chosen; null when Jev did not answer. */
    private async values(table: Table, question: string, columns: readonly string[]): Promise<[Record<string, Filter>, string[]] | null> {
        const asks = new Map<string, Ask>();

        for (const name of columns) {
            const found = askFor(columnNamed(table, name)!, question);

            if (found !== null) {
                asks.set(name, found);
            }
        }

        if (asks.size === 0) {
            return [ordered<Filter>([]), []];
        }

        const names = [...asks.keys()];
        const options = ordered<unknown>([]);
        const questions: Questions = {};

        names.forEach((name, c) => {
            const found = asks.get(name)!;
            options[name] = Array.isArray(found.options) || Object.keys(found.options).length > 0 ? found.options : [];
            askValues(questions, `c${c}`, name, found);
        });

        const answers = await send(this.jev, request({
            [QUESTION]: question,
            'the table': { title: table.title, columns: table.columns.map((column) => column.name) },
            'the values to choose from': options,
        }, questions));

        if (answers === null) {
            return null;
        }

        const filters = ordered<Filter>([]);
        const unmatched: string[] = [];

        names.forEach((name, c) => {
            const ask = asks.get(name)!;
            const found = filterOf(answers, `c${c}`, ask);

            if (found !== null) {
                filters[name] = found;
            } else if (ask.kind === 'values') {
                unmatched.push(name);
            }
        });

        return [filters, unmatched];
    }
}

/** The questions' numbers: `zero`, then each written number with million, billion, thousand, bn, m or k expanded. */
export function numbersIn(question: string): [number, string][] {
    const out: [number, string][] = /\bzero\b/i.test(question) ? [[0, 'zero']] : [];
    const pattern = new RegExp(`(?:[£$€]${WHITESPACE}?)?(${DIGIT}{1,3}(?:,${DIGIT}{3})+(?:\\.${DIGIT}+)?|${DIGIT}+(?:\\.${DIGIT}+)?)${WHITESPACE}*(million|billion|thousand|bn|m|k)?${BOUNDARY}`, 'giu');

    for (const match of question.matchAll(pattern)) {
        out.push([phpFloat(match[1]!.replaceAll(',', '')) * scaleOf(match[2] ?? ''), trim(match[0])]);
    }

    const words = new RegExp(`${BOUNDARY}(${Object.keys(NUMBER_WORDS).join('|')})${WHITESPACE}+(hundred|thousand|million|billion)${BOUNDARY}`, 'giu');

    for (const match of question.matchAll(words)) {
        out.push([NUMBER_WORDS[match[1]!.toLowerCase()]! * scaleOf(match[2]!), match[0]]);
    }

    return out;
}

function scaleOf(word: string): number {
    switch (word.toLowerCase()) {
        case 'million':
        case 'm':
            return 1e6;
        case 'billion':
        case 'bn':
            return 1e9;
        case 'thousand':
        case 'k':
            return 1e3;
        case 'hundred':
            return 1e2;
        default:
            return 1;
    }
}

/**
 * Whether a ranked column becomes the grouping: for totals, averages, counts and changes, and
 * for a highest or lowest with no measure (most records); a highest or lowest of a measure
 * keeps its single record.
 */
function ranks(operation: string, measure: Column | null): boolean {
    return ['sum', 'average', 'count', 'change', 'percent'].includes(operation) || (['max', 'min'].includes(operation) && measure === null);
}

/** How fully the question names each value filter: of its chosen values, the highest share of a value's words that match a question word. */
export function named(question: string, filters: Readonly<Record<string, Filter>>): Record<string, number> {
    const words = wordsOf(question);
    const scores: Record<string, number> = {};

    for (const [column, filter] of Object.entries(filters)) {
        if (filter[0] !== 'in') {
            continue;
        }
        scores[column] = 0;
        for (const value of filter[1]) {
            const own = wordsOf(value);

            if (own.length > 0) {
                scores[column] = Math.max(scores[column]!, own.filter((word) => overlap([word], words) > 0).length / own.length);
            }
        }
    }

    return scores;
}

/** PCRE's `\d` and `\b` with the `u` flag, which match Unicode digits and word characters. */
const DIGIT = '\\p{Nd}';

const WORD = '[\\p{L}\\p{N}_]';

const BOUNDARY = `(?:(?<=${WORD})(?!${WORD})|(?<!${WORD})(?=${WORD}))`;

function request(state: Record<string, unknown>, questions: Questions): SystemOneRequest {
    return { state: state as SystemOneRequest['state'], questions };
}

function askFor(column: Column, question: string): Ask | null {
    if (column.role === 'period') {
        return { kind: 'range', options: [...(column.values ?? [])] };
    }

    if (column.role === 'measure') {
        const numbers = numbersIn(question);
        const options: Record<string, string> = {};

        numbers.forEach(([value, written], i) => {
            options[`n${i}`] = `${written} (= ${formatNumber(value)})`;
        });

        return numbers.length === 0 ? null : { kind: 'threshold', options, numbers };
    }

    const values = column.role === 'category' ? column.values ?? [] : shortlist(question, column.values ?? []);
    const options: Record<string, string> = {};

    values.forEach((value, i) => {
        options[`v${i}`] = value;
    });

    return { kind: 'values', options };
}

function askValues(questions: Questions, key: string, name: string, found: Ask): void {
    if (found.kind === 'range') {
        const values: ChoiceCriteria = ordered(found.options.map((value) => [value, null]));
        questions[`${key}_kind`] = choice(`How much of \`${name}\` does the question cover?`, {
            one: `a single \`${name}\``,
            span: `a span of \`${name}\` values, from one to another`,
            all: `every \`${name}\`, no limit`,
        });
        questions[`${key}_one`] = choice(`If the question covers a single \`${name}\`, which one?`, values);
        questions[`${key}_from`] = choice(`If the question covers a span of \`${name}\`, where does the span start?`, values);
        questions[`${key}_to`] = choice(`If the question covers a span of \`${name}\`, where does the span end?`, values);

        return;
    }

    if (found.kind === 'threshold') {
        questions[`${key}_cmp`] = choice(`What kind of limit does the question put on \`${name}\`?`, {
            above: 'more than',
            atleast: 'at least',
            below: 'less than',
            atmost: 'at most',
            none: 'no threshold',
        });
        questions[`${key}_num`] = choice(`Which number in the question is the limit on \`${name}\`?`, { ...found.options });

        return;
    }

    for (const [label, value] of Object.entries(found.options)) {
        questions[`${key}_${label}`] = noul(`Is \`${value}\` one of the \`${name}\` values the question is about?`, {
            true: `the question names \`${value}\`, or the same thing in other words or as an abbreviation`,
            false: `the question is about some other \`${name}\`, or does not name one`,
        });
    }
}

function filterOf(answers: Answers, key: string, found: Ask): Filter | null {
    if (found.kind === 'range') {
        const kind = answers.choice(`${key}_kind`) ?? 'all';
        const one = answers.choice(`${key}_one`);
        const from = answers.choice(`${key}_from`);
        const to = answers.choice(`${key}_to`);

        if (kind === 'one' && one !== null) {
            return ['range', one, one];
        }

        return kind === 'span' && (from !== null || to !== null) ? ['range', from, to] : null;
    }

    if (found.kind === 'threshold') {
        const comparison = answers.choice(`${key}_cmp`) ?? 'none';
        const picked = answers.choice(`${key}_num`);
        const number = found.numbers[leadingInt((picked ?? '').slice(1))];

        return comparison !== 'none' && number !== undefined && picked !== null ? ['threshold', comparison, number[0]] : null;
    }

    const chosen = Object.entries(found.options).filter(([label]) => (answers.noul(`${key}_${label}`) ?? 0) >= 0.5).map(([, value]) => value);

    return chosen.length === 0 ? null : ['in', chosen];
}

/** Each label and id column gains the values that share a word with the question, up to five, most shared words first. */
function withHints(card: Card, table: Table, question: string): Card {
    const words = wordsOf(question);
    const columns = ordered(Object.entries(card.columns));

    for (const column of table.columns) {
        if (column.role !== 'label' && column.role !== 'id') {
            continue;
        }

        const hits = (column.values ?? [])
            .map((value) => [value, overlap(words, wordsOf(value))] as const)
            .filter(([, shared]) => shared > 0)
            .sort(([, a], [, b]) => b - a);

        if (hits.length > 0) {
            columns[column.name] += `; values sharing a word with the question: ${hits.slice(0, 5).map(([value]) => Array.from(value).slice(0, 60).join('')).join('; ')}`;
        }
    }

    return { ...card, columns };
}

/**
 * The values of a large column most worth offering. Each question word a value shares scores
 * 1 / the number of the column's values sharing that word, so a rare name ("Blackpool")
 * outranks a word half the column has ("council"); highest score first, column order among
 * equals.
 */
export function shortlist(question: string, values: readonly string[]): string[] {
    const valueWords = values.map((value) => wordsOf(value));
    const scores = values.map(() => 0);

    for (const word of wordsOf(question)) {
        const matched = valueWords.flatMap((candidate, v) => (overlap([word], candidate) > 0 ? [v] : []));

        for (const v of matched) {
            scores[v]! += 1 / matched.length;
        }
    }

    return scores.map((score, v) => [v, score] as const).sort(([, a], [, b]) => b - a).slice(0, SHORTLIST).map(([v]) => values[v]!);
}

/** Two words match when equal, or when both have four characters or more and one starts with the other. */
function overlap(words: readonly string[], candidate: readonly string[]): number {
    return words.filter((word) => candidate.some((other) => word === other
        || (length(word) >= 4 && length(other) >= 4 && (other.startsWith(word) || word.startsWith(other))))).length;
}

function wordsOf(text: string): string[] {
    return (text.toLowerCase().match(/[\p{L}\p{N}]+/gu) ?? []).filter((word) => length(word) >= 3 && !STOP_WORDS.has(word));
}

/** A card as sent, in its own key order. */
function wire(card: Card): Record<string, unknown> {
    return ordered<unknown>(Object.entries(card));
}

function labelled(columns: readonly Column[], prefix: string): Map<string, Column> {
    return new Map(columns.map((column, i) => [`${prefix}${i + 1}`, column]));
}

/** Up to twelve labels of the rows with no value in `name`, from the first label column or complete category column. */
function blankExamples(table: Table, name: string): string[] {
    const label = table.columns.find((c) => c.role === 'label' || (c.role === 'category' && c.blank === 0))?.name ?? null;
    const seen = new Set<string>();

    for (const row of table.rows) {
        if (label !== null && (row.values[name] ?? null) === null) {
            seen.add(numberKey(row.values[label] ?? null));
        }
        if (seen.size >= 12) {
            break;
        }
    }

    return [...seen];
}

function leadingInt(text: string): number {
    const match = /^[ \t\n\r\v\f]*([+-]?\d+)/.exec(text);

    return match === null ? 0 : Number(match[1]);
}
