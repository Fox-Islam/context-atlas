"""
The key tree of a TOML file with the line span of every node, read line by line from the original
text, with no TOML library, so every package reads a file the same way.

Read: `[a.b]` tables, `[[a]]` array-of-tables items (path `a` then `[i]`), `key = value` with
dotted and quoted keys. Only headers and keys are nodes: `[tool.black]` is one node at path
`tool > black`, with no node for `tool`, and the subtrees hang it under the nearest ancestor path
that has one. Strings are unescaped; arrays and inline tables are one node with no value and no
children, and their lines, like those of a multi-line string, belong to their key. Comment lines
directly above a node belong to it; comment and blank lines after it do not.

The reader works on the UTF-8 bytes of each line, as the PHP package does; every cut falls at an
ASCII delimiter, so values decode whole.
"""
from __future__ import annotations

import re
from typing import Any

# Quoted strings are unrolled (`[^"\\]*(?:\\.[^"\\]*)*`), which matches what PHP's possessive
# form matches without backtracking on a long value.
_SEGMENT = rb'(?:[A-Za-z0-9_-]+|"[^"\\]*(?:\\.[^"\\]*)*"|\'[^\']*\')'
_KEY = _SEGMENT + rb'(?:[ \t]*\.[ \t]*' + _SEGMENT + rb')*'
_ITEM_HEADER = re.compile(rb'^\[\[[ \t]*(' + _KEY + rb')[ \t]*\]\][ \t]*(?:#.*)?$')
_TABLE_HEADER = re.compile(rb'^\[[ \t]*(' + _KEY + rb')[ \t]*\][ \t]*(?:#.*)?$')
_ASSIGNMENT = re.compile(rb'^(' + _KEY + rb')[ \t]*=[ \t]*(.*)$')
_SEGMENTS = re.compile(_SEGMENT)
_COMMENT = re.compile(rb'^\s*#', re.ASCII)
_EMPTY = re.compile(rb'^\s*(#.*)?$', re.ASCII)
_BASIC = re.compile(rb'^"([^"\\]*(?:\\.[^"\\]*)*)"$')
_LITERAL = re.compile(rb"^'([^']*)'$")
_ESCAPE = re.compile(rb'\\(?:[ \t]*\n[ \t\n]*|u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|.)', re.DOTALL)
_SIMPLE = {b'b': b'\x08', b't': b'\t', b'n': b'\n', b'f': b'\x0c', b'r': b'\r', b'"': b'"', b'\\': b'\\'}


def read(source: str) -> dict[str, Any]:
    """`{lines, entries, anchors}` as the other outlines give them; anchors are always empty."""
    data = source.encode().replace(b'\r\n', b'\n').replace(b'\r', b'\n')
    reader = _Reader(data.split(b'\n'))
    reader.parse()

    return {'lines': [line.decode() for line in reader.lines], 'entries': reader.entries, 'anchors': {}}


class _Reader:
    def __init__(self, lines: list[bytes]) -> None:
        self.lines = lines
        self.entries: list[dict[str, Any]] = [{'path': [], 'start': 0, 'end': len(lines), 'value': None, 'alias': None}]
        self.stack: list[int] = [0]
        self.table: list[str] = []
        self.arrays: dict[str, int] = {}

    def parse(self) -> None:
        count = len(self.lines)
        n = 0
        while n < count:
            content = self.lines[n].strip(b' \t')
            if content == b'' or content[:1] == b'#':
                n += 1
                continue
            item = _ITEM_HEADER.match(content)
            table = None if item else _TABLE_HEADER.match(content)
            assignment = None if item or table else _ASSIGNMENT.match(content)
            if item:
                self._header(n, _keys(item.group(1)), item=True)
            elif table:
                self._header(n, _keys(table.group(1)), item=False)
            elif assignment:
                self._open(n, [*self.table, *_keys(assignment.group(1))])
                line = self.lines[n]
                column = len(line) - len(line.lstrip(b' \t')) + len(content) - len(assignment.group(2))
                n = self._value(n, column)
            n += 1
        self._close([], count, everything=True)

    def _header(self, n: int, keys: list[str], item: bool) -> None:
        path: list[str] = []
        for i, key in enumerate(keys):
            path.append(key)
            index = _key(path)
            if i < len(keys) - 1 and index in self.arrays:
                path.append(f'[{self.arrays[index] - 1}]')
        if item:
            index = _key(path)
            self.arrays[index] = self.arrays.get(index, 0) + 1
            path.append(f'[{self.arrays[index] - 1}]')
        self.table = path
        self._open(n, path)

    def _open(self, n: int, path: list[str]) -> None:
        """Closes the open nodes whose path does not lead to `path`, then opens its node."""
        self._close(path, n)
        start = n
        while start > 0 and _COMMENT.match(self.lines[start - 1]):
            start -= 1
        self.entries.append({'path': path, 'start': start, 'end': len(self.lines), 'value': None, 'alias': None})
        self.stack.append(len(self.entries) - 1)

    def _close(self, path: list[str], line: int, everything: bool = False) -> None:
        """
        Ends the open nodes whose path is not a proper prefix of `path` (all of them with
        `everything`), at `line` moved up over trailing blank and comment lines but never to the
        node's own first line.
        """
        while len(self.stack) > 1:
            top = self.entries[self.stack[-1]]
            if not everything and len(top['path']) < len(path) and path[: len(top['path'])] == top['path']:
                break
            self.stack.pop()
            end = line
            while end > top['start'] + 1 and _EMPTY.match(self.lines[end - 1]):
                end -= 1
            top['end'] = end

    def _value(self, n: int, column: int) -> int:
        """Reads the value starting at byte `column` of line `n` into the top node; returns the line it ends on."""
        line = self.lines[n]
        rest = line[column:]
        entry = self.entries[self.stack[-1]]
        first = rest[:1]

        if first in (b'"', b"'", b'[', b'{'):
            end_line, end_column = self._extent(n, column)
            if first in (b'[', b'{'):
                return end_line
            if end_line == n:
                raw = line[column:end_column]
            else:
                last = self.lines[end_line] if end_line < len(self.lines) else b''
                raw = b'\n'.join([line[column:], *self.lines[n + 1 : end_line], last[:end_column]])
            found = _string(raw)
            entry['value'] = None if found is None else found.decode()
            return end_line

        hash_at = rest.find(b'#')
        plain = (rest if hash_at == -1 else rest[:hash_at]).strip(b' \t')
        entry['value'] = None if plain == b'' else plain.decode()

        return n

    def _extent(self, n: int, column: int) -> tuple[int, int]:
        """
        Where the string, array or inline table starting at byte `column` of line `n` ends: the
        line and the byte after its closing delimiter.
        """
        depth = 0
        mode = b''
        count = len(self.lines)
        for number in range(n, count):
            text = self.lines[number]
            length = len(text)
            i = column if number == n else 0
            while i < length:
                char = text[i : i + 1]
                if mode == b'' and char == b'#':
                    break
                if mode == b'':
                    if char in (b'"', b"'"):
                        mode = char * 3 if text.startswith(char * 3, i) else char
                        i += len(mode) - 1
                    elif char in (b'[', b'{'):
                        depth += 1
                    elif char in (b']', b'}'):
                        depth -= 1
                elif char == b'\\' and mode in (b'"', b'"""'):
                    i += 1
                elif char == mode[:1]:
                    run = len(text[i:]) - len(text[i:].lstrip(char))
                    if len(mode) == 1 or run >= 3:
                        i += (1 if len(mode) == 1 else min(run, 5)) - 1
                        mode = b''
                    else:
                        i += run - 1
                if mode == b'' and depth <= 0:
                    return number, i + 1
                i += 1
            if mode in (b'"', b"'"):
                mode = b''
                if depth <= 0:
                    return number, length

        return count - 1, len(self.lines[count - 1])


def _string(raw: bytes) -> bytes | None:
    """A string's text: a literal one as written, a basic one unescaped; a multi-line one without its first newline."""
    for delimiter in (b'"""', b"'''"):
        if raw.startswith(delimiter):
            if len(raw) < 6 or not raw.endswith(delimiter):
                return None
            body = raw[3:-3]
            body = body[1:] if body.startswith(b'\n') else body
            return body if delimiter == b"'''" else _unescape(body)
    basic = _BASIC.match(raw)
    if basic:
        return _unescape(basic.group(1))
    literal = _LITERAL.match(raw)

    return literal.group(1) if literal else None


def _unescape(data: bytes) -> bytes:
    """
    Basic-string escapes: `\\b \\t \\n \\f \\r \\" \\\\`, `\\uXXXX` and `\\UXXXXXXXX`, and a backslash at
    the end of a line, which removes itself and the whitespace and newlines after it. Any other
    escape, and one naming no Unicode scalar value, stays as written.
    """
    def replace(match: re.Match[bytes]) -> bytes:
        found = match.group(0)
        code = found[1:2]
        if b'\n' in found:
            return b''
        if code in (b'u', b'U'):
            point = int(found[2:], 16)
            return found if point > 0x10FFFF or 0xD800 <= point <= 0xDFFF else chr(point).encode()
        return _SIMPLE.get(code, found)

    return _ESCAPE.sub(replace, data)


def _keys(key: bytes) -> list[str]:
    """A dotted key's segments: bare, or quoted (basic unescaped, literal as written)."""
    segments = []
    for segment in _SEGMENTS.findall(key):
        if segment[:1] == b'"':
            segments.append(_unescape(segment[1:-1]).decode())
        elif segment[:1] == b"'":
            segments.append(segment[1:-1].decode())
        else:
            segments.append(segment.decode())

    return segments


def _key(path: list[str]) -> str:
    return '\x1f'.join(path)
