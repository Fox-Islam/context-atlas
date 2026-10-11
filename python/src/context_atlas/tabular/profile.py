"""
Ingests a spreadsheet. Each table becomes a unit whose text is its card, for routing; the cleaned
rows travel in the document's data for the planner to query. Sheets with no table become
passages. No Jev request is made at ingestion.
"""
from __future__ import annotations

import re
from typing import Any

from .. import compat
from ..document import Document, Unit
from ..index import build
from . import tables as tabulate
from .card import card_text
from .grid import Grid, Sheet

NAME = 'tabular'

_NOTES_SHEET = re.compile(r'^notes?$', re.IGNORECASE | re.ASCII)
_NOTE = re.compile(r'^Note ([0-9]+)$', re.IGNORECASE | re.ASCII)


def ingest(grid: Grid, document_id: str, title: str) -> Document:
    visible = [sheet for sheet in grid.sheets if not sheet.hidden]
    split = [tabulate.split(sheet.rows) for sheet in visible]
    notes = _notes(visible, split)

    tables: list[dict[str, Any]] = []
    units: list[Unit] = []
    for s, sheet in enumerate(visible):
        many = len(split[s]['tables']) > 1
        for i, raw in enumerate(split[s]['tables']):
            name = raw['title']
            if name is None:
                name = (f'{title}: {sheet.name}' if len(visible) > 1 else title) + (f' ({i + 1})' if many else '')
            tables.append(tabulate.normalise(f't{len(tables) + 1}', sheet.name, name, raw, notes))
        if not split[s]['tables'] and split[s]['text']:
            units.append(Unit(f's{s + 1}', 'passage', '\n'.join(split[s]['text']), [sheet.name]))

    tables = _share_notes(tabulate.join(tables))
    extra = {}
    for t, table in enumerate(tables):
        units.append(Unit(table['id'], 'table', card_text(table, 6), [table['sheet']], {'table': t}))
        extra[table['id']] = _values(table)

    return Document(
        document_id,
        NAME,
        units,
        [],
        build(units, extra),
        {'profile': NAME, 'score': None, 'by': 'file type'},
        {'tables': [stored(table) for table in tables]},
    )


def stored(table: dict[str, Any]) -> dict[str, Any]:
    """
    A table in its stored form: `columns` first, as a list in column order, because a JSON reader
    built on plain objects (JavaScript) reorders names that look like integers; then `rows`.
    """
    rest = {k: v for k, v in table.items() if k not in ('columns', 'rows')}

    return {'columns': list(table['columns'].values()), 'rows': table['rows'], **rest}


def by_name(table: dict[str, Any]) -> dict[str, Any]:
    """A stored table with its columns keyed by name again."""
    columns = table['columns']
    named = {str(column['name']): column for column in columns} if isinstance(columns, list) else dict(columns)

    return {'columns': named, **{k: v for k, v in table.items() if k != 'columns'}}


def _notes(sheets: list[Sheet], split: list[dict[str, Any]]) -> dict[str, str]:
    """
    Note number to text, from a sheet named "Notes": rows whose first cell is "Note N", the
    header included, since a notes sheet often has no header. Keys are strings, as JSON writes them.
    """
    notes: dict[int, str] = {}

    for s, sheet in enumerate(sheets):
        if not _NOTES_SHEET.match(compat.trim(sheet.name)):
            continue
        for table in split[s]['tables']:
            for row in [table['header'], *(found['cells'] for found in table['rows'])]:
                match = _NOTE.match(compat.trim(row[0] if row else ''))
                if match:
                    notes[int(match.group(1))] = row[1] if len(row) > 1 else ''

    return {str(number): text for number, text in notes.items()}


def _share_notes(tables: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """A label marked with a note in one table carries it wherever the same label appears."""
    by_label = {}
    for table in tables:
        for label, refs in table['row_notes'].items():
            by_label[label.lower()] = refs

    shared = []
    for table in tables:
        row_notes = dict(table['row_notes'])
        for column in table['columns'].values():
            for value in column.get('values') or []:
                if value.lower() in by_label:
                    row_notes[value] = by_label[value.lower()]
        shared.append({**table, 'row_notes': row_notes})

    return shared


def _values(table: dict[str, Any]) -> str:
    """
    Every value of a table's label, category, id and alias columns, so search finds a table by a
    name its card does not show.
    """
    values = []
    for column in table['columns'].values():
        if column['role'] in ('label', 'category', 'id', 'alias'):
            values.extend(column.get('values') or [])

    return ' '.join(values)
