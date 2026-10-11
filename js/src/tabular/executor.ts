import { byteCompare, isNumeric, phpCompare, phpFloat, phpString } from '../support/php.js';
import { cardText } from './card.js';
import { formatNumber, numberKey, type Value } from './number.js';
import { ordered } from '../support/ordered.js';
import type { Filter, Plan, Relaxed } from './planner.js';
import { columnNamed, type Row, type Table } from './tables.js';

const MAX_RECORDS = 60;

const SAMPLE_RECORDS = 40;

const CHANGES = ['change', 'percent'];

const MAX_GROUPS = 60;

export interface Result {
    text: string;
    /** `sheet!row` of each record shown. */
    sources: string[];
}

interface Aggregate {
    value: number | null;
    records: number;
    blank: number;
    row: Row | null;
}

type Constraint = ['filter' | 'blank', string];

/**
 * Runs a plan over a table and writes the context an LLM reads: the source, the plan in words,
 * the computed result, the records behind it with their sheet and row, and the notes they cite.
 * The arithmetic happens here, never in a model.
 */
export function runPlan(table: Table, given: Plan): Result {
    const lines = [`Source: ${table.title} [${table.id}], ${table.rows.length} records. Columns: ${columnList(table)}`];
    let plan = given;
    let rows = select(table, plan);
    const relaxed = plan.relaxed ?? null;
    const lowest = plan.operation === 'min';
    const [measure, planned] = plan.measure === null && ['sum', 'average', 'max', 'min', ...CHANGES].includes(plan.operation) ? [null, 'count'] : [plan.measure, plan.operation];
    let operation = planned;

    if (rows.length === 0 && relaxed !== null) {
        lines.push(`Query: ${describe(shaped(plan, operation), measure)}`);
        lines.push('Matched 0 records.');
        lines.push(`No record matches every filter, so this one is left out: ${constraintText(plan, relaxed.drop, relaxed.column)}.`);
        plan = without(plan, relaxed.drop, relaxed.column);
        rows = select(table, plan);
    }

    const span = CHANGES.includes(operation) ? spanOf(table, plan) : null;
    const noSpan = CHANGES.includes(operation) && span === null;

    if (noSpan) {
        operation = 'sum';
    }
    plan = shaped(plan, operation);
    const unit = measure !== null ? phpString(columnNamed(table, measure)?.unit ?? '') : '';

    lines.push(`Query: ${describe(plan, measure)}`);
    if (noSpan) {
        lines.push('A change needs a span of a period column with a start and an end, and none was chosen, so this is the sum.');
    }
    for (const column of plan.unmatched ?? []) {
        lines.push(`No ${column} value matched the question, so the records are not filtered by ${column}.`);
    }
    lines.push(`Matched ${rows.length} records.`);
    if (rows.length === 0 && constraintsOf(plan).length > 0) {
        lines.push(unmatchedFilters(table, plan));
    }
    if (measure !== null && operation !== 'count' && rows.length > 0 && !rows.some((row) => typeof row.values[measure] === 'number')) {
        lines.push(noData(table, plan, measure, rows.length));
    }

    let shown = rows;

    if (span !== null && measure !== null) {
        lines.push(...changes(rows, plan.group, operation, measure, unit, span));
        shown = plan.group === null ? rows.filter((row) => [span[1], span[2]].includes(numberKey(row.values[span[0]] ?? null))) : [];
    } else if (plan.group !== null && operation !== 'list') {
        lines.push(...grouped(rows, plan.group, operation, measure, unit, lowest));
        shown = [];
    } else if (operation !== 'list') {
        const result = aggregate(operation, rows, measure);
        lines.push(`Result: ${operation}${measure !== null ? ` of ${measure}` : ''} = ${amount(result.value, unit)} (over ${result.records} records${result.blank > 0 ? `, ${result.blank} blank skipped` : ''})`);

        if ((operation === 'max' || operation === 'min') && rows.length <= MAX_RECORDS) {
            shown = sortBy(rows, measure);
        } else if (result.row !== null) {
            shown = [result.row];
        } else {
            shown = sortBy(rows, measure).slice(0, 5);
        }
    } else if (measure !== null && rows.length > MAX_RECORDS) {
        shown = sortBy(rows, measure);
    }

    const limit = shown.length <= MAX_RECORDS ? shown.length : SAMPLE_RECORDS;

    if (shown.length > 0) {
        lines.push(records(table, shown.slice(0, limit), shown.length));
    }
    if (shown.length > limit) {
        lines.push(rest(shown.slice(limit), measure, unit));
    }
    lines.push(...notesFor(table, shown, plan));

    return { text: lines.join('\n'), sources: [...new Set(shown.slice(0, limit).map((row) => row.source))] };
}

/** The records a plan's filters and blank-row columns select. */
export function select(table: Table, plan: Pick<Plan, 'filters' | 'drop_blank'>): Row[] {
    return table.rows.filter((row) => matches(row.values, plan));
}

/**
 * When a plan with two or more constraints selects no record, the one to leave out: of those
 * whose removal lets some record through, the one that lets the fewest through, the first in
 * order among equals. Null when the plan selects records, or no single removal helps.
 */
export function relax(table: Table, plan: Plan, scores: Readonly<Record<string, number>> = {}): Relaxed | null {
    const constraints = constraintsOf(plan);

    if (constraints.length < 2 || select(table, plan).length > 0) {
        return null;
    }

    let best: Relaxed | null = null;
    let bestScore = 0;

    for (const [drop, column] of constraints) {
        const matched = select(table, without(plan, drop, column)).length;
        const score = drop === 'filter' ? scores[column] ?? 1 : 1;

        if (matched > 0 && (best === null || score < bestScore || (score === bestScore && matched < best.matched))) {
            best = { drop, column, matched };
            bestScore = score;
        }
    }

    return best;
}

/** The plan's constraints in order: each filter, then each blank-row column. */
function constraintsOf(plan: Pick<Plan, 'filters' | 'drop_blank'>): Constraint[] {
    return [...Object.keys(plan.filters).map((column): Constraint => ['filter', column]), ...plan.drop_blank.map((column): Constraint => ['blank', column])];
}

function without<T extends Pick<Plan, 'filters' | 'drop_blank'>>(plan: T, drop: 'filter' | 'blank', column: string): T {
    if (drop === 'filter') {
        return { ...plan, filters: ordered(Object.entries(plan.filters).filter(([name]) => name !== column)) };
    }

    return { ...plan, drop_blank: plan.drop_blank.filter((name) => name !== column) };
}

function constraintText(plan: Pick<Plan, 'filters'>, drop: 'filter' | 'blank', column: string): string {
    return drop === 'filter' ? filterText(column, plan.filters[column]!) : `${column} not blank`;
}

/** How many records each constraint would let through on its own, so a reader can say that none match instead of abstaining. */
function unmatchedFilters(table: Table, plan: Plan): string {
    const parts = constraintsOf(plan).map(([drop, column]) => {
        const alone = { filters: drop === 'filter' ? ordered([[column, plan.filters[column]!] as const]) : {}, drop_blank: drop === 'blank' ? [column] : [] };

        return `${constraintText(plan, drop, column)} (${select(table, alone).length} records)`;
    });

    return `No record matches the filters. Each on its own matches: ${parts.join('; ')}.`;
}

/**
 * Every matched record is blank in the measure: says so and, for each period the plan filters
 * on, the periods that hold values, so a year the source left empty reads as missing instead
 * of zero.
 */
function noData(table: Table, plan: Plan, measure: string, matched: number): string {
    let line = `${measure} is blank in all ${matched} matched records.`;

    for (const [column, filter] of Object.entries(plan.filters)) {
        if (filter[0] !== 'range' || columnNamed(table, column)?.role !== 'period') {
            continue;
        }

        const held = new Set<string>();

        for (const row of table.rows) {
            if (typeof row.values[measure] === 'number' && (row.values[column] ?? null) !== null) {
                held.add(numberKey(row.values[column]));
            }
        }

        const periods = (columnNamed(table, column)?.values ?? []).map(String).filter((value) => held.has(value));
        line += periods.length === 0
            ? ` The table holds no ${measure} value for any ${column}.`
            : ` The table holds ${measure} values for ${column} from ${periods[0]} to ${periods.at(-1)} only.`;
    }

    return line;
}

function amount(value: number | null, unit: string): string {
    return value === null ? 'no data' : formatNumber(value) + (unit !== '' ? ` ${unit}` : '');
}

/** The records past the ones written: how many, and the range and sum of their measure. */
function rest(rows: readonly Row[], measure: string | null, unit: string): string {
    const values = measure === null ? [] : rows.map((row) => row.values[measure] ?? null).filter((value): value is number => typeof value === 'number');

    if (values.length === 0) {
        return `Not shown: ${rows.length} more records.`;
    }

    const suffix = unit !== '' ? ` ${unit}` : '';
    const sum = values.reduce((total, value) => total + value, 0);

    return `Not shown: ${rows.length} more records, ${measure} from ${formatNumber(Math.min(...values))} to ${formatNumber(Math.max(...values))}${suffix}, sum ${formatNumber(sum)}${suffix}.`;
}

function shaped(plan: Plan, operation: string): Plan {
    return { ...plan, operation, group: plan.group ?? implicitGroup(plan, operation) };
}

/** For a question about what the data means: the card, every record when there are 60 or fewer, and the notes. */
export function meaningOf(table: Table): Result {
    const lines = [`Table: ${table.title} [${table.id}]`, cardText(table, 8)];
    const rows = table.rows.length <= 60 ? table.rows : [];

    if (rows.length > 0) {
        lines.push(records(table, rows, rows.length));
    }

    const refs = new Map<number, string[]>();
    const refer = (n: number, where: string): void => {
        refs.set(n, [...(refs.get(n) ?? []), where]);
    };

    for (const [label, numbers] of Object.entries(table.row_notes)) {
        for (const n of numbers) {
            refer(n, label);
        }
    }
    for (const [column, numbers] of Object.entries(table.column_notes)) {
        for (const n of numbers) {
            refer(n, `column ${column}`);
        }
    }

    for (const [n, where] of [...refs].sort(([a], [b]) => a - b)) {
        const note = noteText(table, n);

        if (note !== null) {
            lines.push(`Note ${n} (${[...new Set(where)].join(', ')}): ${note}`);
        }
    }

    return { text: lines.join('\n'), sources: rows.map((row) => row.source) };
}

function matches(values: Readonly<Record<string, Value>>, plan: Pick<Plan, 'filters' | 'drop_blank'>): boolean {
    if (plan.drop_blank.some((column) => (values[column] ?? null) === null)) {
        return false;
    }

    return Object.entries(plan.filters).every(([column, filter]) => {
        const value = values[column] ?? null;

        return value !== null && passes(value, filter);
    });
}

function passes(value: number | string, filter: Filter): boolean {
    switch (filter[0]) {
        case 'in':
            return filter[1].includes(numberKey(value));
        case 'range': {
            const [from, to] = ends(filter);

            return (from === null || compare(value, from) >= 0) && (to === null || compare(value, to) <= 0);
        }
        case 'threshold':
            if (typeof value !== 'number') {
                return false;
            }
            switch (filter[1]) {
                case 'above':
                    return value > filter[2];
                case 'atleast':
                    return value >= filter[2];
                case 'below':
                    return value < filter[2];
                default:
                    return value <= filter[2];
            }
        default:
            return true;
    }
}

/** A range's ends, swapped when the start is after the end. */
function ends(filter: ['range', string | null, string | null]): [string | null, string | null] {
    const [, from, to] = filter;

    return from !== null && to !== null && compare(from, to) > 0 ? [to, from] : [from, to];
}

/** A sum, average or count over several named values of one column, with no grouping chosen, is shown per value. */
function implicitGroup(plan: Plan, operation: string): string | null {
    if (!['sum', 'average', 'count', ...CHANGES].includes(operation)) {
        return null;
    }

    return Object.entries(plan.filters).find(([, filter]) => filter[0] === 'in' && filter[1].length > 1)?.[0] ?? null;
}

function grouped(rows: readonly Row[], group: string, operation: string, measure: string | null, unit: string, lowest: boolean): string[] {
    const groups = new Map<string, Row[]>();

    for (const row of rows) {
        const value = row.values[group] ?? null;
        const key = value === null ? '(blank)' : numberKey(value);
        groups.set(key, [...(groups.get(key) ?? []), row]);
    }

    const results = [...groups].map(([name, members]) => [name, aggregate(operation, members, measure)] as const)
        .sort(([, a], [, b]) => rank(a.value, b.value, lowest));
    const lines = [`Result: ${operation}${measure !== null ? ` of ${measure}` : ''} by ${group} (${results.length} groups):`];

    for (const [name, result] of results.slice(0, MAX_GROUPS)) {
        lines.push(`  ${name}: ${amount(result.value, unit)} (${result.records} records${result.blank > 0 ? `, ${result.blank} blank` : ''})`);
    }

    return lines;
}

/** Groups highest first, or lowest first for min; no value last either way. */
function rank(a: number | null, b: number | null, lowest: boolean): number {
    if (a === null || b === null) {
        return Number(a === null) - Number(b === null);
    }

    return lowest ? Math.sign(a - b) : Math.sign(b - a);
}

/** The span a change is measured over: the first range filter on a period column with both ends, as [column, start key, end key]. */
function spanOf(table: Table, plan: Plan): [string, string, string] | null {
    for (const [column, filter] of Object.entries(plan.filters)) {
        const [from, to] = filter[0] === 'range' ? ends(filter) : [null, null];

        if (from !== null && to !== null && columnNamed(table, column)?.role === 'period') {
            return [column, numberKey(from), numberKey(to)];
        }
    }

    return null;
}

/**
 * The change in the measure from the span's start to its end, overall or per group, highest
 * first. Each end is the sum of the measure's numbers in the records at that period.
 */
function changes(rows: readonly Row[], group: string | null, operation: string, measure: string, unit: string, span: [string, string, string]): string[] {
    const [column, from, to] = span;
    const groups = new Map<string, Row[]>();

    for (const row of rows) {
        const value = group === null ? '' : row.values[group] ?? null;
        const key = value === null ? '(blank)' : numberKey(value);
        groups.set(key, [...(groups.get(key) ?? []), row]);
    }

    const results = [...groups].map(([name, members]) => {
        const start = sumAt(members, measure, column, from);
        const end = sumAt(members, measure, column, to);
        const change = start !== null && end !== null ? end - start : null;
        const value = operation === 'change' ? change : change !== null && start !== 0 ? change / start! * 100 : null;

        return [name, { value, start, end }] as const;
    }).sort(([, a], [, b]) => phpCompare(b.value ?? -Infinity, a.value ?? -Infinity));

    const what = `${operation === 'change' ? 'change' : 'percent change'} of ${measure} from ${from} to ${to}`;
    const figure = (r: { value: number | null; start: number | null; end: number | null }): string =>
        `${amount(r.value, operation === 'change' ? unit : '%')} (${from}: ${amount(r.start, unit)}; ${to}: ${amount(r.end, unit)})`;

    if (group === null) {
        return [`Result: ${what} = ${figure(results[0]?.[1] ?? { value: null, start: null, end: null })}`];
    }

    return [`Result: ${what} by ${group} (${results.length} groups):`, ...results.slice(0, MAX_GROUPS).map(([name, result]) => `  ${name}: ${figure(result)}`)];
}

function sumAt(rows: readonly Row[], measure: string, column: string, period: string): number | null {
    const values = rows.filter((row) => numberKey(row.values[column] ?? null) === period && typeof row.values[measure] === 'number').map((row) => row.values[measure] as number);

    return values.length === 0 ? null : values.reduce((total, value) => total + value, 0);
}

/** A count is of distinct source rows, so a melted table counts countries, not country-years. */
function aggregate(operation: string, rows: readonly Row[], measure: string | null): Aggregate {
    if (operation === 'count' || measure === null) {
        return { value: new Set(rows.map((row) => row.source)).size, records: rows.length, blank: 0, row: null };
    }

    const values: number[] = [];
    let best: Row | null = null;

    for (const row of rows) {
        const value = row.values[measure] ?? null;

        if (typeof value !== 'number') {
            continue;
        }
        values.push(value);

        const current = best === null ? null : best.values[measure] as number;

        if (current === null || (operation === 'max' && value > current) || (operation === 'min' && value < current)) {
            best = row;
        }
    }

    const sum = values.reduce((total, value) => total + value, 0);
    const extremes: Record<string, number | null> = {
        sum: values.length === 0 ? null : sum,
        average: values.length === 0 ? null : sum / values.length,
        max: values.length === 0 ? null : Math.max(...values),
        min: values.length === 0 ? null : Math.min(...values),
    };

    return {
        value: extremes[operation] ?? null,
        records: values.length,
        blank: rows.length - values.length,
        row: operation === 'max' || operation === 'min' ? best : null,
    };
}

function compare(a: number | string, b: string): number {
    if (isNumeric(a) && isNumeric(b)) {
        const x = typeof a === 'number' ? a : phpFloat(a);
        const y = phpFloat(b);

        return x < y ? -1 : x > y ? 1 : 0;
    }

    return byteCompare(numberKey(a), b);
}

/** Highest first by the measure, blanks last, ties in record order. */
function sortBy(rows: readonly Row[], measure: string | null): Row[] {
    if (measure === null) {
        return [...rows];
    }

    const score = (row: Row): number | string => row.values[measure] ?? -Infinity;

    return [...rows].sort((a, b) => phpCompare(score(b), score(a)));
}

function describe(plan: Plan, measure: string | null): string {
    const parts = [(plan.operation === 'percent' ? 'percent change' : plan.operation) + (measure !== null ? ` of ${measure}` : '')];

    for (const [column, filter] of Object.entries(plan.filters)) {
        parts.push(filterText(column, filter));
    }
    for (const column of plan.drop_blank) {
        parts.push(`${column} not blank`);
    }
    if (plan.group !== null) {
        parts.push(`grouped by ${plan.group}`);
    }

    return parts.join('; ');
}

function filterText(column: string, filter: Filter): string {
    if (filter[0] === 'in') {
        return `${column} is ${filter[1].map((value) => `"${value}"`).join(' or ')}`;
    }
    if (filter[0] === 'range') {
        const [from, to] = ends(filter);

        return `${column} from ${from ?? 'start'} to ${to ?? 'end'}`;
    }

    return `${column} ${filter[1]} ${formatNumber(filter[2])}`;
}

function columnList(table: Table): string {
    return table.columns
        .filter((column) => column.role !== 'constant')
        .map((column) => column.name + (column.unit !== null && !column.name.includes(`(${column.unit})`) ? ` (${column.unit})` : ''))
        .join(', ');
}

function records(table: Table, rows: readonly Row[], total: number): string {
    const columns = table.columns.filter((column) => column.role !== 'constant').map((column) => column.name);
    const lines = [`Records (${rows.length} of ${total}):`, `source | ${columns.join(' | ')}`];

    for (const row of rows) {
        lines.push(`${row.source} | ${columns.map((name) => ((row.values[name] ?? null) === null ? '' : numberKey(row.values[name]))).join(' | ')}`);
    }

    return lines.join('\n');
}

function notesFor(table: Table, rows: readonly Row[], plan: Plan): string[] {
    const refs = new Set<number>();
    const rowNotes = (label: string): readonly number[] => (Object.hasOwn(table.row_notes, label) ? table.row_notes[label]! : []);

    for (const row of rows) {
        for (const value of Object.values(row.values)) {
            rowNotes(numberKey(value)).forEach((n) => refs.add(n));
        }
    }
    for (const [column, filter] of Object.entries(plan.filters)) {
        (Object.hasOwn(table.column_notes, column) ? table.column_notes[column]! : []).forEach((n) => refs.add(n));

        if (filter[0] === 'in') {
            filter[1].forEach((value) => rowNotes(value).forEach((n) => refs.add(n)));
        }
    }

    return [...refs].sort((a, b) => a - b).flatMap((n) => {
        const note = noteText(table, n);

        return note === null ? [] : [`Note ${n}: ${note}`];
    });
}

/** A note's text; `notes` is an object keyed by number, or a list when PHP wrote one from keys counting up from 0. */
function noteText(table: Table, n: number): string | null {
    const notes: unknown = table.notes;
    const note = Array.isArray(notes) ? notes[n] : Object.hasOwn(notes as object, String(n)) ? (notes as Record<string, unknown>)[String(n)] : undefined;

    return note === undefined || note === null ? null : phpString(note);
}
