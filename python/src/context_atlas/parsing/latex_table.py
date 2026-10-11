"""
Splits the content of a LaTeX tabular into rows and cells: rows at `\\\\` and `\\tabularnewline`,
cells at `&`, both outside braces; rule commands dropped. In a longtable the repeated header and
the footers are dropped and a `\\caption` row gives the caption. The rules are stated in
spec/formats/latex.md.
"""
from __future__ import annotations

import re

from .. import text
from . import latex_inline

_RULES = re.compile(
    r'\\(?:(?:toprule|midrule|bottomrule)(?:\[[^\]]*\])?|hline|hdashline|cline[ \t\n]*\{[^}]*\}'
    r'|hhline[ \t\n]*\{[^}]*\}|cmidrule[ \t\n]*(?:\[[^\]]*\])?[ \t\n]*(?:\([^)]*\))?[ \t\n]*\{[^}]*\}'
    r'|addlinespace(?:[ \t\n]*\[[^\]]*\])?|specialrule[ \t\n]*\{[^}]*\}[ \t\n]*\{[^}]*\}[ \t\n]*\{[^}]*\}'
    r'|rowcolor[ \t\n]*(?:\[[^\]]*\])?[ \t\n]*\{[^}]*\})(?![A-Za-z])'
)
_CAPTION = re.compile(r'(?<!\\)\\caption(?![A-Za-z])\*?')
_NAME = re.compile(r'[A-Za-z]+')

LONGTABLE_MARKS = ('endfirsthead', 'endhead', 'endfoot', 'endlastfoot')


def read(content: str, long: bool) -> tuple[str | None, list[list[str]]]:
    """The caption a longtable gives, and the rows."""
    caption = None
    rows = []

    for raw in _kept(_split(content, long)):
        raw = _RULES.sub('', raw)
        found = _CAPTION.search(raw) if long else None
        if found is not None:
            value = latex_inline.argument(raw, found.end())[0]
            caption = text.squash(latex_inline.convert(value or ''))
            continue
        cells = [text.squash(latex_inline.convert(cell)) for cell in _cells(raw)]
        if any(cell != '' for cell in cells):
            rows.append(cells)

    return caption, rows


def _split(content: str, long: bool) -> list[tuple[str, str | None]]:
    """Each row's source and the longtable mark that ended it."""
    rows: list[tuple[str, str | None]] = []
    row: list[str] = []
    depth = 0
    n = len(content)
    i = 0

    while i < n:
        char = content[i]
        if char != '\\':
            depth += 1 if char == '{' else (-1 if char == '}' else 0)
            row.append(char)
            i += 1
            continue
        match = _NAME.match(content, i + 1)
        name = match.group(0) if match else ''
        mark = name if long and name in LONGTABLE_MARKS else None
        if depth <= 0 and (content[i + 1 : i + 2] == '\\' or name == 'tabularnewline' or mark is not None):
            rows.append((''.join(row), mark))
            row = []
            i = _after_break(content, i, name) + 1
            continue
        piece = '\\' + name if name != '' else content[i : i + 2]
        row.append(piece)
        i += len(piece)
    rows.append((''.join(row), None))

    return rows


def _after_break(content: str, i: int, name: str) -> int:
    if name != '':
        return i + len(name)
    i += 2
    i = i + 1 if content[i : i + 1] == '*' else i
    j = latex_inline.space(content, i, False)

    return (latex_inline.optional(content, j)[1] if content[j : j + 1] == '[' else i) - 1


def _kept(rows: list[tuple[str, str | None]]) -> list[str]:
    """
    Drops a longtable's repeated header (the rows ended by `\\endhead` when `\\endfirsthead` is
    used) and its footers (the rows ended by `\\endfoot` or `\\endlastfoot`).
    """
    first = any(mark == 'endfirsthead' for _, mark in rows)
    kept: list[str] = []
    segment: list[str] = []

    for raw, mark in rows:
        segment.append(raw)
        if mark is None:
            continue
        if mark == 'endfirsthead' or (mark == 'endhead' and not first):
            kept.extend(segment)
        segment = []

    return [*kept, *segment]


def _cells(row: str) -> list[str]:
    cells = []
    cell: list[str] = []
    depth = 0
    i = 0
    n = len(row)

    while i < n:
        char = row[i]
        if char == '\\':
            cell.append(row[i : i + 2])
            i += 2
            continue
        if char == '&' and depth <= 0:
            cells.append(''.join(cell))
            cell = []
        else:
            depth += 1 if char == '{' else (-1 if char == '}' else 0)
            cell.append(char)
        i += 1
    cells.append(''.join(cell))

    return cells
