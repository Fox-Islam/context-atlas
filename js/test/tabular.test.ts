import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';
import { strToU8, zipSync } from 'fflate';
import { Grid, Processor, ContextAtlasError } from '../src/index.js';
import { formatNumber, numberKey } from '../src/tabular/number.js';

const workbook = fileURLToPath(new URL('../../../spec/fixtures/workbook.xlsx', import.meta.url));

function rows(grid: Grid): [string, boolean, [number, [number, string][]][]][] {
    return grid.sheets.map((sheet) => [sheet.name, sheet.hidden, [...sheet.rows].map(([number, cells]) => [number, [...cells]])]);
}

describe('XLSX reader', () => {
    it('reads sheets in workbook order with shared, rich-text and inline strings', () => {
        assert.deepEqual(rows(Grid.xlsx(workbook)), [
            ['Sales', false, [
                [1, [[0, 'Table 1 Sales by region (£)']]],
                [3, [[1, 'Region'], [2, 'Q1'], [3, 'Q2']]],
                [4, [[1, 'North'], [2, '1200.5'], [3, '1300']]],
                [5, [[1, 'South'], [2, '800'], [3, '[x]']]],
            ]],
            ['Notes', false, [
                [1, [[0, 'Note 1'], [1, 'Figures are provisional.']]],
                [2, [[0, 'Note 2'], [1, 'Q2 is an estimate.']]],
            ]],
            ['Secret', true, [[1, [[0, 'Hidden'], [1, 'x']]]]],
        ]);
    });

    it('decodes entities and CDATA, skips blank cells and sorts rows by number', () => {
        const directory = mkdtempSync(join(tmpdir(), 'stp-xlsx-'));
        const path = join(directory, 'book.xlsx');

        try {
            writeFileSync(path, zipSync({
                'xl/workbook.xml': strToU8('<?xml version="1.0"?><workbook xmlns:rel="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="A &amp; B" rel:id="r1"/></sheets></workbook>'),
                'xl/_rels/workbook.xml.rels': strToU8('<Relationships><Relationship Id="r1" Target="/xl/s.xml"/></Relationships>'),
                'xl/s.xml': strToU8('<worksheet><sheetData><row><c r="B2" t="inlineStr"><is><t><![CDATA[<b>]]></t></is></c><c r="C2"><v>   </v></c></row><row><c r="AA1"><v>&#163;5</v></c></row></sheetData></worksheet>'),
            }));

            assert.deepEqual(rows(Grid.xlsx(path)), [['A & B', false, [[1, [[26, '£5']]], [2, [[1, '<b>']]]]]]);
        } finally {
            rmSync(directory, { recursive: true, force: true });
        }
    });

    it('places rows and cells without a reference after the previous ones, and reads an absolute target from the package root', () => {
        const directory = mkdtempSync(join(tmpdir(), 'stp-xlsx-'));
        const path = join(directory, 'refs.xlsx');

        try {
            writeFileSync(path, zipSync({
                'xl/workbook.xml': strToU8('<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" r:id="a"/></sheets></workbook>'),
                'xl/_rels/workbook.xml.rels': strToU8('<Relationships><Relationship Id="a" Target="/sheets/one.xml"/></Relationships>'),
                'sheets/one.xml': strToU8('<worksheet><sheetData><row><c><v>a</v></c><c><v>b</v></c><c r="E3"><v>e</v></c><c><v>f</v></c></row>'
                    + '<row><c t="inlineStr"><is><t>next</t></is></c></row><row r="7"><c r="B7"><v>x</v></c><c><v>y</v></c></row></sheetData></worksheet>'),
            }));

            assert.deepEqual(rows(Grid.xlsx(path)), [['S', false, [
                [1, [[0, 'a'], [1, 'b']]],
                [3, [[4, 'e'], [5, 'f']]],
                [4, [[0, 'next']]],
                [7, [[1, 'x'], [2, 'y']]],
            ]]]);
        } finally {
            rmSync(directory, { recursive: true, force: true });
        }
    });

    it('names a missing part, and a file that is not a workbook', () => {
        assert.throws(() => Grid.xlsxParts({}), new ContextAtlasError('The workbook has no xl/_rels/workbook.xml.rels.'));
        assert.throws(() => Grid.xlsx(fileURLToPath(new URL('../../../package.json', import.meta.url))), /as an XLSX workbook\.$/);
    });
});

describe('CSV reader', () => {
    it('reads bytes that are not UTF-8 as Windows-1252', () => {
        const bytes = new Uint8Array([0x50, 0x72, 0x69, 0x63, 0x65, 0x2c, 0x80, 0x0a, 0xa3, 0x35, 0x2c, 0x43, 0x61, 0x66, 0xe9]);

        assert.deepEqual(rows(Grid.csv(bytes, 'S')), [['S', false, [[1, [[0, 'Price'], [1, '€']]], [2, [[0, '£5'], [1, 'Café']]]]]]);
    });

    it('drops a byte-order mark and keeps valid UTF-8 as it is', () => {
        const bytes = new TextEncoder().encode('﻿Name,Größe\nä,1');

        assert.deepEqual(rows(Grid.csv(bytes)), [['Sheet1', false, [[1, [[0, 'Name'], [1, 'Größe']]], [2, [[0, 'ä'], [1, '1']]]]]]);
    });

    it('numbers records from 1 counting blank lines, and quotes across lines', () => {
        assert.deepEqual(rows(Grid.csv('a,b\r\n\r\n"multi\nline",""""\n , ,\nlast')), [['Sheet1', false, [
            [1, [[0, 'a'], [1, 'b']]],
            [3, [[0, 'multi\nline'], [1, '"']]],
            [5, [[0, 'last']]],
        ]]]);
    });
});

describe('spreadsheet ingestion', () => {
    it('takes the sheet name and title from the file name, and refuses other file types', () => {
        const directory = mkdtempSync(join(tmpdir(), 'stp-csv-'));

        try {
            const path = join(directory, 'spend.csv');
            writeFileSync(path, 'Supplier,Amount\nCafé Ltd,£1\nRingway,£2\n');
            const document = new Processor().ingestSpreadsheet(path, 'spend');

            assert.deepEqual(document.units.map((unit) => [unit.id, unit.path]), [['t1', ['spend']]]);
            assert.match(document.units[0]!.text, /^title: spend\nsheet: spend\nrecords: 2\n/);
            assert.throws(() => new Processor().ingestSpreadsheet(join(directory, 'a.ods'), 'x'), /Cannot read \.ods as a spreadsheet; give a \.csv or \.xlsx file\./);
            assert.throws(() => new Processor().ingestSpreadsheet(join(directory, 'missing.csv'), 'x'), /Cannot read .*missing\.csv\./);
        } finally {
            rmSync(directory, { recursive: true, force: true });
        }
    });
});

describe('number formatting', () => {
    it('writes numbers as PHP number_format does', () => {
        const cases: [number, string, string][] = [
            [1960, '1960', '1960'],
            [12345.5, '12,345.5', '12345.5'],
            [1.00005, '1.0001', '1.0001'],
            [0.99995, '1', '1'],
            [-0.00001, '0', '0'],
            [-12345.67891, '-12,345.6789', '-12345.6789'],
            [2000034784, '2,000,034,784', '2000034784'],
        ];

        for (const [value, formatted, key] of cases) {
            assert.deepEqual([formatNumber(value), numberKey(value)], [formatted, key], String(value));
        }
        assert.equal(formatNumber(null), '(none)');
    });
});
