import { Block, BlockType } from '../../block.js';
import { squash } from '../../text.js';
import { dropped, lineText } from './content.js';
import type { Element } from './element.js';

const MAX_SPAN = 100;

interface Cell {
    text: string;
    colspan: number;
    rowspan: number;
    th: boolean;
}

interface Row {
    cells: Cell[];
    head: boolean;
}

export interface TableContent {
    caption: string;
    header: string[];
    rows: string[][];
}

/**
 * A `table` element's caption, header and rows. A cell is repeated across its colspan and
 * carried down its rowspan; tables inside cells are read as text. Null when it has neither a
 * header nor a row.
 */
export function readTable(table: Element, inArticle: boolean): TableContent | null {
    const found = { caption: '' };
    const rows: Row[] = [];
    collectRows(table, inArticle, false, rows, found);
    let caption = found.caption;

    if (caption === '' && isTitleRow(rows)) {
        caption = rows[0]!.cells[0]!.text;
        rows.shift();
    }

    const grid = gridOf(rows);
    let header = grid.length > 0 && grid[0]!.header ? grid[0]!.cells : [];

    if (header.length > 0) {
        grid.shift();
    }
    if (header.join('') === '') {
        header = [];
    }

    const body = grid.filter((row) => row.cells.join('') !== '').map((row) => row.cells);

    return header.length === 0 && body.length === 0 ? null : { caption, header, rows: body };
}

/** The table block: text `Table: <caption>`, then the header and rows with cells joined by ` | `; meta `{caption, header, rows}`. */
export function tableBlock(table: TableContent): Block {
    const lines = table.caption !== '' ? [`Table: ${table.caption}`] : [];

    for (const cells of table.header.length > 0 ? [table.header, ...table.rows] : table.rows) {
        lines.push(cells.join(' | '));
    }

    return new Block(BlockType.Table, lines.join('\n'), null, { caption: table.caption, header: table.header, rows: table.rows });
}

/** A first row, followed by others, of one non-empty cell spanning more than one column and at least as many as any other row's cells span. */
function isTitleRow(rows: readonly Row[]): boolean {
    const first = rows[0]?.cells;

    if (rows.length < 2 || first === undefined || first.length !== 1 || first[0]!.colspan < 2 || first[0]!.text === '') {
        return false;
    }

    return rows.slice(1).every((row) => row.cells.reduce((sum, cell) => sum + cell.colspan, 0) <= first[0]!.colspan);
}

/** The rows of a table, not those of tables inside it, and its first non-empty caption. */
function collectRows(parent: Element, inArticle: boolean, head: boolean, rows: Row[], found: { caption: string }): void {
    for (const child of parent.children) {
        if (typeof child === 'string' || child.name === 'table' || dropped(child, inArticle)) {
            continue;
        }
        if (child.name === 'caption') {
            found.caption = found.caption !== '' ? found.caption : squash(lineText(child, inArticle));
        } else if (child.name === 'tr') {
            rows.push({ cells: cellsOf(child, inArticle), head });
        } else {
            collectRows(child, inArticle, head || child.name === 'thead', rows, found);
        }
    }
}

function cellsOf(parent: Element, inArticle: boolean): Cell[] {
    const cells: Cell[] = [];

    for (const child of parent.children) {
        if (typeof child === 'string' || child.name === 'table' || child.name === 'tr' || dropped(child, inArticle)) {
            continue;
        }
        if (child.name === 'td' || child.name === 'th') {
            cells.push({
                text: squash(lineText(child, inArticle)),
                colspan: span(child.attribute('colspan')),
                rowspan: span(child.attribute('rowspan')),
                th: child.name === 'th',
            });
        } else {
            cells.push(...cellsOf(child, inArticle));
        }
    }

    return cells;
}

function span(value: string | null): number {
    const trimmed = (value ?? '').replace(/^[ \t\n\f\r]+|[ \t\n\f\r]+$/g, '');

    return /^[0-9]{1,9}$/.test(trimmed) ? Math.max(1, Math.min(MAX_SPAN, Number(trimmed))) : 1;
}

/** Rows of cell texts; a row is a header row when it sits in a `thead` or all its cells are `th`. */
function gridOf(rows: readonly Row[]): { cells: string[]; header: boolean }[] {
    const grid: { cells: string[]; header: boolean }[] = [];
    const carry = new Map<number, [string, number]>();

    for (const row of rows) {
        const out: string[] = [];
        let column = 0;

        for (const cell of row.cells) {
            column = carried(carry, out, column);
            for (let k = 0; k < cell.colspan; k++, column++) {
                out.push(cell.text);
                if (cell.rowspan > 1) {
                    carry.set(column, [cell.text, cell.rowspan - 1]);
                }
            }
        }
        carried(carry, out, column);

        const allTh = row.cells.length > 0 && row.cells.every((cell) => cell.th);
        grid.push({ cells: out, header: row.head || allTh });
    }

    return grid;
}

/** Places the cells carried down into `column` and the columns after it, while there are any, and returns the next free column. */
function carried(carry: Map<number, [string, number]>, out: string[], from: number): number {
    let column = from;

    for (let entry = carry.get(column); entry !== undefined; entry = carry.get(column)) {
        out.push(entry[0]);
        entry[1]--;
        if (entry[1] === 0) {
            carry.delete(column);
        }
        column++;
    }

    return column;
}
