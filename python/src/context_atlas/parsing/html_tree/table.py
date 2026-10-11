"""
A `table` element as a table block: text `Table: <caption>`, then the header and rows with cells
joined by ` | `; meta `{caption, header, rows}`. A cell is repeated across its colspan and
carried down its rowspan; tables inside cells are read as text.
"""
from __future__ import annotations

import re
from typing import Any

from ... import text
from ...blocks import Block, BlockType
from . import content
from .element import Element

MAX_SPAN = 100

_SPAN = re.compile(r'^[0-9]{1,9}$')


def read(table: Element, in_article: bool) -> dict[str, Any] | None:
    caption: list[str] = ['']
    rows: list[dict[str, Any]] = []
    _rows(table, in_article, False, rows, caption)

    if caption[0] == '' and _is_title_row(rows):
        caption[0] = rows[0]['cells'][0]['text']
        rows = rows[1:]

    grid = _grid(rows)
    header = grid[0]['cells'] if grid and grid[0]['header'] else []
    if header:
        grid = grid[1:]
    if ''.join(header) == '':
        header = []
    body = [row['cells'] for row in grid if ''.join(row['cells']) != '']

    return None if not header and not body else {'caption': caption[0], 'header': header, 'rows': body}


def block(table: dict[str, Any]) -> Block:
    lines = [f'Table: {table["caption"]}'] if table['caption'] != '' else []
    for cells in ([table['header'], *table['rows']] if table['header'] else table['rows']):
        lines.append(' | '.join(cells))

    return Block(BlockType.TABLE, '\n'.join(lines), None, table)


def _is_title_row(rows: list[dict[str, Any]]) -> bool:
    """
    A first row, followed by others, of one non-empty cell spanning more than one column and at
    least as many as any other row's cells span (rowspans aside).
    """
    if len(rows) < 2 or len(rows[0]['cells']) != 1:
        return False
    first = rows[0]['cells'][0]
    if first['colspan'] < 2 or first['text'] == '':
        return False

    return all(sum(cell['colspan'] for cell in row['cells']) <= first['colspan'] for row in rows[1:])


def _rows(parent: Element, in_article: bool, head: bool, rows: list[dict[str, Any]], caption: list[str]) -> None:
    """The rows of a table, not those of tables inside it, and its first non-empty caption."""
    for child in parent.children:
        if not isinstance(child, Element) or child.name == 'table' or content.dropped(child, in_article):
            continue
        if child.name == 'caption':
            if caption[0] == '':
                caption[0] = text.squash(content.line(child, in_article))
        elif child.name == 'tr':
            rows.append({'cells': _cells(child, in_article), 'head': head})
        else:
            _rows(child, in_article, head or child.name == 'thead', rows, caption)


def _cells(parent: Element, in_article: bool) -> list[dict[str, Any]]:
    cells: list[dict[str, Any]] = []
    for child in parent.children:
        if not isinstance(child, Element) or child.name in ('table', 'tr') or content.dropped(child, in_article):
            continue
        if child.name in ('td', 'th'):
            cells.append({
                'text': text.squash(content.line(child, in_article)),
                'colspan': _span(child.attribute('colspan')),
                'rowspan': _span(child.attribute('rowspan')),
                'th': child.name == 'th',
            })
        else:
            cells.extend(_cells(child, in_article))

    return cells


def _span(value: str | None) -> int:
    trimmed = (value or '').strip(' \t\n\f\r')

    return max(1, min(MAX_SPAN, int(trimmed))) if _SPAN.match(trimmed) else 1


def _grid(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Rows of cell texts; a row is a header row when it sits in a `thead` or all its cells are `th`."""
    grid = []
    carry: dict[int, list[Any]] = {}

    for row in rows:
        out: list[str] = []
        column = 0
        for cell in row['cells']:
            column = _carried(carry, out, column)
            for _ in range(cell['colspan']):
                out.append(cell['text'])
                if cell['rowspan'] > 1:
                    carry[column] = [cell['text'], cell['rowspan'] - 1]
                column += 1
        _carried(carry, out, column)
        all_th = bool(row['cells']) and all(cell['th'] for cell in row['cells'])
        grid.append({'cells': out, 'header': row['head'] or all_th})

    return grid


def _carried(carry: dict[int, list[Any]], out: list[str], column: int) -> int:
    """Places the cells carried down into `column` and the columns after it, and returns the next free column."""
    while column in carry:
        out.append(carry[column][0])
        carry[column][1] -= 1
        if carry[column][1] == 0:
            del carry[column]
        column += 1

    return column
