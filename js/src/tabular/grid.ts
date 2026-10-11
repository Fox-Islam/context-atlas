import { readFileSync } from 'node:fs';
import { unzipSync } from 'fflate';
import { ContextAtlasError } from '../errors.js';
import { utf8OrWindows1252 } from '../support/encoding.js';
import { phpTrim } from '../support/php.js';
import { parseXml, type XmlElement } from './xml.js';

/** Cells keyed by column from 0, in the order the file gives them; empty cells are left out. */
export type Cells = Map<number, string>;

/** A sheet's rows keyed by row number from 1, in ascending order. */
export interface Sheet {
    name: string;
    hidden: boolean;
    rows: Map<number, Cells>;
}

const RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

/** The cells of a spreadsheet as the file gives them, sheet by sheet. */
export class Grid {
    constructor(readonly sheets: readonly Sheet[]) {}

    /**
     * CSV text, or its bytes. Bytes that are not valid UTF-8 are read as Windows-1252, the usual
     * encoding of spreadsheets exported on Windows. Quoting follows PHP's `fgetcsv` with no
     * escape character; a row number counts every record, blank ones included.
     */
    static csv(input: string | Uint8Array, name = 'Sheet1'): Grid {
        const text = (typeof input === 'string' ? input : decodeBytes(input)).replace(/^﻿/, '');
        const rows = new Map<number, Cells>();
        let number = 0;

        for (const record of csvRecords(text)) {
            number++;
            const cells: Cells = new Map();

            record.forEach((cell, index) => {
                if (cell !== null && phpTrim(cell) !== '') {
                    cells.set(index, cell);
                }
            });
            if (cells.size > 0) {
                rows.set(number, cells);
            }
        }

        return new Grid([{ name, hidden: false, rows }]);
    }

    /** @throws {ContextAtlasError} when the file is not an XLSX workbook or lacks a part */
    static xlsx(path: string): Grid {
        let files: Record<string, Uint8Array>;

        try {
            files = unzipSync(readFileSync(path));
        } catch {
            throw new ContextAtlasError(`Cannot open ${path} as an XLSX workbook.`);
        }

        return Grid.xlsxParts(files);
    }

    /** The workbook's parts, as unzipped. */
    static xlsxParts(files: Readonly<Record<string, Uint8Array>>): Grid {
        const part = (name: string): XmlElement => {
            const bytes = files[name];

            if (bytes === undefined) {
                throw new ContextAtlasError(`The workbook has no ${name}.`);
            }
            try {
                return parseXml(new TextDecoder().decode(bytes));
            } catch (error) {
                throw new ContextAtlasError(`Cannot read ${name}: ${(error as Error).message}`);
            }
        };

        const strings = files['xl/sharedStrings.xml'] === undefined ? [] : sharedStrings(part('xl/sharedStrings.xml'));
        const targets = new Map<string, string>();

        for (const relation of part('xl/_rels/workbook.xml.rels').all('Relationship')) {
            const target = relation.attribute('Target');
            targets.set(relation.attribute('Id'), target.startsWith('/') ? target.slice(1) : `xl/${target}`);
        }

        const workbook = part('xl/workbook.xml');
        const idAttribute = `${relationshipsPrefix(workbook)}:id`;

        return new Grid((workbook.child('sheets')?.all('sheet') ?? []).map((sheet) => ({
            name: sheet.attribute('name'),
            hidden: ['hidden', 'veryHidden'].includes(sheet.attribute('state')),
            rows: cells(part(targets.get(sheet.attribute(idAttribute)) ?? ''), strings),
        })));
    }
}

function decodeBytes(bytes: Uint8Array): string {
    const start = bytes[0] === 0xef && bytes[1] === 0xbb && bytes[2] === 0xbf ? 3 : 0;

    return utf8OrWindows1252(bytes.subarray(start));
}

/**
 * Records as PHP's `fgetcsv` reads them: lines end at `\n`, with a `\r` before it dropped; a
 * quoted field runs across lines, `""` inside it is a quote, and text after its closing quote
 * is kept; an unquoted field keeps its leading spaces. A blank line is `[null]`.
 */
function* csvRecords(text: string): Generator<(string | null)[]> {
    let i = 0;

    while (i < text.length) {
        const fields: (string | null)[] = [];
        const lineEnd = endOfLine(text, i);

        if (text.slice(i, lineEnd).replace(/\r$/, '') === '') {
            yield [null];
            i = lineEnd + 1;
            continue;
        }

        for (;;) {
            let j = i;

            while (text[j] === ' ' || text[j] === '\t') {
                j++;
            }

            let field = '';

            if (text[j] === '"') {
                j++;
                for (;;) {
                    if (j >= text.length) {
                        break;
                    }
                    if (text[j] === '"') {
                        if (text[j + 1] === '"') {
                            field += '"';
                            j += 2;
                            continue;
                        }
                        j++;
                        break;
                    }
                    field += text[j];
                    j++;
                }
                while (j < text.length && text[j] !== ',' && text[j] !== '\n') {
                    field += text[j];
                    j++;
                }
            } else {
                j = i;
                while (j < text.length && text[j] !== ',' && text[j] !== '\n') {
                    field += text[j];
                    j++;
                }
            }

            if (text[j] === ',') {
                fields.push(field);
                i = j + 1;
                continue;
            }

            fields.push(field.replace(/\r$/, ''));
            i = j + 1;
            break;
        }

        yield fields;
    }
}

function endOfLine(text: string, from: number): number {
    const end = text.indexOf('\n', from);

    return end === -1 ? text.length : end;
}

function sharedStrings(sst: XmlElement): string[] {
    return sst.all('si').map((item) => (item.child('t')?.text ?? '') + item.all('r').map((run) => run.child('t')?.text ?? '').join(''));
}

function relationshipsPrefix(workbook: XmlElement): string {
    for (const [name, value] of workbook.attributes) {
        if (name.startsWith('xmlns:') && value === RELATIONSHIPS) {
            return name.slice(6);
        }
    }

    return 'r';
}

function cells(sheet: XmlElement, strings: readonly string[]): Map<number, Cells> {
    const rows = new Map<number, Cells>();
    let number = 0;

    for (const row of sheet.child('sheetData')?.all('row') ?? []) {
        number = row.attribute('r') !== '' ? phpIntOf(row.attribute('r')) : number + 1;
        let column = -1;

        for (const cell of row.all('c')) {
            [column, number] = cell.attribute('r') !== '' ? reference(cell.attribute('r')) : [column + 1, number];
            const type = cell.attribute('t');
            const value = type === 's'
                ? strings[phpIntOf(cell.child('v')?.text ?? '')] ?? ''
                : type === 'inlineStr' ? cell.child('is')?.child('t')?.text ?? '' : cell.child('v')?.text ?? '';

            if (phpTrim(value) !== '') {
                const cellsOfRow = rows.get(number) ?? new Map<number, string>();
                cellsOfRow.set(column, value);
                rows.set(number, cellsOfRow);
            }
        }
    }

    return new Map([...rows].sort(([a], [b]) => a - b));
}

/** Column from 0 and row from 1; a reference that is not letters then digits reads as A1, as in the PHP package. */
function reference(text: string): [number, number] {
    const match = /^([A-Z]+)(\d+)$/.exec(text);
    let column = 0;

    for (const letter of match?.[1] ?? 'A') {
        column = column * 26 + letter.charCodeAt(0) - 64;
    }

    return [column - 1, Number(match?.[2] ?? 1)];
}

function phpIntOf(text: string): number {
    const match = /^[ \t\n\r\v\f]*([+-]?\d+)/.exec(text);

    return match === null ? 0 : Number(match[1]);
}
