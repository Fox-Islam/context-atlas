"""
JSON for the chat reader, scanned by hand: objects decode to JsonMap, numbers to JsonNumber (as
written), strings to their text with an unpaired surrogate escape as U+FFFD. Errors name the
UTF-8 byte offset where reading stopped. The rules are stated in spec/formats/chat.md.
"""
from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Any, NoReturn

from ...errors import ContextAtlasError

MAX_DEPTH = 512

_DROPPED = ('encrypted_content', 'signature')

_SPECIAL = re.compile('["\\\\\x00-\x1f]')

_SIMPLE = {'"': '"', '\\': '\\', '/': '/', 'b': '\x08', 'f': '\x0c', 'n': '\n', 'r': '\r', 't': '\t'}

_ENCODED = {'"': '\\"', '\\': '\\\\', '\x08': '\\b', '\x0c': '\\f', '\n': '\\n', '\r': '\\r', '\t': '\\t'}

_HEX = frozenset('0123456789abcdefABCDEF')

_DIGITS = frozenset('0123456789')

_SPACE = frozenset(' \t\r\n')


@dataclass(frozen=True)
class JsonNumber:
    """A JSON number kept as written, so re-serialising it never reformats it."""

    raw: str


@dataclass(frozen=True)
class JsonMap:
    """A decoded JSON object: its members in source order, a repeated key keeping its first place and its last value."""

    members: dict[str, Any] = field(default_factory=dict)

    def get(self, key: str) -> Any:
        return self.members.get(key)

    def has(self, key: str) -> bool:
        return key in self.members

    def string(self, key: str) -> str | None:
        """The member when it is a non-empty string, else None."""
        value = self.members.get(key)

        return value if isinstance(value, str) and value != '' else None

    def map(self, key: str) -> JsonMap | None:
        value = self.members.get(key)

        return value if isinstance(value, JsonMap) else None

    def list(self, key: str) -> list[Any] | None:
        value = self.members.get(key)

        return value if isinstance(value, list) else None


def decode(source: str) -> Any:
    """:raises ContextAtlasError: `Invalid JSON at byte <b>.`"""
    scanner = _Scanner(source)
    scanner.space()
    value = scanner.value()
    scanner.space()
    if scanner.at != len(source):
        scanner.fail()

    return value


def valid(source: str) -> bool:
    """Whether the text is one JSON value."""
    try:
        decode(source)
    except ContextAtlasError:
        return False

    return True


def encode(value: Any) -> str:
    """
    Compact JSON: no whitespace, members in order, numbers as written, members named
    `encrypted_content` or `signature` left out, strings escaping only `"`, `\\` and controls.
    """
    if isinstance(value, JsonMap):
        members = [string(key) + ':' + encode(member) for key, member in value.members.items() if key not in _DROPPED]
        return '{' + ','.join(members) + '}'
    if isinstance(value, list):
        return '[' + ','.join(encode(item) for item in value) + ']'
    if isinstance(value, str):
        return string(value)
    if isinstance(value, JsonNumber):
        return value.raw
    if value is True:
        return 'true'
    if value is False:
        return 'false'

    return 'null'


def string(source: str) -> str:
    out = ['"']
    start = 0
    for match in _SPECIAL.finditer(source):
        char = match.group()
        out.append(source[start : match.start()])
        out.append(_ENCODED.get(char, f'\\u{ord(char):04x}'))
        start = match.end()
    out.append(source[start:])

    return ''.join(out) + '"'


class _Scanner:
    def __init__(self, source: str) -> None:
        self.text = source
        self.at = 0

    def value(self) -> Any:
        """
        The value at the cursor, read with an explicit stack of open objects and arrays so a
        nesting of MAX_DEPTH levels needs no recursion.
        """
        stack: list[tuple[dict[str, Any] | list[Any], str]] = []
        while True:
            char = self._char(self.at)
            if char in ('{', '['):
                if len(stack) + 1 > MAX_DEPTH:
                    self.fail()
                opened = self._open(char)
                if opened is None:
                    value: Any = JsonMap({}) if char == '{' else []
                else:
                    stack.append(opened)
                    continue
            elif char == '"':
                value = self._string()
            else:
                value = self._literal()

            while True:
                if not stack:
                    return value
                container, key = stack[-1]
                if isinstance(container, dict):
                    container[key] = value
                else:
                    container.append(value)
                self.space()
                if self._char(self.at) == ',':
                    self.at += 1
                    stack[-1] = (container, self._key() if isinstance(container, dict) else '')
                    if isinstance(container, list):
                        self.space()
                    break
                self._expect('}' if isinstance(container, dict) else ']')
                stack.pop()
                value = JsonMap(container) if isinstance(container, dict) else container

    def _open(self, char: str) -> tuple[dict[str, Any] | list[Any], str] | None:
        """The container a bracket opens with the key of its first member, or None when it is empty."""
        self.at += 1
        self.space()
        if self._char(self.at) == ('}' if char == '{' else ']'):
            self.at += 1
            return None
        if char == '{':
            return {}, self._key()
        self.space()

        return [], ''

    def _key(self) -> str:
        """A member's key and its `:`, the cursor left on the value."""
        self.space()
        if self._char(self.at) != '"':
            self.fail()
        key = self._string()
        self.space()
        self._expect(':')
        self.space()

        return key

    def _literal(self) -> Any:
        start = self.at
        for word, value in (('true', True), ('false', False), ('null', None)):
            if self.text.startswith(word, start):
                self.at += len(word)
                return value
        end = self._number_end(start)
        if end is None:
            self.fail()
        self.at = end

        return JsonNumber(self.text[start:end])

    def _string(self) -> str:
        """The decoded string opening at the cursor; the cursor moves past its closing quote."""
        start = self.at
        length = len(self.text)
        i = start + 1
        out: list[str] = []
        while i < length:
            match = _SPECIAL.search(self.text, i)
            stop = match.start() if match is not None else length
            out.append(self.text[i:stop])
            i = stop
            char = self._char(i)
            if char == '"':
                self.at = i + 1
                return ''.join(out)
            if char != '\\':
                break
            escape = self._escape(i)
            if escape is None:
                break
            decoded, i = escape
            out.append(decoded)
        self.at = start

        return self.fail()

    def _escape(self, at: int) -> tuple[str, int] | None:
        """The text of the escape at `at` and the offset after it, or None when it is not one."""
        following = self._char(at + 1)
        if following in _SIMPLE:
            return _SIMPLE[following], at + 2
        code = self._hex(at)
        if code is None:
            return None
        if 0xD800 <= code <= 0xDBFF:
            low = self._hex(at + 6)
            if low is not None and 0xDC00 <= low <= 0xDFFF:
                return chr(0x10000 + ((code - 0xD800) << 10) + (low - 0xDC00)), at + 12

        return ('�' if 0xD800 <= code <= 0xDFFF else chr(code)), at + 6

    def _hex(self, at: int) -> int | None:
        """The code unit of the `\\uXXXX` escape at `at`, or None when there is none."""
        digits = self.text[at + 2 : at + 6]
        if self._char(at) != '\\' or self._char(at + 1) != 'u' or len(digits) != 4 or not set(digits) <= _HEX:
            return None

        return int(digits, 16)

    def _number_end(self, at: int) -> int | None:
        i = at + (1 if self._char(at) == '-' else 0)
        first = self._char(i)
        if first == '0':
            i += 1
        elif first != '' and '1' <= first <= '9':
            i = self._digits(i)
        else:
            return None
        if self._char(i) == '.' and self._digits(i + 1) > i + 1:
            i = self._digits(i + 1)
        if self._char(i) in ('e', 'E'):
            sign = 1 if self._char(i + 1) in ('+', '-') else 0
            end = self._digits(i + 1 + sign)
            if end > i + 1 + sign:
                i = end

        return i

    def _digits(self, at: int) -> int:
        """The offset after the run of ASCII digits starting at `at`."""
        while at < len(self.text) and self.text[at] in _DIGITS:
            at += 1

        return at

    def _char(self, at: int) -> str:
        return self.text[at] if 0 <= at < len(self.text) else ''

    def space(self) -> None:
        while self.at < len(self.text) and self.text[self.at] in _SPACE:
            self.at += 1

    def _expect(self, char: str) -> None:
        if self._char(self.at) != char:
            self.fail()
        self.at += 1

    def fail(self) -> NoReturn:
        offset = len(self.text[: self.at].encode('utf-8', 'surrogatepass'))
        raise ContextAtlasError(f'Invalid JSON at byte {offset}.')
