"""
The key tree of a block-style YAML file with the line span of every node, read from indentation
alone: enough for OpenAPI documents, Compose files and CI workflows, with no YAML library, so every
package reads a file the same way.

Read: `key: value` and `- item` lines (an item may open with a key), quoted keys, `&anchor`
(after a key or an item, or alone on the line below one), `*alias` and `<<: *alias`. A block
scalar (`|`, `>`) and an unclosed flow collection (`[`, `{`) belong to their key, and their lines
are not parsed. Comment lines directly above a node belong to it; comment and blank lines after
it do not. Lines that are none of these, such as a plain scalar continued on the next line,
belong to the node above them. `---` and `...` are skipped, so the documents of a stream read as
one.
"""
from __future__ import annotations

import re
from typing import Any

from .. import compat

# The PHP patterns have no `u` flag: `\s` is ASCII. PHP reads quoted strings possessively
# (`[^"\\]*+(?:\\.[^"\\]*+)*+`): a lookahead capture consumed by a backreference gives the same
# match in `re`, which has no possessive quantifiers before Python 3.11. It matters for an
# unterminated `'a'' b`, which a backtracking match would read as `'a'`.
_DOUBLE_BODY = r'(?=(?P<double>[^"\\]*(?:\\.[^"\\]*)*))(?P=double)'
_SINGLE_BODY = r"(?=(?P<single>[^']*(?:''[^']*)*))(?P=single)"
KEY = re.compile(
    rf'^(?:"{_DOUBLE_BODY}"|\'{_SINGLE_BODY}\'|(?P<plain>[^\s#\'"\[\]{{}},&*!|>%@`-][^#]*?|-[^\s#][^#]*?))'
    r'\s*:(?:\s+(?P<value>.*))?$',
    re.ASCII,
)
_DOCUMENT = re.compile(r'^(?:---|\.\.\.)(?:\s|$)', re.ASCII)
_ALIAS = re.compile(r'^\*([^\s,\[\]{}]+)', re.ASCII)
_BLOCK = re.compile(r'^[|>][0-9+-]*(\s+#.*)?$', re.ASCII)
_ANCHOR = re.compile(r'^&([^\s,\[\]{}]+)\s*(.*)$', re.ASCII)
_COMMENT = re.compile(r'^\s*#', re.ASCII)
_EMPTY = re.compile(r'^\s*(#.*)?$', re.ASCII)
_DOUBLE = re.compile(rf'^"{_DOUBLE_BODY}"')
_SINGLE = re.compile(rf"^'{_SINGLE_BODY}'")
_TRAILING_COMMENT = re.compile(r'\s+#.*$', re.ASCII)
_ESCAPE = re.compile(r'\\(.)', re.DOTALL)

Outline = dict[str, Any]


def read(source: str) -> Outline:
    """`{lines, entries, anchors}`: entries are `{path, start, end, value, alias}`, in the order the nodes open."""
    return _Reader(source.replace('\r\n', '\n').replace('\r', '\n').split('\n')).parse()


class _Reader:
    def __init__(self, lines: list[str]) -> None:
        self.lines = lines
        self.entries: list[dict[str, Any]] = [{'path': [], 'start': 0, 'end': len(lines), 'value': None, 'alias': None}]
        self.stack: list[dict[str, Any]] = [{'indent': -1, 'entry': 0, 'kind': 'root', 'items': 0}]
        self.anchors: dict[str, list[str]] = {}

    def parse(self) -> Outline:
        block: int | None = None
        flow = 0
        for n, line in enumerate(self.lines):
            content = line.lstrip(' ')
            indent = len(line) - len(content)
            content = content.rstrip(' \t\n\r\x00\x0b')
            if content == '' or content[0] == '#':
                continue
            if block is not None and indent > block:
                continue
            block = None
            if flow > 0:
                flow += _depth(content)
                continue
            if _DOCUMENT.match(content):
                continue
            block, flow = self._node(n, indent, content)
        self._close(-2, len(self.lines))

        return {'lines': self.lines, 'entries': self.entries, 'anchors': self.anchors}

    def _node(self, n: int, indent: int, content: str) -> tuple[int | None, int]:
        """
        Reads a line that opens a node; returns the indent past which following lines belong to a
        block scalar, and the depth of an unclosed flow collection.
        """
        if content == '-' or content.startswith('- '):
            self._close(indent, n, item=True)
            parent = self.stack[-1]
            path = [*self.entries[parent['entry']]['path'], f'[{parent["items"]}]']
            parent['items'] += 1
            self._open(n, indent, 'item', path)
            rest = content[1:].lstrip(' ')
            column = indent + len(content) - len(rest)
            anchor, rest = _split_anchor(rest)
            self._remember(anchor)
            if rest == '':
                return None, 0
            if KEY.match(rest) and not _is_flow(rest):
                return self._node(n, column, rest)
            return self._value(rest, indent)

        if content[0] == '&' and len(self.stack) > 1:
            anchor, rest = _split_anchor(content)
            self._remember(anchor)
            return (None, 0) if rest == '' else self._value(rest, self.stack[-1]['indent'])

        match = KEY.match(content)
        if match is None:
            return None, 0
        if match.group('double'):
            key = _unescape(match.group('double'))
        elif match.group('single'):
            key = match.group('single').replace("''", "'")
        else:
            key = match.group('plain') or ''
        self._close(indent, n)
        self._open(n, indent, 'key', [*self.entries[self.stack[-1]['entry']]['path'], key])
        anchor, rest = _split_anchor(compat.trim(match.group('value') or ''))
        self._remember(anchor)

        return self._value(rest, indent)

    def _value(self, rest: str, indent: int) -> tuple[int | None, int]:
        entry = self.entries[self.stack[-1]['entry']]
        alias = _ALIAS.match(rest)
        if alias:
            entry['alias'] = alias.group(1)
            return None, 0
        if _BLOCK.match(rest):
            return indent, 0
        if _is_flow(rest):
            return None, max(0, _depth(rest))
        if rest != '' and rest[0] != '#':
            entry['value'] = _scalar(rest)

        return None, 0

    def _open(self, n: int, indent: int, kind: str, path: list[str]) -> None:
        start = n
        while start > 0 and _COMMENT.match(self.lines[start - 1]):
            start -= 1
        self.entries.append({'path': path, 'start': start, 'end': len(self.lines), 'value': None, 'alias': None})
        self.stack.append({'indent': indent, 'entry': len(self.entries) - 1, 'kind': kind, 'items': 0})

    def _close(self, indent: int, line: int, item: bool = False) -> None:
        """
        Ends the open nodes a line at this indent closes: deeper ones, and those at the same
        indent, except that a key keeps the sequence written under it at its own indent.
        """
        while len(self.stack) > 1:
            top = self.stack[-1]
            if top['indent'] < indent or (top['indent'] == indent and item and top['kind'] == 'key'):
                break
            self.stack.pop()
            entry = self.entries[top['entry']]
            end = line
            while end > entry['start'] + 1 and _EMPTY.match(self.lines[end - 1]):
                end -= 1
            entry['end'] = end

    def _remember(self, name: str | None) -> None:
        if name is not None:
            self.anchors[name] = self.entries[self.stack[-1]['entry']]['path']


def _split_anchor(source: str) -> tuple[str | None, str]:
    match = _ANCHOR.match(source)

    return (match.group(1), match.group(2)) if match else (None, source)


def _is_flow(source: str) -> bool:
    return source != '' and source[0] in '[{'


def _depth(source: str) -> int:
    """Opening minus closing brackets outside quotes, stopping at a `#` that starts a comment."""
    depth = 0
    quote: str | None = None
    for i, char in enumerate(source):
        if quote is not None:
            quote = None if char == quote else quote
        elif char in '"\'':
            quote = char
        elif char in '[{':
            depth += 1
        elif char in ']}':
            depth -= 1
        elif char == '#' and (i == 0 or source[i - 1] == ' '):
            break

    return depth


def _scalar(source: str) -> str:
    """A scalar's text: quotes removed, or a plain scalar without its trailing comment."""
    double = _DOUBLE.match(source)
    if double:
        return _unescape(double.group('double'))
    single = _SINGLE.match(source)
    if single:
        return single.group('single').replace("''", "'")

    return compat.trim(_TRAILING_COMMENT.sub('', source, count=1))


def _unescape(source: str) -> str:
    """`\\n` and `\\t` are a newline and a tab; a backslash before any other character stands for it."""
    return _ESCAPE.sub(lambda match: {'n': '\n', 't': '\t'}.get(match.group(1), match.group(1)), source)
