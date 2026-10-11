"""
The records of a JSON Lines file as one key tree: each non-blank line is a JSON value read and
printed by the JSON outline, under the root as item `[i]`, i counting non-blank lines from 0.
The outline is marked `records`, so the cutter cuts the records in order with no root unit.
"""
from __future__ import annotations

from typing import Any

from ..errors import ContextAtlasError
from . import json_outline


def read(source: str) -> dict[str, Any]:
    """
    `{lines, entries, anchors, records}`: as the other outlines give them, with `records` true.

    :raises ContextAtlasError: naming the line and the byte offset within it of invalid JSON
    """
    lines: list[str] = []
    entries: list[dict[str, Any]] = [{'path': [], 'start': 0, 'end': 0, 'value': None, 'alias': None}]
    item = 0
    for n, line in enumerate(source.split('\n')):
        if line.strip(' \t\r') == '':
            continue
        try:
            record = json_outline.read(line)
        except ContextAtlasError as error:
            raise ContextAtlasError(f'Invalid JSONL at line {n + 1}: {error}') from error
        offset = len(lines)
        for entry in record['entries']:
            entries.append({
                'path': [f'[{item}]', *entry['path']],
                'start': entry['start'] + offset,
                'end': entry['end'] + offset,
                'value': entry['value'],
                'alias': None,
            })
        lines.extend(record['lines'])
        item += 1
    entries[0]['end'] = len(lines)

    return {'lines': lines, 'entries': entries, 'anchors': {}, 'records': True}
