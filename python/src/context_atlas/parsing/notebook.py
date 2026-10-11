"""
Reads a Jupyter notebook (nbformat 4 JSON) cell by cell: markdown cells as Markdown, code cells
as code followed by their text outputs, raw cells as paragraphs. Images and other rich outputs
are dropped. The rules are stated in spec/formats/notebook.md.
"""
from __future__ import annotations

import json
import re
from typing import Any

from .. import text
from ..blocks import Block, BlockType
from ..errors import ContextAtlasError
from ..text import WHITESPACE
from . import markdown

NOT_A_NOTEBOOK = 'A notebook must be a JSON object with a "cells" list.'

_ANSI = re.compile(r'\x1b\[[0-9;?]*[A-Za-z]')
_SPLIT = re.compile(r'\n[ \t]*\n')
_TRAILING = re.compile(f'[{WHITESPACE}]+$')


# PHP's json_decode refuses 512 nested arrays or objects and accepts 511; the spec's limit is
# that one.
MAX_DEPTH = 512


def parse(source: str) -> list[Block]:
    """:raises ContextAtlasError: when the text is not a JSON object with a `cells` list"""
    notebook = _decode(source)
    if not isinstance(notebook, dict) or not isinstance(notebook.get('cells'), list):
        raise ContextAtlasError(NOT_A_NOTEBOOK)

    blocks: list[Block] = []
    for cell in notebook['cells']:
        if isinstance(cell, dict) and isinstance(cell.get('cell_type'), str):
            blocks.extend(_cell(cell))

    return blocks


def _decode(source: str) -> Any:
    """The JSON value, or None where PHP's json_decode refuses the text."""

    def refuse(_: str) -> None:
        raise ValueError

    try:
        value = json.loads(source, parse_constant=refuse)
    except (ValueError, RecursionError):
        return None

    return value if _accepted(value) else None


def _accepted(value: Any) -> bool:
    """
    False where PHP's json_decode refuses the value: 512 or more nested arrays and objects, or a
    string holding an unpaired surrogate. Walked without recursion, as the nesting can run deep.
    """
    pending = [(value, 0)]
    while pending:
        item, level = pending.pop()
        if isinstance(item, str):
            try:
                item.encode()
            except UnicodeEncodeError:
                return False
        elif isinstance(item, (list, dict)):
            if level + 1 >= MAX_DEPTH:
                return False
            children = [*item.keys(), *item.values()] if isinstance(item, dict) else item
            pending.extend((child, level + 1) for child in children)

    return True


def _cell(cell: dict[str, Any]) -> list[Block]:
    source = _joined(cell.get('source', ''))
    kind = cell['cell_type']

    if kind == 'markdown':
        return markdown.parse(source)
    if kind == 'raw':
        return _paragraphs(source)
    if kind == 'code':
        return [*_code(source), *_outputs(cell.get('outputs', []))]

    return []


def _paragraphs(source: str) -> list[Block]:
    pieces = (text.squash(piece) for piece in _SPLIT.split(_lines(source)))

    return [Block.paragraph(piece) for piece in pieces if piece != '']


def _code(source: str, prefix: str = '') -> list[Block]:
    source = _lines(source)

    return [] if text.trim(source) == '' else [Block(BlockType.CODE, prefix + source)]


def _outputs(outputs: Any) -> list[Block]:
    blocks: list[Block] = []
    for output in outputs if isinstance(outputs, list) else []:
        kind = output.get('output_type') if isinstance(output, dict) else None
        if kind == 'stream':
            blocks.extend(_output(_joined(output.get('text', ''))))
        elif kind in ('execute_result', 'display_data'):
            data = output.get('data')
            blocks.extend(_output(_joined(data.get('text/plain', '')) if isinstance(data, dict) else ''))
        elif kind == 'error':
            blocks.extend(_error(_joined(output.get('ename', '')), _joined(output.get('evalue', ''))))

    return blocks


def _error(name: str, value: str) -> list[Block]:
    name = text.trim(_ANSI.sub('', name))
    value = text.trim(_ANSI.sub('', _lines(value)))
    if value == '':
        return _code(name)

    return _code(value if name == '' else f'{name}: {value}')


def _output(source: str) -> list[Block]:
    return _code(_TRAILING.sub('', _ANSI.sub('', _lines(source))), 'Output:\n')


def _joined(value: Any) -> str:
    """A source or text field: a string, or a list of strings joined with nothing between them."""
    if isinstance(value, str):
        return value

    return ''.join(item for item in value if isinstance(item, str)) if isinstance(value, list) else ''


def _lines(source: str) -> str:
    return source.replace('\r\n', '\n').replace('\r', '\n')
