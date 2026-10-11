"""
A table described for Jev and for the reader: title, caption, record count and each column's
role, unit and values. Every category value is listed, because a card that shows only some leads
Jev to judge the others absent.
"""
from __future__ import annotations

from typing import Any

from .number import format_number


def card(table: dict[str, Any], examples: int) -> dict[str, Any]:
    columns = {}

    for column in table['columns'].values():
        description = _describe(column, examples)
        if column['blank'] > 0 and column['role'] != 'constant':
            description += f'; blank in {column["blank"]} records'
        columns[column['name']] = description

    described: dict[str, Any] = {
        'title': table['title'],
        'sheet': table['sheet'],
        'records': len(table['rows']),
        'columns': columns,
    }
    if table['caption']:
        described['about'] = ' '.join(table['caption'][:4])
    if table['melted'] is not None:
        described['layout'] = table['melted']
    if table['joined']:
        described['joined'] = '; '.join(table['joined'])

    return described


def card_text(table: dict[str, Any], examples: int) -> str:
    """The card as plain text, for embedding and for meaning questions."""
    lines = []

    for name, value in card(table, examples).items():
        if isinstance(value, dict):
            lines.append(f'{name}:\n' + '\n'.join(f'  - {column}: {text}' for column, text in value.items()))
        else:
            lines.append(f'{name}: {value}')

    return '\n'.join(lines)


def _describe(column: dict[str, Any], examples: int) -> str:
    role = column['role']
    values = column.get('values') or []

    if role == 'measure':
        unit = f' ({column["unit"]})' if column['unit'] is not None else ''
        return f'number{unit}, {format_number(column.get("min"))} to {format_number(column.get("max"))}'
    if role == 'period':
        last = values[-1] if values and values[-1] not in ('', '0') else ''
        first = values[0] if values else ''
        return f'{"date" if column["type"] == "date" else "period"}, {column["distinct"]} values from {first} to {last}'
    if role == 'constant':
        return 'same in every record: ' + (column['top'][0] if column['top'] else '')
    if role == 'alias':
        return f'another name for each {column["alias_of"]}, e.g. {"; ".join(column["top"][:3])}'
    if role == 'category':
        return f'one of {column["distinct"]} values: {"; ".join(values)}'

    shown = '; '.join(value[:60] for value in column['top'][:examples])

    return f'{"identifier" if role == "id" else "text"}, {column["distinct"]} distinct, e.g. {shown}'
