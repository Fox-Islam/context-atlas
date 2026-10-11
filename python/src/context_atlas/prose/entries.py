"""
Turns the blocks of a reference section into entries: one per documented member, glossary term
or table row, each carrying its name and the fields a filter can match.

An entry starts at a block whose meta names it (`entry`), or by layout: a short line with no
sentence end followed by a paragraph more than twice its length, or a call signature such as
`Path.walk(top_down=True)`. The blocks after it belong to it until the next entry or heading.

A table becomes one entry per row. When its row key is a short index and two or more column
groups share a prefix ("A series", "B series"), each group is its own entry, named by the
prefix and the key ("A4"): a reader asks for "C5", which no cell holds.
"""
from __future__ import annotations

import re
import unicodedata
from typing import Any

from .. import compat, text
from ..blocks import Block, BlockType
from ..text import WHITESPACE

HEAD_MAX = 60

# The PHP patterns have no `u` flag: `\w`, `\s` and `\d` are ASCII.
_SIGNATURE = re.compile(r'^[A-Za-z_][\w.]*\(.*\)\s*(?:->.*)?$|^[A-Z]\w*\.\w+$', re.ASCII)
_GLOSSARY = re.compile(rf'^.{{1,{HEAD_MAX}}}?(?::[{WHITESPACE}]| \u2013 | - )[^{WHITESPACE}]')
_SENTENCE_END = re.compile(r'[.!?:;,]$')
_BRACKETS = re.compile(r'\((.*)\)')
_COMMA = re.compile(r'\s*,\s*', re.ASCII)
_DEFAULT = re.compile(r'[=:].*$')
_VERSIONS = {
    field: re.compile(prefix + r'\s*(\d+(?:\.\d+)*)', re.IGNORECASE | re.ASCII)
    for field, prefix in (
        ('added', r'(?:added in version|versionadded::?|new in version)'),
        ('changed', r'(?:changed in version|versionchanged::?)'),
        ('deprecated', r'(?:deprecated since version|deprecated::?)'),
    )
}
_EXCEPTION = re.compile(r'\b([A-Z][A-Za-z]*(?:Error|Exception|Warning))\b', re.ASCII)
_GROUP = re.compile(r'^([A-Z]{1,2})(?: series)?\b', re.ASCII)
_SERIES = re.compile(r'\b([A-Z]{1,3}) series\b', re.ASCII)
_INDEX = re.compile(r'^(\d{1,2}|[IVX]{1,5})$', re.ASCII)
_CAPTION = re.compile(r'^Table:\s*(.*)$', re.ASCII)
_ITEM = re.compile(rf'^[{WHITESPACE}]*(?:[-*+]|\d{{1,9}}[.)])[{WHITESPACE}]+([^{WHITESPACE}].*)$')
_ITEM_NAME = re.compile(rf'^(.{{1,{HEAD_MAX}}}?)(?::[{WHITESPACE}]| \u2013 | - | \()')
_SEPARATOR = re.compile(r'^\|?[\s:|-]+\|?$', re.ASCII)

Entry = dict[str, Any]


def build(blocks: list[tuple[Block, list[str]]]) -> list[Entry]:
    """
    Entries from a section's blocks, each given with its heading path: `{name, text, path, kind,
    group?, fields, blocks}`, where `kind` is `entry` or `row` and `blocks` index `blocks`.
    """
    entries: list[Entry] = []
    current: dict[str, Any] | None = None
    by_meta = any(isinstance(_meta(block).get('entry'), str) for block, _ in blocks)

    for i, (block, path) in enumerate(blocks):
        if block.is_heading():
            current = _close(entries, current)
            following = blocks[i + 1][0] if i + 1 < len(blocks) else None
            if following is None or (following.is_heading() and (following.level or 0) <= (block.level or 0)):
                heading = text.squash(block.text)
                entries.append(
                    {'name': heading, 'text': heading, 'path': path, 'kind': 'entry', 'fields': {}, 'blocks': [i]}
                )
            continue
        if block.type is BlockType.TABLE:
            current = _close(entries, current)
            entries.extend(_rows(block, path, i))
            continue

        name = _head(blocks, i, by_meta)
        if name is not None:
            _close(entries, current)
            signature = _meta(block).get('signature')
            current = {
                'name': name,
                'signature': signature if isinstance(signature, str) else name,
                'lines': [text.trim(block.text)],
                'path': path,
                'blocks': [i],
            }
        elif current is not None:
            current['lines'].append(text.trim(block.text))
            current['blocks'].append(i)
        elif (items := _items(block)) and _is_glossary(items):
            entries.extend(
                {
                    'name': _item_name(item),
                    'text': item,
                    'path': path,
                    'kind': 'entry',
                    'fields': _fields('', item),
                    'blocks': [i],
                }
                for item in items
            )
        else:
            current = {
                'name': path[-1] if path else 'section',
                'signature': '',
                'lines': [text.trim(block.text)],
                'path': path,
                'blocks': [i],
            }

    _close(entries, current)

    return entries


def _is_glossary(items: list[str]) -> bool:
    """Whether more than half the items open with a term: up to 60 characters, then a colon, a dash or an en dash."""
    return 2 * sum(1 for item in items if _GLOSSARY.match(item)) > len(items)


def _head(blocks: list[tuple[Block, list[str]]], i: int, by_meta: bool) -> str | None:
    """The entry name a block starts, or None. Where meta names entries, only those start one."""
    block = blocks[i][0]
    squashed = text.squash(block.text)

    if by_meta:
        entry = _meta(block).get('entry')
        starts = isinstance(entry, str) and (i == 0 or _meta(blocks[i - 1][0]).get('entry') != entry)
        return entry if starts else None
    if block.type is not BlockType.PARAGRAPH or not any(unicodedata.category(char)[0] == 'L' for char in squashed):
        return None
    if _SIGNATURE.match(squashed) and len(squashed) <= 2 * HEAD_MAX:
        return squashed

    following = blocks[i + 1][0] if i + 1 < len(blocks) else None
    short = len(squashed) <= HEAD_MAX and not _SENTENCE_END.search(squashed)
    longer = (
        following is not None
        and following.type is BlockType.PARAGRAPH
        and len(text.trim(following.text)) > 2 * len(squashed)
    )

    return squashed if short and longer else None


def _items(block: Block) -> list[str]:
    """
    The items of a list paragraph (every line opening with `- `, `* `, `+ ` or `1.`/`1)`), with
    their markers removed; empty for any other block.
    """
    if block.type is not BlockType.PARAGRAPH:
        return []
    found = []
    for line in text.trim(block.text).split('\n'):
        match = _ITEM.match(line)
        if match is None:
            return []
        found.append(text.trim(match.group(1)))

    return found


def _item_name(item: str) -> str:
    """
    An item's name: its text up to the first `: `, an en dash between spaces, ` - ` or ` (`,
    when that comes within HEAD_MAX characters, else its first HEAD_MAX characters.
    """
    match = _ITEM_NAME.match(item)

    return text.trim(match.group(1)) if match else text.trim(item[:HEAD_MAX])


def _close(entries: list[Entry], current: dict[str, Any] | None) -> None:
    if current is not None:
        joined = '\n\n'.join(current['lines'])
        entries.append({
            'name': current['name'],
            'text': joined,
            'path': current['path'],
            'kind': 'entry',
            'fields': _fields(current['signature'], joined),
            'blocks': current['blocks'],
        })


def _fields(name: str, body: str) -> dict[str, list[str]]:
    """
    Parameters from a call signature, versions from "Added in version 3.12" notes and reST
    directives, and exception names.
    """
    fields: dict[str, list[str]] = {}

    brackets = _BRACKETS.search(name)
    if brackets:
        for parameter in _COMMA.split(brackets.group(1)):
            parameter = _DEFAULT.sub('', text.trim(parameter)).lstrip('*')
            if parameter not in ('', '/', 'self'):
                fields.setdefault('parameter', []).append(parameter)
    for field, pattern in _VERSIONS.items():
        found = pattern.findall(body)
        if found:
            fields[field] = list(dict.fromkeys(found))
    exceptions = _EXCEPTION.findall(body)
    if exceptions:
        fields['exception'] = list(dict.fromkeys(exceptions))

    return fields


def _rows(table: Block, path: list[str], index: int) -> list[Entry]:
    caption, header, rows = _table(table)
    groups: dict[str, list[int]] = {}
    for c, label in enumerate(header):
        match = _GROUP.match(label) if c > 0 else None
        if match:
            groups.setdefault(match.group(1), []).append(c)
    series_match = _SERIES.search(caption)
    series = series_match.group(1) if series_match else None
    section = ' > '.join(path)
    context = text.trim((f'[{section}] ' if section != '' else '') + (f'Table: {caption}' if caption != '' else ''))
    entries: list[Entry] = []

    for row in rows:
        key = text.trim(row[0] if row else '')
        if key == '' or sum(1 for cell in row if text.trim(cell) != '') < 2:
            continue
        indexed = _INDEX.match(key) is not None
        every = list(range(1, max(1, len(row) - 1) + 1))
        if indexed and len(groups) >= 2:
            names = {f'{group}{key}': columns for group, columns in groups.items()}
        elif indexed and series is not None:
            names = {f'{series}{key}': every}
        else:
            names = {key: every}

        for name, columns in names.items():
            fields: dict[str, list[str]] = {}
            labels = []
            for c in columns:
                value = text.trim(row[c] if c < len(row) else '')
                if value != '':
                    head = text.trim(header[c] if c < len(header) else f'column {c}')
                    fields.setdefault(head, []).append(value)
                    labels.append(f'{head}: {value}')
            entries.append({
                'name': name,
                'text': f'{context}\n{" | ".join(header)}\n{" | ".join(row)}\nEntry {name}: {"; ".join(labels)}',
                'path': path,
                'kind': 'row',
                'group': caption if caption != '' else section,
                'fields': fields,
                'blocks': [index],
            })

    return entries


def _table(table: Block) -> tuple[str, list[str], list[list[str]]]:
    """Caption, header and rows from the block's meta when given, else from its text split on `|`."""
    meta = _meta(table)
    header, rows = meta.get('header'), meta.get('rows')
    if isinstance(header, (list, dict)) and isinstance(rows, (list, dict)):
        return (
            _strval(meta.get('caption')),
            [_strval(cell) for cell in _values(header)],
            [[_strval(cell) for cell in _values(row)] for row in _values(rows)],
        )

    caption = ''
    found: list[list[str]] = []
    for line in table.text.split('\n'):
        line = text.trim(line)
        match = _CAPTION.match(line)
        if match:
            caption = match.group(1)
        elif '|' in line and not _SEPARATOR.match(line):
            found.append([text.trim(cell) for cell in line.strip('|').split('|')])

    return caption, (found[0] if found else []), found[1:]


def _meta(block: Block) -> dict[str, Any]:
    return block.meta if isinstance(block.meta, dict) else {}


def _values(value: Any) -> list[Any]:
    """PHP's `array_values((array) $value)`."""
    if isinstance(value, dict):
        return list(value.values())
    if isinstance(value, list):
        return value

    return [] if value is None else [value]


def _strval(value: Any) -> str:
    if value is None or value is False:
        return ''
    if value is True:
        return '1'

    return compat.to_str(value)
