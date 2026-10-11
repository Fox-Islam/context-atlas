"""
Turns a sheet's grid into clean tables: finds where each table starts, folds title rows into its
caption, types its cells, melts period columns into rows and profiles each column.

A table is plain data (dicts and lists), because it travels inside the stored Document.
"""
from __future__ import annotations

import re
from functools import cmp_to_key
from typing import Any

from .. import compat
from . import cells
from .number import key as number_key

CATEGORY_LIMIT = 60

JOIN_OVERLAP = 0.9

_TITLE = re.compile(r'^Table \S+', re.IGNORECASE | re.ASCII)
_VALUE_UNIT = re.compile(r'\(([A-Za-z%£$€]{1,10})\)')
_INDICATOR = re.compile(r'indicator|series', re.IGNORECASE | re.ASCII)
_ISO_DATE = re.compile(r'^[0-9]{4}-[0-9]{2}-[0-9]{2}$')
_ID_NAME = re.compile(r'\b(no|number|id|code)\b', re.IGNORECASE | re.ASCII)
_NAME_UNIT = re.compile(r'\((GWh|MWh|kWh|tonnes|km|kg|%|£|\$|€)\)')

Table = dict[str, Any]


def split(rows: dict[int, dict[int, str]]) -> dict[str, Any]:
    """
    The text lines around a sheet's tables, and the tables. A table starts at a row of two or
    more cells and runs while consecutive rows keep two or more; one-cell rows before it are its
    caption, and the last "Table X" line among them is its title. A header with no rows under it
    is folded into the next table's caption.
    """
    text: list[str] = []
    tables: list[Table] = []
    current: Table | None = None
    pending: list[str] = []
    previous: int | None = None

    for number, row in rows.items():
        wide = len(row) >= 2

        if current is not None and wide and previous is not None and number == previous + 1:
            current['rows'].append({'source': number, 'cells': _row(row, len(current['header']))})
        elif wide:
            if current is not None:
                tables.append(current)
            current = {'title': _title(pending), 'caption': pending, 'header': _row(row, max(row) + 1), 'rows': []}
            pending = []
        else:
            if current is not None:
                tables.append(current)
                current = None
            line = cells.squash(next(iter(row.values())))
            pending.append(line)
            text.append(line)
        previous = number

    if current is not None:
        tables.append(current)

    return {'text': text, 'tables': _fold_empty(tables)}


def normalise(table_id: str, sheet: str, title: str, raw: Table, notes: dict[str, str]) -> Table:
    """
    The table with its header cleaned, note markers split out, cells typed, and period columns
    (five or more, and at least half the header) melted into Year/Quarter/Period rows.
    """
    header: dict[int, str] = {}
    column_notes: dict[str, list[int]] = {}
    for i, cell in enumerate(raw['header']):
        name, refs = cells.notes(cell)
        if name == '':
            name = f'Column {i + 1}'
        elif name in ('£', '$', '€'):
            name = f'Amount ({name})'
        header[i] = name
        if refs:
            column_notes[name] = refs

    periods = {i: found for i, cell in enumerate(raw['header']) if i > 0 and (found := cells.period(cell)) is not None}
    melt = len(periods) >= 5 and len(periods) >= 0.5 * len(header)
    quarters = any(found[2] is not None for found in periods.values())
    value_name = _value_name(raw)
    order = cells.date_order([cell for row in raw['rows'] for cell in row['cells']])

    rows: list[dict[str, Any]] = []
    row_notes: dict[str, list[int]] = {}
    units: dict[str, list[str]] = {}
    for row in raw['rows']:
        base: dict[str, Any] = {}
        for i, name in header.items():
            if melt and i in periods:
                continue
            label, refs = cells.notes(row['cells'][i])
            if refs:
                row_notes[label] = refs
            value, unit = cells.parse(label, order)
            if unit is not None and unit != 'date' and unit not in units.setdefault(name, []):
                units[name].append(unit)
            base[name] = value

        if not melt:
            rows.append({'source': f'{sheet}!{row["source"]}', 'values': base})
            continue

        for i, (label, year, quarter) in periods.items():
            values = dict(base)
            values.setdefault('Year', float(year))
            if quarters:
                values['Quarter'] = None if quarter is None else float(quarter)
                values['Period'] = label
            values[value_name] = cells.parse(row['cells'][i], order)[0]
            rows.append({'source': f'{sheet}!{row["source"]}', 'values': values})

    if melt:
        rows, value_name = _name_value_by_indicator(rows, value_name)

    labels = [found[0] for found in periods.values()]
    table: Table = {
        'id': table_id,
        'sheet': sheet,
        'title': title,
        'caption': raw['caption'],
        'melted': f'{len(periods)} period columns ({labels[0]} to {labels[-1]}) turned into rows' if melt else None,
        'joined': [],
        'rows': rows,
        'row_notes': row_notes,
        'column_notes': column_notes,
        'notes': notes,
        'units': {name: found for name, found in units.items() if found},
        'columns': {},
    }

    return profile(table)


def profile(table: Table) -> Table:
    """
    Gives each column a type and a role: constant, period, id, measure, category (at most 60
    values), label (more), text (long), or alias (another name for a label column, never offered
    as a filter).
    """
    values: dict[str, list[Any]] = {}
    for row in table['rows']:
        for name, value in row['values'].items():
            values.setdefault(name, []).append(value)

    count = len(table['rows'])
    table['columns'] = {
        name: _column(name, column, count, (table['units'].get(name) or [None])[0]) for name, column in values.items()
    }

    return _mark_aliases(table)


def join(tables: list[Table]) -> list[Table]:
    """
    Copies the category columns of a lookup table (one whose label column is unique) onto tables
    of the same workbook that share that column, when nine in ten of their values match. Only
    columns with fewer values than keys are copied: one with as many is another name for each
    key, not a grouping.

    Each lookup is the table as it was before any join, and each table it joins onto is the
    table as earlier joins left it.
    """
    for lookup_index, lookup in enumerate(list(tables)):
        for key_name, column in lookup['columns'].items():
            if column['role'] not in ('label', 'id', 'alias') or column['distinct'] != len(lookup['rows']):
                continue
            index = {compat.to_str(row['values'][key_name]): row['values'] for row in lookup['rows']}
            copy = [
                name
                for name, other in lookup['columns'].items()
                if other['role'] == 'category' and other['distinct'] < column['distinct']
            ]
            for t, table in enumerate(list(tables)):
                if (
                    t != lookup_index
                    and copy
                    and key_name in table['columns']
                    and _overlap(table, key_name, index) >= JOIN_OVERLAP
                ):
                    tables[t] = _copy_columns(table, key_name, index, copy, lookup['title'])

    return tables


def _row(row: dict[int, str], width: int) -> list[str]:
    return [cells.squash(row.get(i, '')) for i in range(width)]


def _title(lines: list[str]) -> str | None:
    title = None
    for line in lines:
        if _TITLE.match(line):
            title = line

    return title


def _fold_empty(tables: list[Table]) -> list[Table]:
    kept = []
    carry: list[str] = []

    for table in tables:
        if not table['rows']:
            carry.extend(table['caption'])
            carry.append(': '.join(cell for cell in table['header'] if cell not in ('', '0')))
            continue
        table['caption'] = [*carry, *table['caption']]
        carry = []
        kept.append(_drop_empty_columns(table))

    return kept


def _drop_empty_columns(table: Table) -> Table:
    keep = [
        i
        for i, name in enumerate(table['header'])
        if name != '' or any(row['cells'][i] not in cells.BLANKS for row in table['rows'])
    ]
    table['header'] = [table['header'][i] for i in keep]
    for row in table['rows']:
        row['cells'] = [row['cells'][i] for i in keep]

    return table


def _value_name(raw: Table) -> str:
    """The name for melted values: a unit in brackets from the title or caption, such as `GWh`, or `Value`."""
    match = _VALUE_UNIT.search((raw['title'] or '') + ' ' + ' '.join(raw['caption']))

    return match.group(1) if match else 'Value'


def _name_value_by_indicator(rows: list[dict[str, Any]], value_name: str) -> tuple[list[dict[str, Any]], str]:
    """
    World Bank style tables carry the measure's name in a constant "Indicator Name" column; the
    melted values take that name.
    """
    first = rows[0]['values'] if rows else {}
    indicator = None
    for name, value in first.items():
        found = {compat.to_str(row['values'][name]) for row in rows if name in row['values']}
        if _INDICATOR.search(name) and isinstance(value, str) and len(found) == 1:
            indicator = value
            break

    if indicator is None:
        return rows, value_name

    for row in rows:
        row['values'][indicator] = row['values'][value_name]
        del row['values'][value_name]

    return rows, indicator


def _column(name: str, values: list[Any], count: int, unit: str | None) -> dict[str, Any]:
    present = [value for value in values if value is not None]
    distinct = _counted(number_key(value) for value in present)
    numbers = sum(1 for value in present if isinstance(value, float))
    dates = sum(1 for value in present if isinstance(value, str) and _ISO_DATE.match(value))
    if present and numbers >= 0.9 * len(present):
        kind = 'number'
    elif present and dates >= 0.9 * len(present):
        kind = 'date'
    else:
        kind = 'text'
    average = sum(len(value) for value in distinct) / max(1, len(distinct))

    if len(distinct) == 1 and count > 1 and len(present) == count:
        role = 'constant'
    elif name in ('Year', 'Quarter', 'Period') or kind == 'date':
        role = 'period'
    elif len(distinct) > 0.6 * len(present) and _ID_NAME.search(name):
        role = 'id'
    elif kind == 'text' and average > 80:
        role = 'text'
    elif kind == 'number':
        role = 'measure'
    elif len(distinct) <= CATEGORY_LIMIT:
        role = 'category'
    else:
        role = 'label'

    column: dict[str, Any] = {
        'name': name,
        'type': kind,
        'role': role,
        'blank': count - len(present),
        'distinct': len(distinct),
        'top': list(distinct)[:6],
        'unit': unit if unit is not None else _unit_from_name(name),
    }
    if kind in ('number', 'date'):
        column['min'] = compat.php_min(present)
        column['max'] = compat.php_max(present)
    if role in ('category', 'period', 'label', 'id'):
        column['values'] = list(distinct)
        if role == 'period':
            column['values'] = sorted(column['values'], key=cmp_to_key(compat.natural_compare))

    return column


def _counted(keys: Any) -> dict[str, int]:
    """Keys by how often they occur, most frequent first, ties in first-seen order."""
    counts: dict[str, int] = {}
    for found in keys:
        counts[found] = counts.get(found, 0) + 1

    return dict(sorted(counts.items(), key=lambda item: -item[1]))


def _unit_from_name(name: str) -> str | None:
    match = _NAME_UNIT.search(name)
    if match:
        return match.group(1)

    return '%' if 'per cent' in name.lower() else None


def _mark_aliases(table: Table) -> Table:
    """
    A label column that maps one to one onto another with longer values (a country code onto the
    name) is an alias of it.
    """
    labels = [dict(column) for column in table['columns'].values() if column['role'] in ('label', 'category', 'id')]

    for a in labels:
        for b in labels:
            same = a['name'] == b['name'] or a['distinct'] != b['distinct']
            if same or table['columns'][b['name']]['role'] == 'alias':
                continue
            targets: dict[str, set[str]] = {}
            for row in table['rows']:
                source = compat.to_str(row['values'].get(a['name']))
                targets.setdefault(source, set()).add(compat.to_str(row['values'].get(b['name'])))
            one_to_one = all(len(found) <= 1 for found in targets.values())
            if one_to_one and sum(len(top) for top in a['top']) < sum(len(top) for top in b['top']):
                table['columns'][a['name']]['role'] = 'alias'
                table['columns'][a['name']]['alias_of'] = b['name']
                break

    return table


def _overlap(table: Table, key_name: str, index: dict[str, Any]) -> float:
    values = {compat.to_str(row['values'].get(key_name)) for row in table['rows']}
    hits = sum(1 for value in values if value in index)

    return 0.0 if not values else hits / len(values)


def _copy_columns(table: Table, key_name: str, index: dict[str, Any], copy: list[str], source: str) -> Table:
    """A new table with the columns added, leaving the one passed in unchanged for use as a lookup."""
    rows = []
    for row in table['rows']:
        found = index.get(compat.to_str(row['values'].get(key_name)), {})
        rows.append({**row, 'values': {**row['values'], **{name: found.get(name) for name in copy}}})
    joined = [*table['joined'], f'{", ".join(copy)} joined from "{source}" on {key_name}']

    return profile({**table, 'rows': rows, 'joined': joined})
