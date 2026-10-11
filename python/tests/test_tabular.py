from __future__ import annotations

import zipfile
from functools import cmp_to_key
from pathlib import Path

import pytest
from support import ROOT

from context_atlas import ContextAtlasError, compat
from context_atlas.tabular.grid import Grid
from context_atlas.tabular.number import format_number, key

WORKBOOK = ROOT / 'spec' / 'fixtures' / 'workbook.xlsx'

MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'


def rows(source: str | bytes) -> dict[int, dict[int, str]]:
    return Grid.csv(source).sheets[0].rows


def test_xlsx_reads_sheets_in_order_with_shared_rich_and_inline_strings() -> None:
    sheets = Grid.xlsx(WORKBOOK).sheets

    assert [(sheet.name, sheet.hidden) for sheet in sheets] == [('Sales', False), ('Notes', False), ('Secret', True)]
    assert sheets[0].rows == {
        1: {0: 'Table 1 Sales by region (£)'},
        3: {1: 'Region', 2: 'Q1', 3: 'Q2'},
        4: {1: 'North', 2: '1200.5', 3: '1300'},
        5: {1: 'South', 2: '800', 3: '[x]'},
    }
    assert sheets[1].rows[2] == {0: 'Note 2', 1: 'Q2 is an estimate.'}


def test_xlsx_reads_root_and_xl_targets_and_positions_without_references(tmp_path: Path) -> None:
    path = tmp_path / 'book.xlsx'
    with zipfile.ZipFile(path, 'w') as archive:
        archive.writestr(
            'xl/workbook.xml',
            f'<workbook xmlns="{MAIN}" xmlns:r="{RELATIONSHIPS}"><sheets><sheet name="A" r:id="rId1"/>'
            '<sheet name="B" r:id="rId2" state="veryHidden"/></sheets></workbook>',
        )
        archive.writestr(
            'xl/_rels/workbook.xml.rels',
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            '<Relationship Id="rId1" Target="/custom/one.xml"/><Relationship Id="rId2" Target="worksheets/two.xml"/>'
            '</Relationships>',
        )
        archive.writestr(
            'xl/sharedStrings.xml',
            f'<sst xmlns="{MAIN}"><si><t>plain</t></si><si><r><t>rich </t></r><r><t>runs</t></r></si></sst>',
        )
        archive.writestr(
            'custom/one.xml',
            f'<worksheet xmlns="{MAIN}"><sheetData>'
            '<row><c><v>1</v></c><c t="s"><v>1</v></c></row>'
            '<row r="5"><c r="C7"><v>x</v></c><c><v>y</v></c></row>'
            '<row><c t="inlineStr"><is><t>z</t><r><t>not read</t></r></is></c><c r="bad"><v>w</v></c></row>'
            '</sheetData></worksheet>',
        )
        archive.writestr(
            'xl/worksheets/two.xml',
            f'<worksheet xmlns="{MAIN}"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c></row></sheetData>'
            '</worksheet>',
        )

    sheets = Grid.xlsx(path).sheets

    assert [(sheet.name, sheet.hidden) for sheet in sheets] == [('A', False), ('B', True)]
    assert sheets[0].rows == {1: {0: 'w', 1: 'rich runs'}, 7: {2: 'x', 3: 'y'}, 8: {0: 'z'}}
    assert sheets[1].rows == {1: {0: 'plain'}}


def test_xlsx_errors_name_the_problem(tmp_path: Path) -> None:
    (tmp_path / 'not.xlsx').write_text('plain text', encoding='utf-8')
    with zipfile.ZipFile(tmp_path / 'empty.xlsx', 'w') as archive:
        archive.writestr('other.xml', '<x/>')

    with pytest.raises(ContextAtlasError, match='as an XLSX workbook'):
        Grid.xlsx(tmp_path / 'not.xlsx')
    with pytest.raises(ContextAtlasError, match='The workbook has no xl/'):
        Grid.xlsx(tmp_path / 'empty.xlsx')


def test_csv_drops_a_byte_order_mark_and_reads_invalid_utf8_as_windows_1252() -> None:
    assert rows(b'\xef\xbb\xbfName,Amount\n') == {1: {0: 'Name', 1: 'Amount'}}
    assert rows(b'Caf\xe9,\x80 5,\x81\n') == {1: {0: 'Café', 1: '€ 5', 2: '\x81'}}
    assert rows('Café,ok\n'.encode()) == {1: {0: 'Café', 1: 'ok'}}


def test_csv_quotes_as_php_fgetcsv_reads_them() -> None:
    assert rows(' "a, b",c\n') == {1: {0: 'a, b', 1: 'c'}}
    assert rows('"a"b,"say ""hi"""\n') == {1: {0: 'ab', 1: 'say "hi"'}}
    assert rows('"one\ntwo",x\nnext,row\n') == {1: {0: 'one\ntwo', 1: 'x'}, 2: {0: 'next', 1: 'row'}}
    assert rows('a\rb,c\r\nd,e') == {1: {0: 'a\rb', 1: 'c'}, 2: {0: 'd', 1: 'e'}}


def test_csv_numbers_rows_from_1_and_drops_blank_cells_and_rows() -> None:
    assert rows('a,b\n\n , \nc,,d\n') == {1: {0: 'a', 1: 'b'}, 4: {0: 'c', 2: 'd'}}


def test_numbers_round_half_up_after_php_pre_rounding() -> None:
    assert [format_number(v) for v in (1.00005, 0.00005, 1.23455, 12345.6789, 9999.5, 1960.0, -0.00001)] == [
        '1.0001',
        '0.0001',
        '1.2346',
        '12,345.6789',
        '9999.5',
        '1960',
        '0',
    ]
    assert format_number(1e15) == '1,000,000,000,000,000'
    assert key(22219217.5) == '22219217.5'
    assert format_number(None) == '(none)'
    assert key(None) == ''


def test_php_casts_and_comparisons() -> None:
    assert [compat.float_str(v) for v in (1e25, 1e14, 0.1 + 0.2, 100.0, 1e-5, -0.0, 100000000000005.0)] == [
        '1.0E+25',
        '1.0E+14',
        '0.3',
        '100',
        '1.0E-5',
        '-0',
        '1.0000000000000E+14',
    ]
    assert compat.compare(10.0, '9abc') == -1
    assert compat.compare('10', '9') == 1
    assert compat.compare(-float('inf'), 'x') == -1
    assert sorted(['10', '9', '1a', '01', '1'], key=cmp_to_key(compat.natural_compare)) == ['01', '1', '1a', '9', '10']
    assert compat.total([0.1, 0.2, 0.3]) == 0.6000000000000001
