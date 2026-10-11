"""
The element tree of an XML file, read by its own tokenizer (no XML library, so every package
reads a file the same way) and printed in one fixed layout so that minified and pretty-printed
files cut the same way: one element per line, a text-only element on one line, children indented
two spaces, text with its whitespace collapsed.

Paths: an element's segment is its tag, or `tag[i]` among same-name siblings; an attribute is a
child `@name` whose span is empty. An `id` or `xml:id` attribute anchors its element, and a
`ref`, `idref`, `href="#x"` or `xlink:href="#x"` attribute is an alias of that anchor. The
prolog, comments, processing instructions and the doctype are not printed.

The tokenizer works on the UTF-8 bytes, so an error names the same byte offset as in PHP.
"""
from __future__ import annotations

import re
from typing import Any, NoReturn

from ..errors import ContextAtlasError

LABELS = ['@name', 'name', '@id', 'id', '@title', 'title', '@key', 'key']

_NAME = re.compile(rb'[A-Za-z_:\x80-\xff][A-Za-z0-9_:.\x80-\xff-]*')
_SPACE = re.compile(rb'[ \t\r\n]*')
_ENTITY = re.compile(rb'&(lt|gt|amp|quot|apos|#[0-9]+|#x[0-9A-Fa-f]+);')
_RUN = re.compile(rb'[ \t\r\n]+')
_NAMED = {b'lt': b'<', b'gt': b'>', b'amp': b'&', b'quot': b'"', b'apos': b"'"}


def read(source: str) -> dict[str, Any]:
    """
    `{lines, entries, anchors, labels}`; `labels` are the fields an item segment is named by.

    :raises ContextAtlasError: naming the byte offset of invalid XML
    """
    reader = _Reader(source.encode())
    if reader.text.startswith(b'\xef\xbb\xbf'):
        reader.at = 3
    reader.misc(prolog=True)
    if reader.text[reader.at : reader.at + 1] != b'<':
        reader.fail()
    root = reader.element()
    reader.misc(prolog=False)
    if reader.at != len(reader.text):
        reader.fail()

    reader.entries.append({'path': [], 'start': 0, 'end': 0, 'value': None, 'alias': None})
    reader.print(root, [root['tag'].decode()], 0)
    reader.entries[0] = {'path': [], 'start': 0, 'end': len(reader.lines), 'value': None, 'alias': None}

    return {
        'lines': [line.decode() for line in reader.lines],
        'entries': reader.entries,
        'anchors': reader.anchors,
        'labels': LABELS,
    }


class _Reader:
    def __init__(self, data: bytes) -> None:
        self.text = data
        self.at = 0
        self.lines: list[bytes] = []
        self.entries: list[dict[str, Any]] = []
        self.anchors: dict[str, list[str]] = {}

    def misc(self, prolog: bool) -> None:
        """Skips whitespace, comments and processing instructions, and a doctype before the root."""
        while True:
            self._space()
            if self._at(b'<?'):
                self._skip_past(b'?>')
            elif self._at(b'<!--'):
                self._skip_past(b'-->', 4)
            elif prolog and self._at(b'<!DOCTYPE'):
                self._doctype()
            else:
                return

    def element(self) -> dict[str, Any]:
        """Reads the element whose `<` is here, and its content."""
        self.at += 1
        tag = self._name()
        attributes: list[tuple[bytes, bytes]] = []
        while True:
            before = self.at
            self._space()
            if self._at(b'/>'):
                self.at += 2
                return {'tag': tag, 'attributes': attributes, 'content': []}
            if self.text[self.at : self.at + 1] == b'>':
                self.at += 1
                return {'tag': tag, 'attributes': attributes, 'content': self._content(tag)}
            if self.at == before:
                self.fail()
            attributes.append(self._attribute())

    def print(self, node: dict[str, Any], path: list[str], depth: int) -> None:
        """Prints an element and records its entry, its attributes' entries, then its children's."""
        index = len(self.entries)
        start = len(self.lines)
        self.entries.append({'path': path, 'start': start, 'end': 0, 'value': None, 'alias': None})
        pad = b'  ' * depth
        opening = b'<' + node['tag']
        for name, value in node['attributes']:
            opening += b' ' + name + b'="' + value.replace(b'"', b'&quot;') + b'"'
            self._attribute_entry(path, start, name, value)

        texts = [item for item in node['content'] if isinstance(item, bytes)]
        has_children = any(isinstance(item, dict) for item in node['content'])
        if not node['content']:
            self.lines.append(pad + opening + b'/>')
        elif not has_children:
            self.lines.append(pad + opening + b'>' + b' '.join(texts) + b'</' + node['tag'] + b'>')
        else:
            self.lines.append(pad + opening + b'>')
            self._print_content(node['content'], path, depth + 1)
            self.lines.append(pad + b'</' + node['tag'] + b'>')
        self.entries[index] = {
            'path': path,
            'start': start,
            'end': len(self.lines),
            'value': b' '.join(texts).decode() if texts else None,
            'alias': None,
        }

    def fail(self) -> NoReturn:
        """:raises ContextAtlasError: naming the byte offset"""
        raise ContextAtlasError(f'Invalid XML at byte {self.at}.')

    def _print_content(self, content: list[Any], path: list[str], depth: int) -> None:
        counts: dict[bytes, int] = {}
        for item in content:
            if isinstance(item, dict):
                counts[item['tag']] = counts.get(item['tag'], 0) + 1
        seen: dict[bytes, int] = {}
        for item in content:
            if isinstance(item, bytes):
                self.lines.append(b'  ' * depth + item)
                continue
            tag = item['tag']
            segment = tag.decode()
            if counts[tag] > 1:
                seen[tag] = seen.get(tag, -1) + 1
                segment = f'{segment}[{seen[tag]}]'
            self.print(item, [*path, segment], depth)

    def _attribute_entry(self, path: list[str], line: int, name: bytes, value: bytes) -> None:
        alias = None
        if value != b'':
            if name in (b'ref', b'idref'):
                alias = value.decode()
            elif name in (b'href', b'xlink:href') and len(value) > 1 and value[:1] == b'#':
                alias = value[1:].decode()
        self.entries.append({
            'path': [*path, '@' + name.decode()],
            'start': line,
            'end': line,
            'value': value.decode(),
            'alias': alias,
        })
        if name in (b'id', b'xml:id') and value != b'':
            self.anchors[value.decode()] = path

    def _skip_past(self, end: bytes, start_from: int = 2) -> int:
        """Moves past the first `end` after the construct starting here; returns where its body starts."""
        close = self.text.find(end, self.at + start_from)
        if close == -1:
            self.fail()
        start = self.at + start_from
        self.at = close + len(end)

        return start

    def _doctype(self) -> None:
        """
        Skips a doctype: up to the `>` outside quotes and outside its `[...]` internal subset,
        where comments are skipped whole.
        """
        depth = 0
        i = self.at + 9
        while i < len(self.text):
            char = self.text[i : i + 1]
            if char in (b'"', b"'"):
                close = self.text.find(char, i + 1)
                if close == -1:
                    break
                i = close
            elif depth > 0 and self.text.startswith(b'<!--', i):
                close = self.text.find(b'-->', i + 4)
                if close == -1:
                    break
                i = close + 2
            elif char == b'[':
                depth += 1
            elif char == b']':
                depth -= 1
            elif char == b'>' and depth <= 0:
                self.at = i + 1
                return
            i += 1
        self.fail()

    def _attribute(self) -> tuple[bytes, bytes]:
        name = self._name()
        self._space()
        if self.text[self.at : self.at + 1] != b'=':
            self.fail()
        self.at += 1
        self._space()
        quote = self.text[self.at : self.at + 1]
        if quote not in (b'"', b"'"):
            self.fail()
        close = self.text.find(quote, self.at + 1)
        if close == -1:
            self.fail()
        value = self.text[self.at + 1 : close]
        self.at = close + 1

        return name, _collapse(_decode(value))

    def _content(self, tag: bytes) -> list[Any]:
        """
        An element's content up to its end tag: child elements and runs of text, a run being the
        character data (entities decoded, CDATA as written) between two tags.
        """
        content: list[Any] = []
        run = b''
        while True:
            following = self.text.find(b'<', self.at)
            if following == -1:
                self.at = len(self.text)
                self.fail()
            run += _decode(self.text[self.at : following])
            self.at = following
            if self._at(b'<!--'):
                self._skip_past(b'-->', 4)
            elif self._at(b'<![CDATA['):
                start = self._skip_past(b']]>', 9)
                run += self.text[start : self.at - 3]
            elif self._at(b'<?'):
                self._skip_past(b'?>')
            elif self._at(b'<!'):
                self.fail()
            else:
                collapsed = _collapse(run)
                if collapsed != b'':
                    content.append(collapsed)
                run = b''
                if self._at(b'</'):
                    self._end_tag(tag)
                    return content
                content.append(self.element())

    def _end_tag(self, tag: bytes) -> None:
        start = self.at
        self.at += 2
        match = _NAME.match(self.text, self.at)
        name = match.group(0) if match else b''
        self.at += len(name)
        self._space()
        if name != tag or self.text[self.at : self.at + 1] != b'>':
            self.at = start
            self.fail()
        self.at += 1

    def _name(self) -> bytes:
        match = _NAME.match(self.text, self.at)
        if match is None:
            self.fail()
        self.at = match.end()

        return match.group(0)

    def _space(self) -> None:
        self.at = _SPACE.match(self.text, self.at).end()

    def _at(self, prefix: bytes) -> bool:
        return self.text.startswith(prefix, self.at)


def _decode(data: bytes) -> bytes:
    """
    The five predefined entities and numeric references; any other `&` stays as written, as does
    a reference to a code point that is not a Unicode scalar value.
    """
    if b'&' not in data:
        return data

    def replace(match: re.Match[bytes]) -> bytes:
        body = match.group(1)
        if body in _NAMED:
            return _NAMED[body]
        hexadecimal = body[1:2] == b'x'
        digits = body[2 if hexadecimal else 1 :].lstrip(b'0')
        code = -1 if len(digits) > 7 else int(digits or b'0', 16 if hexadecimal else 10)
        if code < 1 or code > 0x10FFFF or 0xD800 <= code <= 0xDFFF:
            return match.group(0)
        return chr(code).encode()

    return _ENTITY.sub(replace, data)


def _collapse(data: bytes) -> bytes:
    return _RUN.sub(b' ', data).strip(b' ')
