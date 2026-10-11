"""
The cells of a spreadsheet as the file gives them: per sheet, rows keyed from 1 and cells keyed
from 0, with empty cells left out.
"""
from __future__ import annotations

import re
import zipfile
from dataclasses import dataclass, field
from pathlib import Path
from xml.etree import ElementTree

from .. import compat
from ..errors import ContextAtlasError

_RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'

_REFERENCE = re.compile(r'^([A-Z]+)([0-9]+)$')

# Windows-1252, with the five bytes it leaves undefined read as the code points of the same
# number, as PHP's mb_convert_encoding reads them.
_CP1252 = [bytes([byte]).decode('cp1252', errors='ignore') or chr(byte) for byte in range(256)]


@dataclass
class Sheet:
    name: str
    hidden: bool = False
    rows: dict[int, dict[int, str]] = field(default_factory=dict)


@dataclass
class Grid:
    sheets: list[Sheet]

    @staticmethod
    def csv(source: str | bytes, name: str = 'Sheet1') -> Grid:
        """
        Reads CSV with standard quoting and no escape character, as PHP's fgetcsv reads it. A
        UTF-8 byte-order mark is dropped, and bytes that are not valid UTF-8 are read as
        Windows-1252, the usual encoding of spreadsheets exported on Windows.
        """
        rows: dict[int, dict[int, str]] = {}

        for number, row in enumerate(_records(_decode(source)), start=1):
            cells = {index: cell for index, cell in enumerate(row) if compat.trim(cell) != ''}
            if cells:
                rows[number] = cells

        return Grid([Sheet(name, False, rows)])

    @staticmethod
    def xlsx(path: str | Path) -> Grid:
        """
        Reads an XLSX workbook's sheets in workbook order.

        :raises ContextAtlasError: when the file is not a workbook or misses a part
        """
        try:
            archive = zipfile.ZipFile(path)
        except (OSError, zipfile.BadZipFile) as error:
            raise ContextAtlasError(f'Cannot open {path} as an XLSX workbook.') from error

        with archive:
            strings = _shared_strings(archive)
            targets = _sheet_targets(archive)
            sheets = []

            for sheet in _children(_first(_xml(archive, 'xl/workbook.xml'), 'sheets'), 'sheet'):
                target = targets.get(sheet.get(f'{{{_RELATIONSHIPS}}}id', ''), '')
                sheets.append(
                    Sheet(
                        sheet.get('name', ''),
                        sheet.get('state', '') in ('hidden', 'veryHidden'),
                        _cells(_xml(archive, target), strings),
                    )
                )

        return Grid(sheets)


def _decode(source: str | bytes) -> str:
    data = source.encode() if isinstance(source, str) else source
    data = data.removeprefix(b'\xef\xbb\xbf')

    try:
        return data.decode('utf-8')
    except UnicodeDecodeError:
        return ''.join(_CP1252[byte] for byte in data)


def _records(text: str) -> list[list[str]]:
    """
    Every record, a blank line as an empty one, read as PHP's php_fgetcsv reads it with `,`, `"`
    and no escape, because Python's csv module differs from it: only `\n` ends a line, spaces
    before an opening quote are dropped, and text after a closing quote joins the field.
    """
    lines = re.findall(r'[^\n]*\n|[^\n]+$', text)
    records = []
    position = 0

    while position < len(lines):
        record, position = _record(lines, position)
        records.append(record)

    return records


def _record(lines: list[str], position: int) -> tuple[list[str], int]:
    """The record starting at line `position`, and the index of the line after it."""
    line = lines[position]
    position += 1
    limit = _line_end(line)
    at = 0
    fields: list[str] = []
    first = True

    def char(index: int) -> str:
        return line[index] if index < len(line) else '\0'

    while True:
        step = 1 if at < limit else 0
        if step == 1:
            skip = at
            while char(skip) != ',' and char(skip) in ' \t\n\v\f\r':
                skip += 1
            if char(skip) == '"' and skip < limit:
                at = skip

        if first and at == limit:
            return [], position
        first = False

        if step != 0 and char(at) == '"':
            field, at, step, line, limit, position = _enclosed(lines, position, line, limit, at + 1)
        else:
            start = at
            while step != 0 and char(at) != ',':
                at += 1
                step = 1 if at < limit else 0
            field = line[start:at]
            field = field[: _line_end(field)]
            if char(at) == ',':
                at += 1

        fields.append(field)
        if step <= 0:
            return fields, position


def _enclosed(lines: list[str], position: int, line: str, limit: int, at: int) -> tuple[str, int, int, str, int, int]:
    """A quoted field from just after its opening quote; continues onto later lines while the quote is open."""
    out = ''
    begin = at
    state = 0
    step = 1

    def char(text: str, index: int) -> str:
        return text[index] if index < len(text) else '\0'

    while True:
        if step == 0:
            if state == 2:
                out += line[begin : at - 1]
                begin = at
                break
            if begin != limit:
                out += line[begin:at]
                begin = at
            out += line[limit:]
            if position >= len(lines):
                if at > limit:
                    if begin == at:
                        begin -= 1
                    at -= 1
                break
            line = lines[position]
            position += 1
            at = begin = 0
            limit = _line_end(line)
            state = 0
        elif state == 2:
            if char(line, at) != '"':
                out += line[begin : at - 1]
                begin = at
                break
            out += line[begin:at]
            at += 1
            begin = at
            state = 0
        else:
            if char(line, at) == '"':
                state = 2
            at += 1
        step = 1 if at < limit else 0

    while step != 0 and char(line, at) != ',':
        at += 1
        step = 1 if at < limit else 0

    out += line[begin:at]

    return out, at + step, step, line, limit, position


def _line_end(text: str) -> int:
    """Where a trailing `\r\n`, `\n` or `\r` starts, or the length."""
    if text.endswith('\r\n'):
        return len(text) - 2
    if text.endswith(('\n', '\r')):
        return len(text) - 1

    return len(text)


def _shared_strings(archive: zipfile.ZipFile) -> list[str]:
    """Each string's own `t`, then the `t` of each rich-text run."""
    try:
        root = ElementTree.fromstring(archive.read('xl/sharedStrings.xml'))
    except KeyError:
        return []

    strings = []
    for item in _children(root, 'si'):
        text = _text(_first(item, 't'))
        for run in _children(item, 'r'):
            text += _text(_first(run, 't'))
        strings.append(text)

    return strings


def _sheet_targets(archive: zipfile.ZipFile) -> dict[str, str]:
    """Relationship id to part path: a target starting with `/` is from the package root, any other from `xl/`."""
    targets = {}

    for relation in _children(_xml(archive, 'xl/_rels/workbook.xml.rels'), 'Relationship'):
        target = relation.get('Target', '')
        targets[relation.get('Id', '')] = target[1:] if target.startswith('/') else f'xl/{target}'

    return targets


def _cells(sheet: ElementTree.Element, strings: list[str]) -> dict[int, dict[int, str]]:
    """
    A row or cell without a reference takes the position after the one before it. A cell's
    reference also sets the row number the next unreferenced row counts on from.
    """
    rows: dict[int, dict[int, str]] = {}
    number = 0

    for row in _children(_first(sheet, 'sheetData'), 'row'):
        given = row.get('r', '')
        number = int(compat.to_float(given)) if given != '' else number + 1
        column = -1
        for cell in _children(row, 'c'):
            reference = cell.get('r', '')
            column, number = _reference(reference) if reference != '' else (column + 1, number)
            kind = cell.get('t', '')
            if kind == 's':
                index = int(compat.to_float(_text(_first(cell, 'v'))))
                value = strings[index] if 0 <= index < len(strings) else ''
            elif kind == 'inlineStr':
                value = _text(_first(_first(cell, 'is'), 't'))
            else:
                value = _text(_first(cell, 'v'))
            if compat.trim(value) != '':
                rows.setdefault(number, {})[column] = value

    return dict(sorted(rows.items()))


def _reference(reference: str) -> tuple[int, int]:
    """Column from 0 and row from 1 of a reference such as `C7`; a malformed one reads as A1."""
    match = _REFERENCE.match(reference)
    letters, number = (match.group(1), int(match.group(2))) if match else ('A', 1)
    column = 0

    for letter in letters:
        column = column * 26 + ord(letter) - 64

    return column - 1, number


def _xml(archive: zipfile.ZipFile, part: str) -> ElementTree.Element:
    try:
        return ElementTree.fromstring(archive.read(part))
    except KeyError as error:
        raise ContextAtlasError(f'The workbook has no {part}.') from error


def _local(tag: str) -> str:
    return tag.rsplit('}', 1)[-1]


def _children(element: ElementTree.Element | None, name: str) -> list[ElementTree.Element]:
    """Child elements by local name, whatever the namespace: transitional and strict workbooks differ in it."""
    return [] if element is None else [child for child in element if _local(child.tag) == name]


def _first(element: ElementTree.Element | None, name: str) -> ElementTree.Element | None:
    found = _children(element, name)

    return found[0] if found else None


def _text(element: ElementTree.Element | None) -> str:
    """An element's own text, without its children's, as PHP's SimpleXML casts it."""
    if element is None:
        return ''

    return (element.text or '') + ''.join(child.tail or '' for child in element)
