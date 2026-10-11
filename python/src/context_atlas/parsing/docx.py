"""
Reads a Word document (.docx): the body of the main document part in order, with headings, lists
and code from paragraph styles and numbering, tables, text boxes where they appear, and footnotes
and endnotes appended. Comments, headers, footers and images are not read. The rules are stated
in spec/formats/docx.md.

DOCX is binary, so the reader takes a path or the file's bytes, never text.
"""
from __future__ import annotations

import io
import re
import zipfile
import zlib
from collections.abc import Callable
from pathlib import Path
from typing import Any
from xml.etree import ElementTree
from xml.parsers import expat

from .. import text
from ..blocks import Block
from ..errors import ContextAtlasError
from .block_builder import BlockBuilder

LIMIT = 50 * 1024 * 1024

W = ('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'http://purl.oclc.org/ooxml/wordprocessingml/main')

MC = 'http://schemas.openxmlformats.org/markup-compatibility/2006'

MATH = ('http://schemas.openxmlformats.org/officeDocument/2006/math', 'http://purl.oclc.org/ooxml/officeDocument/math')

DC = 'http://purl.org/dc/elements/1.1/'

REL = 'http://schemas.openxmlformats.org/package/2006/relationships'

CHAIN = 20

# Elements nested deeper than this are refused before parsing; a parser building them uses
# memory out of all proportion to the part's size.
MAX_DEPTH = 1000

_TAG_STOP = re.compile(rb'["\'>]')

_SEPARATORS = ('separator', 'continuationSeparator', 'continuationNotice')

_DEFAULTS = {
    'styles': 'word/styles.xml',
    'numbering': 'word/numbering.xml',
    'footnotes': 'word/footnotes.xml',
    'endnotes': 'word/endnotes.xml',
}

# The spec's regexes are ASCII-only and run without a Unicode flag. Those anchored at the end
# use `\Z`: `$` would also accept a trailing newline, which PHP's `/D` patterns refuse.
_DOCTYPE = re.compile(rb'<!DOCTYPE', re.IGNORECASE)
_DECLARATION = re.compile(rb'^[ \t\n\r\x0b\x0c]*<\?xml[^>]*\?>')
_INTEGER = re.compile(r'^-?[0-9]{1,9}\Z')
_TOC = re.compile(r'^\s*TOC(?![A-Za-z])', re.IGNORECASE | re.ASCII)
_HEADING = re.compile(r'^heading ([1-9])\Z')
_CODE = re.compile(r'code|source|preformatted')
_HEX = re.compile(r'^[0-9A-Fa-f]{1,6}\Z')
_TEMPLATE = re.compile(r'%([1-9])')
_SYMBOL_FONTS = re.compile(r'symbol|wingdings|webdings')

Element = ElementTree.Element
Item = dict[str, Any]


def parse(source: str | Path | bytes, name: str | None = None) -> list[Block]:
    """
    The blocks of a .docx file, given as a path or as its bytes; `name` names it in error
    messages (the path's base name by default, `document.docx` for bytes).

    :raises ContextAtlasError: for a file that is not a DOCX package, or a part that is too
        large, declares a DOCTYPE or is not well-formed XML
    """
    parts = _open(source, name)

    return _Reader(parts).read()


def _open(source: str | Path | bytes, name: str | None) -> dict[str, Element | None]:
    if isinstance(source, bytes):
        label = name or 'document.docx'
        handle: Any = io.BytesIO(source)
    else:
        label = name or Path(source).name
        if not Path(source).is_file():
            raise ContextAtlasError(f'Cannot open {label} as a DOCX document.')
        handle = source
    try:
        archive = zipfile.ZipFile(handle)
    except (OSError, zipfile.BadZipFile, ValueError) as error:
        raise ContextAtlasError(f'Cannot open {label} as a DOCX document.') from error

    with archive:
        return _Package(archive, label).parts()


class _Package:
    """The parts the reader uses, found through the package relationships and parsed as XML."""

    def __init__(self, archive: zipfile.ZipFile, name: str) -> None:
        self.archive = archive
        self.name = name
        self.read = 0

    def parts(self) -> dict[str, Element | None]:
        root = self._relationships('_rels/.rels', '')
        main = root.get('officeDocument', 'word/document.xml')
        document = self._xml(main)
        if document is None:
            raise ContextAtlasError(f'{self.name} has no main document part ({main}).')
        slash = main.rfind('/')
        folder = '' if slash == -1 else main[: slash + 1]
        related = self._relationships(f'{folder}_rels/{main[slash + 1 :]}.rels', folder)
        parts: dict[str, Element | None] = {'document': document}
        for kind, default in _DEFAULTS.items():
            parts[kind] = self._xml(related.get(kind, default))
        parts['core'] = self._xml(root.get('core-properties', 'docProps/core.xml'))

        return parts

    def _relationships(self, part: str, folder: str) -> dict[str, str]:
        """Relationship type (the last segment of its Type) to part path, for internal targets."""
        rels = self._xml(part)
        targets: dict[str, str] = {}
        if rels is None:
            return targets

        for relation in (node for node in rels.iter(f'{{{REL}}}Relationship') if node is not rels):
            kind = relation.get('Type', '')
            kind = kind[kind.rfind('/') + 1 :]
            if relation.get('TargetMode', '') == 'External' or kind in targets:
                continue
            target = relation.get('Target', '')
            targets[kind] = _normalise(target[1:] if target.startswith('/') else folder + target)

        return targets

    def _xml(self, part: str) -> Element | None:
        """The root element of a part, or None when the package has no such part."""
        try:
            info = self.archive.getinfo(part)
        except KeyError:
            return None
        self.read += info.file_size
        unreadable = ContextAtlasError(f'{part} in {self.name} cannot be read.')
        if info.compress_type not in (zipfile.ZIP_STORED, zipfile.ZIP_DEFLATED):
            raise unreadable
        if self.read > LIMIT:
            raise ContextAtlasError(f'{self.name} is too large: its parts unpack to more than 50 MB.')
        try:
            with self.archive.open(info) as stream:
                data = stream.read(info.file_size + 1)
        except (OSError, zipfile.BadZipFile, EOFError, ValueError, NotImplementedError, zlib.error) as error:
            raise unreadable from error
        if len(data) != info.file_size:
            raise unreadable

        data = data.removeprefix(b'\xef\xbb\xbf')
        if _DOCTYPE.search(data):
            raise ContextAtlasError(f'{part} in {self.name} declares a DOCTYPE, which is not read.')
        data = _DECLARATION.sub(b'', data, count=1)
        if _depth(data) > MAX_DEPTH:
            raise ContextAtlasError(f'{part} in {self.name} is nested more than {MAX_DEPTH} elements deep.')
        malformed = ContextAtlasError(f'{part} in {self.name} is not well-formed XML.')
        if data.strip(b' \t\n\r\x00\x0b') == b'':
            raise malformed
        try:
            data.decode('utf-8')
            parser = ElementTree.XMLParser(encoding='utf-8')
            parser.feed(data)
            return parser.close()
        except (UnicodeDecodeError, ElementTree.ParseError, expat.ExpatError, ValueError) as error:
            raise malformed from error


def _depth(data: bytes) -> int:
    """
    The deepest element nesting, scanned from the tags without parsing, so a deeply nested part
    is refused before the parser builds it. Comments, CDATA and processing instructions are
    skipped, a `>` inside a quoted attribute value does not end a tag, a self-closing tag does
    not nest, and an unclosed tag ends the scan.
    """
    depth = deepest = 0
    length = len(data)
    at = data.find(b'<')
    while at != -1 and at < length:
        following = data[at + 1 : at + 2]
        if data.startswith(b'<!--', at):
            end = data.find(b'-->', at + 4)
            at = length if end == -1 else end + 2
        elif data.startswith(b'<![CDATA[', at):
            end = data.find(b']]>', at + 9)
            at = length if end == -1 else end + 2
        elif following == b'?':
            end = data.find(b'?>', at + 2)
            at = length if end == -1 else end + 1
        elif following == b'/':
            depth = max(0, depth - 1)
        else:
            close = _tag_end(data, at + 1)
            if close is None:
                break
            if data[close - 1 : close] != b'/':
                depth += 1
                deepest = max(deepest, depth)
            at = close
        at = data.find(b'<', at + 1)

    return deepest


def _tag_end(data: bytes, start: int) -> int | None:
    """The offset of the `>` that ends a tag, skipping any inside quoted attribute values."""
    at = start
    while True:
        match = _TAG_STOP.search(data, at)
        if match is None:
            return None
        if match.group() == b'>':
            return match.start()
        end = data.find(match.group(), match.start() + 1)
        if end == -1:
            return None
        at = end + 1


def _normalise(path: str) -> str:
    segments: list[str] = []
    for segment in path.split('/'):
        if segment == '..':
            if segments:
                segments.pop()
        elif segment not in ('.', ''):
            segments.append(segment)

    return '/'.join(segments)


def _namespace(element: Element) -> str:
    return element.tag[1:].split('}', 1)[0] if element.tag.startswith('{') else ''


def _local(element: Element) -> str:
    return element.tag.rsplit('}', 1)[-1]


def _is_w(element: Any, name: str) -> bool:
    if not isinstance(element, Element) or not isinstance(element.tag, str):
        return False

    return _local(element) == name and _namespace(element) in W


def _attribute(element: Element, name: str) -> str | None:
    """A `w:` attribute's value, in either `w:` namespace, or None."""
    for namespace in W:
        found = element.get(f'{{{namespace}}}{name}')
        if found is not None:
            return found

    return None


def _child(element: Element | None, name: str) -> Element | None:
    """The first child element `w:<name>`, or None."""
    if element is None:
        return None

    return next((node for node in element if _is_w(node, name)), None)


def _value(element: Element | None, name: str) -> str | None:
    """The `w:val` of the first child element `w:<name>`, or None."""
    found = _child(element, name)

    return None if found is None else _attribute(found, 'val')


def _integer(value: str | None) -> int | None:
    return int(value) if value is not None and _INTEGER.match(value) else None


def _content(element: Element) -> str:
    """The text of an element and its descendants, as DOM's textContent."""
    return ''.join(element.itertext())


def _elements(element: Element) -> list[Element]:
    return [node for node in element if isinstance(node.tag, str)]


class _Styles:
    """
    Paragraph styles: each style's own name, and the outline level and numbering it has or
    inherits through `w:basedOn`.
    """

    def __init__(self, root: Element | None) -> None:
        self.styles: dict[str, dict[str, Any]] = {}
        self.default: str | None = None
        for style in _elements(root) if root is not None else []:
            if not _is_w(style, 'style') or _attribute(style, 'type') != 'paragraph':
                continue
            style_id = _attribute(style, 'styleId') or ''
            if style_id in self.styles:
                continue
            properties = _child(style, 'pPr')
            numbering = _child(properties, 'numPr')
            self.styles[style_id] = {
                'name': _value(style, 'name') or '',
                'basedOn': _value(style, 'basedOn'),
                'outline': _integer(_value(properties, 'outlineLvl')),
                'numId': _value(numbering, 'numId'),
                'ilvl': _integer(_value(numbering, 'ilvl')),
            }
            if self.default is None and _attribute(style, 'default') in ('1', 'true', 'on'):
                self.default = style_id

    def resolve(self, style_id: str | None) -> str | None:
        return style_id if style_id is not None and style_id in self.styles else self.default

    def name(self, style_id: str | None) -> str:
        return '' if style_id is None else self.styles.get(style_id, {}).get('name', '')

    def inherited(self, style_id: str | None, key: str) -> Any:
        """The first value of `key` set on the style or the styles it is based on, at most 20 deep."""
        seen = 0
        while style_id is not None and style_id in self.styles and seen < CHAIN:
            found = self.styles[style_id][key]
            if found is not None:
                return found
            style_id = self.styles[style_id]['basedOn']
            seen += 1

        return None


_NO_LEVEL: dict[str, Any] = {'start': None, 'format': None, 'text': None}


class _Numbering:
    """List markers: levels, start values and templates, with counters kept per numId."""

    def __init__(self, root: Element | None) -> None:
        self.nums: dict[str, dict[str, Any]] = {}
        self.abstracts: dict[str, dict[int, dict[str, Any]]] = {}
        self.counters: dict[str, dict[int, int]] = {}
        for node in _elements(root) if root is not None else []:
            if _is_w(node, 'abstractNum'):
                self.abstracts.setdefault(_attribute(node, 'abstractNumId') or '', _levels(node))
            elif _is_w(node, 'num'):
                self.nums.setdefault(_attribute(node, 'numId') or '', _num(node))

    def has(self, num_id: str | None) -> bool:
        return num_id is not None and num_id in self.nums and self.nums[num_id]['abstract'] in self.abstracts

    def marker(self, num_id: str, ilvl: int) -> str:
        counters = dict(self.counters.get(num_id, {}))
        counters[ilvl] = counters[ilvl] + 1 if ilvl in counters else self._start(num_id, ilvl)
        counters = {level: value for level, value in counters.items() if level <= ilvl}
        self.counters[num_id] = counters

        level = self._level(num_id, ilvl)
        if level['format'] == 'bullet':
            return '-'

        def replace(match: re.Match[str]) -> str:
            k = int(match.group(1)) - 1
            found = counters[k] if k in counters else self._start(num_id, k)
            return _format(found, self._level(num_id, k)['format'])

        template = level['text'] if level['text'] is not None else f'%{ilvl + 1}.'

        return _TEMPLATE.sub(replace, template).strip(' ')

    def _start(self, num_id: str, ilvl: int) -> int:
        override = self.nums.get(num_id, {}).get('starts', {}).get(ilvl)
        if override is not None:
            return override
        start = self._level(num_id, ilvl)['start']

        return 0 if start is None else start

    def _level(self, num_id: str, ilvl: int) -> dict[str, Any]:
        num = self.nums.get(num_id)
        if num is None:
            return _NO_LEVEL
        if ilvl in num['levels']:
            return num['levels'][ilvl]

        return self.abstracts.get(num['abstract'], {}).get(ilvl, _NO_LEVEL)


def _levels(parent: Element) -> dict[int, dict[str, Any]]:
    levels: dict[int, dict[str, Any]] = {}
    for lvl in _elements(parent):
        if _is_w(lvl, 'lvl'):
            ilvl = _integer(_attribute(lvl, 'ilvl'))
            if ilvl is not None and 0 <= ilvl <= 8 and ilvl not in levels:
                levels[ilvl] = _definition(lvl)

    return levels


def _definition(lvl: Element) -> dict[str, Any]:
    return {'start': _integer(_value(lvl, 'start')), 'format': _value(lvl, 'numFmt'), 'text': _value(lvl, 'lvlText')}


def _num(num: Element) -> dict[str, Any]:
    levels: dict[int, dict[str, Any]] = {}
    starts: dict[int, int] = {}
    for override in _elements(num):
        if not _is_w(override, 'lvlOverride'):
            continue
        ilvl = _integer(_attribute(override, 'ilvl'))
        if ilvl is None or ilvl < 0 or ilvl > 8:
            continue
        start = _integer(_value(override, 'startOverride'))
        if start is not None:
            starts[ilvl] = start
        lvl = _child(override, 'lvl')
        if lvl is not None:
            levels[ilvl] = _definition(lvl)

    return {'abstract': _value(num, 'abstractNumId') or '', 'levels': levels, 'starts': starts}


def _format(n: int, kind: str | None) -> str:
    if kind in ('bullet', 'none'):
        return ''
    if kind == 'decimalZero':
        return ('0' if 0 <= n < 10 else '') + str(n)
    if kind in ('lowerLetter', 'upperLetter'):
        return str(n) if n < 1 else chr((65 if kind == 'upperLetter' else 97) + (n - 1) % 26) * ((n - 1) // 26 + 1)
    if kind in ('lowerRoman', 'upperRoman'):
        return str(n) if n < 1 or n > 3999 else _roman(n, kind == 'upperRoman')

    return str(n)


def _roman(n: int, upper: bool) -> str:
    out = ''
    for numeral, value in (
        ('m', 1000), ('cm', 900), ('d', 500), ('cd', 400), ('c', 100), ('xc', 90), ('l', 50),
        ('xl', 40), ('x', 10), ('ix', 9), ('v', 5), ('iv', 4), ('i', 1),
    ):
        while n >= value:
            out += numeral
            n -= value

    return out.upper() if upper else out


def _choice(alternate: Element) -> Element | None:
    """The first `mc:Choice` of an `mc:AlternateContent`, else its first `mc:Fallback`."""
    fallback = None
    for child in _elements(alternate):
        if _namespace(child) == MC:
            if _local(child) == 'Choice':
                return child
            if fallback is None and _local(child) == 'Fallback':
                fallback = child

    return fallback


def _is_contents(sdt: Element) -> bool:
    """Whether a content control is a table of contents."""
    return _value(_child(_child(sdt, 'sdtPr'), 'docPartObj'), 'docPartGallery') == 'Table of Contents'


class _Runs:
    """
    The text of a paragraph's runs. Field state carries across paragraphs, as fields may; text
    boxes met on the way are handed to the body reader and returned beside the text.
    """

    def __init__(
        self, footnotes: Element | None, endnotes: Element | None, body: Callable[[Element], list[Item]]
    ) -> None:
        self.body = body
        self.fields: list[list[Any]] = []
        self.notes: dict[str, Element] = {}
        self.numbers: dict[str, int] = {}
        self.in_note = False
        self.boxes: list[Item] = []
        for kind, root in (('footnote', footnotes), ('endnote', endnotes)):
            for note in _elements(root) if root is not None else []:
                if _is_w(note, kind) and _attribute(note, 'type') not in _SEPARATORS:
                    self.notes.setdefault(f'{kind}:{_attribute(note, "id") or ""}', note)

    def read(self, paragraph: Element) -> tuple[str, list[Item]]:
        saved = self.boxes
        self.boxes = []
        found = self._walk(paragraph)
        boxes = self.boxes
        self.boxes = saved

        return found, boxes

    def note_lines(self) -> list[str]:
        """The referenced notes in marker order, each `[n] text`; a note whose text is empty gives `[n]`."""
        self.in_note = True
        self.fields = []
        lines = []
        for key, number in self.numbers.items():
            parts = [text.squash(self.read(child)[0]) for child in _elements(self.notes[key]) if _is_w(child, 'p')]
            lines.append(text.trim(f'[{number}] ' + ' '.join(part for part in parts if part != '')))

        return lines

    def _walk(self, element: Element) -> str:
        out = ''
        for node in _elements(element):
            namespace = _namespace(node)
            if namespace in W:
                out += self._word(node)
            elif namespace == MC and _local(node) == 'AlternateContent':
                chosen = _choice(node)
                out += '' if chosen is None else self._walk(chosen)
            elif namespace in MATH and _local(node) == 't':
                out += '' if self._dropping() else _content(node)
            else:
                out += self._walk(node)

        return out

    def _word(self, node: Element) -> str:
        name = _local(node)
        if name == 't':
            return '' if self._dropping() else _content(node)
        if name in ('tab', 'ptab'):
            return '' if self._dropping() else '\t'
        if name in ('br', 'cr'):
            return '' if self._dropping() else '\n'
        if name == 'noBreakHyphen':
            return '' if self._dropping() else '-'
        if name == 'sym':
            return '' if self._dropping() else _symbol(node)
        if name == 'fldChar':
            self._field(_attribute(node, 'fldCharType'))
            return ''
        if name in ('footnoteReference', 'endnoteReference'):
            return self._reference(node, 'footnote' if name == 'footnoteReference' else 'endnote')
        if name == 'ruby':
            base = _child(node, 'rubyBase')
            return '' if base is None else self._walk(base)
        if name == 'txbxContent':
            if not self.in_note:
                self.boxes.extend(self.body(node))
            return ''
        if name == 'instrText':
            if self.fields:
                self.fields[-1] = [self.fields[-1][0], self.fields[-1][1] + _content(node)]
            return ''
        if name == 'fldSimple' and _TOC.match(_attribute(node, 'instr') or ''):
            return ''
        if name == 'sdt' and _is_contents(node):
            return ''
        if name in ('del', 'moveFrom', 'delText', 'delInstrText', 'softHyphen') or name.endswith('Pr'):
            return ''

        return self._walk(node)

    def _dropping(self) -> bool:
        return any(entry[0] for entry in self.fields)

    def _field(self, kind: str | None) -> None:
        if kind == 'begin':
            self.fields.append([True, ''])
        elif kind == 'separate' and self.fields:
            instruction = self.fields[-1][1]
            self.fields[-1] = [_TOC.match(instruction) is not None, instruction]
        elif kind == 'end' and self.fields:
            self.fields.pop()

    def _reference(self, node: Element, kind: str) -> str:
        key = f'{kind}:{_attribute(node, "id") or ""}'
        if self.in_note or self._dropping() or key not in self.notes:
            return ''
        self.numbers.setdefault(key, len(self.numbers) + 1)

        return f'[{self.numbers[key]}]'


def _symbol(node: Element) -> str:
    font = (_attribute(node, 'font') or '').lower()
    char = _attribute(node, 'char') or ''
    if not _HEX.match(char) or _SYMBOL_FONTS.search(font):
        return ''
    code = int(char, 16)
    code = code - 0xF000 if code >= 0xF000 else code

    return chr(code) if 0x20 <= code <= 0x10FFFF and not 0xD800 <= code <= 0xDFFF else ''


class _Reader:
    def __init__(self, parts: dict[str, Element | None]) -> None:
        self.parts = parts
        self.styles = _Styles(parts['styles'])
        self.numbering = _Numbering(parts['numbering'])
        self.runs = _Runs(parts['footnotes'], parts['endnotes'], self._body)

    def read(self) -> list[Block]:
        document = self.parts['document']
        body = _child(document, 'body')
        items = [] if body is None else self._body(body)

        return self._render(items, _title(self.parts['core']))

    def _body(self, container: Element) -> list[Item]:
        """Block-level items of a container: paragraphs, each followed by its text boxes' items, and tables."""
        items: list[Item] = []
        for node in _elements(container):
            if _is_w(node, 'p'):
                items.extend(self._paragraph(node))
            elif _is_w(node, 'tbl'):
                items.extend(self._table(node))
            elif _is_w(node, 'sdt') and not _is_contents(node):
                content = _child(node, 'sdtContent')
                items.extend([] if content is None else self._body(content))
            elif _is_w(node, 'customXml') or _is_w(node, 'ins') or _is_w(node, 'moveTo'):
                items.extend(self._body(node))
            elif _namespace(node) == MC and _local(node) == 'AlternateContent':
                chosen = _choice(node)
                items.extend([] if chosen is None else self._body(chosen))

        return items

    def _paragraph(self, paragraph: Element) -> list[Item]:
        properties = _child(paragraph, 'pPr')
        style = self.styles.resolve(_value(properties, 'pStyle'))
        name = self.styles.name(style).lower()
        numbering = _child(properties, 'numPr')
        num_id = _value(numbering, 'numId')
        if num_id is None:
            num_id = self.styles.inherited(style, 'numId')
        ilvl = _integer(_value(numbering, 'ilvl'))
        if ilvl is None:
            ilvl = self.styles.inherited(style, 'ilvl')
        outline = _integer(_value(properties, 'outlineLvl'))
        if outline is None:
            outline = self.styles.inherited(style, 'outline')
        found, boxes = self.runs.read(paragraph)

        item: Item = {'kind': 'paragraph', 'text': found}
        heading = _HEADING.match(name)
        if name == 'title':
            item['kind'] = 'title'
        elif heading or (isinstance(outline, int) and 0 <= outline <= 8):
            level = int(heading.group(1)) if heading else int(outline) + 1
            item = {'kind': 'heading', 'text': found, 'level': min(6, level)}
        elif isinstance(num_id, str) and num_id != '0' and self.numbering.has(num_id):
            clamped = max(0, min(8, int(ilvl or 0)))
            item = {'kind': 'item', 'text': found, 'ilvl': clamped, 'marker': self.numbering.marker(num_id, clamped)}
        elif _CODE.search(name):
            item['kind'] = 'code'
        elif name == 'caption':
            item['kind'] = 'caption'

        return [item, *boxes]

    def _table(self, table: Element) -> list[Item]:
        rows: list[list[dict[str, Any]]] = []
        heads: list[bool] = []
        width = 0
        above: list[dict[str, Any]] = []

        for tr in _children(table, 'tr'):
            properties = _child(tr, 'trPr')
            before = _integer(_value(properties, 'gridBefore'))
            grid = [{'text': '', 'items': [], 'continued': False} for _ in range(max(0, min(100, before or 0)))]
            for tc in _children(tr, 'tc'):
                cell_properties = _child(tc, 'tcPr')
                spanned = _integer(_value(cell_properties, 'gridSpan'))
                span = max(1, min(100, 1 if spanned is None else spanned))
                merge = _child(cell_properties, 'vMerge')
                continues = merge is not None and _attribute(merge, 'val') in (None, 'continue')
                items = self._body(tc)
                for k in range(span):
                    if continues:
                        column = len(grid)
                        merged = above[column]['text'] if column < len(above) else ''
                        grid.append({'text': merged, 'items': [], 'continued': True})
                    else:
                        grid.append({'text': _flatten(items), 'items': items if k == 0 else [], 'continued': False})
            header = _child(properties, 'tblHeader')
            heads.append(header is not None and _attribute(header, 'val') not in ('0', 'false', 'off'))
            rows.append(grid)
            width = max(width, len(grid))
            above = grid

        if width == 1:
            return [item for row in rows for cell in row for item in cell['items']]

        count = 0
        while count < len(heads) and heads[count]:
            count += 1
        count = 0 if not rows else max(1, count)
        texts = [
            ['' if r < count and cell['continued'] else cell['text'] for cell in row] for r, row in enumerate(rows)
        ]

        return [{'kind': 'table', 'header': texts[:count], 'rows': texts[count:]}]

    def _render(self, items: list[Item], title: str) -> list[Block]:
        items = _captions(items)
        shift = 1 if any(item['kind'] == 'title' and text.squash(item['text']) != '' for item in items) else 0

        out = BlockBuilder()
        listed: list[str] = []
        code: list[str] | None = None
        for item in items:
            kind = item['kind']
            squashed = text.squash(item.get('text') or '')
            blank = kind == 'paragraph' and squashed == ''
            if kind != 'code' and code is not None and not blank:
                out.code(code)
                code = None
            if kind != 'item' and listed and not blank:
                out.paragraph('\n'.join(listed))
                listed = []

            if kind == 'code':
                code = [*(code or []), *item['text'].split('\n')]
            elif kind == 'item':
                marker = item['marker']
                if squashed != '':
                    listed.append('  ' * item['ilvl'] + (squashed if marker == '' else f'{marker} {squashed}'))
            elif kind == 'title':
                out.heading(squashed, 1)
            elif kind == 'heading':
                out.heading(squashed, min(6, item['level'] + shift))
            elif kind == 'table':
                out.table(item.get('caption', ''), item['header'], item['rows'])
            elif kind != 'taken':
                out.paragraph(squashed)
        if code is not None:
            out.code(code)
        if listed:
            out.paragraph('\n'.join(listed))
        for note in self.runs.note_lines():
            out.paragraph(note)

        return _with_title(out.blocks(), title)


def _children(parent: Element, name: str) -> list[Element]:
    """Child elements `w:<name>`, also inside `w:sdt` content and `w:customXml`."""
    found: list[Element] = []
    for node in _elements(parent):
        if _is_w(node, name):
            found.append(node)
        elif _is_w(node, 'sdt'):
            content = _child(node, 'sdtContent')
            found.extend([] if content is None else _children(content, name))
        elif _is_w(node, 'customXml'):
            found.extend(_children(node, name))

    return found


def _flatten(items: list[Item]) -> str:
    """A cell's text: its items' squashed text (a nested table's cells in order), non-empty parts joined by spaces."""
    parts: list[str] = []
    for item in items:
        if item['kind'] == 'table':
            for row in [*item['header'], *item['rows']]:
                parts.extend(row)
        else:
            parts.append(text.squash(item['text']))

    return ' '.join(part for part in parts if part != '')


def _captions(items: list[Item]) -> list[Item]:
    """Gives each table the text of a caption-styled paragraph next to it, before it first, then after it."""
    items = [dict(item) for item in items]
    for i, item in enumerate(items):
        if item['kind'] != 'table':
            continue
        for step in (-1, 1):
            j = i + step
            while 0 <= j < len(items) and _blank(items[j]):
                j += step
            if 0 <= j < len(items) and items[j]['kind'] == 'caption':
                item['caption'] = text.squash(items[j]['text'])
                items[j]['kind'] = 'taken'
                break

    return items


def _blank(item: Item) -> bool:
    return item['kind'] in ('paragraph', 'caption') and text.squash(item['text']) == ''


def _title(core: Element | None) -> str:
    for node in _elements(core) if core is not None else []:
        if _namespace(node) == DC and _local(node) == 'title':
            return text.squash(_content(node))

    return ''


def _with_title(blocks: list[Block], title: str) -> list[Block]:
    if any(block.is_heading() and block.level == 1 for block in blocks):
        return blocks

    return blocks if title == '' else [Block.heading(title, 1), *blocks]
