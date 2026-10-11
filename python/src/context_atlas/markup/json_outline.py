"""
The key tree of a JSON file, printed in one fixed layout so that minified and pretty-printed
files cut the same way: two-space indents, `"key": value`, an empty object or array as `{}` or
`[]`. Strings and numbers are printed as written in the source, so no language reformats a
number. Parsing works on the UTF-8 bytes, so an error names the same byte offset as in PHP.
"""
from __future__ import annotations

import json
import re
from typing import Any, NoReturn

from ..errors import ContextAtlasError

_SCALAR = re.compile(
    rb'"(?:[^"\\\x00-\x1f]|\\(?:["\\/bfnrt]|u[0-9a-fA-F]{4}))*"'
    rb'|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|true|false|null'
)
_SPACE = re.compile(rb'[ \t\r\n]*')


def read(source: str) -> dict[str, Any]:
    """`{lines, entries, anchors}` as the YAML reader gives them; anchors are always empty."""
    reader = _Reader(source.encode())
    reader.space()
    reader.value([], 0, b'')
    reader.space()
    if reader.at != len(reader.text):
        reader.fail()

    return {'lines': [line.decode() for line in reader.lines], 'entries': reader.entries, 'anchors': {}}


class _Reader:
    def __init__(self, data: bytes) -> None:
        self.text = data
        self.at = 0
        self.lines: list[bytes] = []
        self.entries: list[dict[str, Any]] = []

    def value(self, path: list[str], depth: int, lead: bytes) -> None:
        """Prints one value on a new line after `lead` and records its entry."""
        entry: dict[str, Any] = {'path': path, 'start': len(self.lines), 'end': 0, 'value': None, 'alias': None}
        self.entries.append(entry)
        pad = b'  ' * depth
        char = self.text[self.at : self.at + 1]

        if char in (b'{', b'['):
            close = b'}' if char == b'{' else b']'
            self.at += 1
            self.space()
            if self.text[self.at : self.at + 1] == close:
                self.at += 1
                self.lines.append(pad + lead + char + close)
            else:
                self.lines.append(pad + lead + char)
                self._members(path, depth + 1, char == b'{')
                self.lines.append(pad + close)
        else:
            raw = self._scalar()
            self.lines.append(pad + lead + raw)
            entry['value'] = _decoded(raw) if raw[:1] == b'"' else raw.decode()

        entry['end'] = len(self.lines)

    def _members(self, path: list[str], depth: int, is_object: bool) -> None:
        close = b'}' if is_object else b']'
        i = 0
        while True:
            self.space()
            lead = b''
            segment = f'[{i}]'
            if is_object:
                key = self._scalar()
                if key[:1] != b'"':
                    self.fail()
                segment = _decoded(key)
                lead = key + b': '
                self.space()
                self._expect(b':')
                self.space()
            self.value([*path, segment], depth, lead)
            self.space()
            if self.text[self.at : self.at + 1] != b',':
                self._expect(close)
                return
            self.lines[-1] += b','
            self.at += 1
            i += 1

    def _scalar(self) -> bytes:
        match = _SCALAR.match(self.text, self.at)
        if match is None:
            self.fail()
        self.at = match.end()

        return match.group(0)

    def space(self) -> None:
        self.at = _SPACE.match(self.text, self.at).end()

    def _expect(self, char: bytes) -> None:
        if self.text[self.at : self.at + 1] != char:
            self.fail()
        self.at += 1

    def fail(self) -> NoReturn:
        """:raises ContextAtlasError: naming the byte offset"""
        raise ContextAtlasError(f'Invalid JSON at byte {self.at}.')


def _decoded(raw: bytes) -> str:
    """A JSON string's text, or `''` where PHP's json_decode refuses it (an unpaired surrogate)."""
    found = json.loads(raw)
    try:
        found.encode()
    except UnicodeEncodeError:
        return ''

    return found
