import { Document } from '../document/document.js';
import { Unit } from '../document/unit.js';
import { buildLexical } from '../store/lexical.js';
import { phpTrim } from '../support/php.js';
import { cardText } from './card.js';
import type { Grid, Sheet } from './grid.js';
import { join, normalise, split, type RawTable, type Table } from './tables.js';

export const TABULAR = 'tabular';

/**
 * Ingests a spreadsheet with no Jev request. Each table becomes a unit whose text is its card,
 * for routing; the cleaned rows travel in the document's `data.tables` for the planner to query.
 * A sheet with no table becomes a passage.
 */
export function ingestGrid(grid: Grid, id: string, title: string): Document {
    const visible = grid.sheets.filter((sheet) => !sheet.hidden);
    const splits = visible.map((sheet) => split(sheet.rows));
    const notes = notesOf(visible, splits);
    let tables: Table[] = [];
    const units: Unit[] = [];

    visible.forEach((sheet, s) => {
        const { text, tables: raws } = splits[s]!;
        const many = raws.length > 1;

        raws.forEach((raw, i) => {
            const name = raw.title ?? `${visible.length > 1 ? `${title}: ${sheet.name}` : title}${many ? ` (${i + 1})` : ''}`;
            tables.push(normalise(`t${tables.length + 1}`, sheet.name, name, raw, notes));
        });
        if (raws.length === 0 && text.length > 0) {
            units.push(new Unit(`s${s + 1}`, 'passage', text.join('\n'), [sheet.name]));
        }
    });

    tables = shareNotes(join(tables));
    const extra: Record<string, string> = {};

    tables.forEach((table, t) => {
        units.push(new Unit(table.id, 'table', cardText(table, 6), [table.sheet], { table: t }));
        extra[table.id] = searchValues(table);
    });

    return new Document(id, TABULAR, units, [], buildLexical(units, extra), { profile: TABULAR, score: null, by: 'file type' }, { tables });
}

/** Notes from a sheet named "Note" or "Notes": rows whose first cell is "Note N", the header included, since a notes sheet often has none. */
function notesOf(sheets: readonly Sheet[], splits: readonly { tables: RawTable[] }[]): Record<string, string> {
    const notes: Record<string, string> = {};

    sheets.forEach((sheet, s) => {
        if (!/^notes?$/i.test(phpTrim(sheet.name))) {
            return;
        }
        for (const table of splits[s]!.tables) {
            for (const cells of [table.header, ...table.rows.map((row) => row.cells)]) {
                const match = /^Note (\d+)$/i.exec(phpTrim(cells[0] ?? ''));

                if (match !== null) {
                    notes[String(Number(match[1]))] = cells[1] ?? '';
                }
            }
        }
    });

    return notes;
}

/** A label marked with notes in one table carries them wherever a listed value equals it, case-insensitively. */
function shareNotes(tables: readonly Table[]): Table[] {
    const byLabel = new Map<string, number[]>();

    for (const table of tables) {
        for (const [label, refs] of Object.entries(table.row_notes)) {
            byLabel.set(label.toLowerCase(), refs);
        }
    }

    return tables.map((table) => {
        const rowNotes = { ...table.row_notes };

        for (const column of table.columns) {
            for (const value of column.values ?? []) {
                const refs = byLabel.get(value.toLowerCase());

                if (refs !== undefined) {
                    rowNotes[value] = refs;
                }
            }
        }

        return { ...table, row_notes: rowNotes };
    });
}

/** Every value of a table's label, category, id and alias columns, so exact-term search finds a table by a name its card does not show. */
function searchValues(table: Table): string {
    return table.columns
        .filter((column) => ['label', 'category', 'id', 'alias'].includes(column.role))
        .flatMap((column) => column.values ?? [])
        .join(' ');
}
