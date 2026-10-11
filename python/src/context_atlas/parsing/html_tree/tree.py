"""
Tokenizes HTML and builds a tree with a stack of open elements: void elements never open, `/>`
closes any element at once, a few start tags close the element they cannot sit in (a `p` before
a block, an `li` before the next `li`, table cells and rows), and an end tag closes the nearest
open element of its name or is ignored. Every character the tokenizer looks for is ASCII, so
code point positions give the same tokens as PHP's byte offsets.
"""
from __future__ import annotations

import re

from . import entities
from .element import Element, ascii_lower

_NAME = r'[A-Za-z][^\t\n\f\r />]*'
_BOGUS = re.compile(r'<[!?]|</(?![A-Za-z])')
_END_TAG = re.compile(r'</(' + _NAME + r')[^>]*(?:>|\Z)')
_START_TAG = re.compile(r'<(' + _NAME + r')')
_SPACE = re.compile(r'[\t\n\f\r ]*')
_ATTRIBUTE = re.compile(r'[^\t\n\f\r />][^\t\n\f\r />=]*')
_EQUALS = re.compile(r'[\t\n\f\r ]*=[\t\n\f\r ]*')
_VALUE = re.compile(r'"([^"]*)(?:"|\Z)|\'([^\']*)(?:\'|\Z)|([^\t\n\f\r >]*)')

VOID = {
    'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'keygen', 'link', 'meta', 'param', 'source', 'track',
    'wbr',
}

RAW = {'iframe', 'noembed', 'noframes', 'noscript', 'script', 'style', 'textarea', 'title', 'xmp'}

ESCAPABLE_RAW = {'textarea', 'title'}

CLOSES_P = {
    'address', 'article', 'aside', 'blockquote', 'center', 'dd', 'details', 'dialog', 'dir', 'div', 'dl', 'dt',
    'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup',
    'hr', 'li', 'main', 'menu', 'nav', 'ol', 'p', 'pre', 'search', 'section', 'summary', 'table', 'ul',
}

HEADINGS = {'h1', 'h2', 'h3', 'h4', 'h5', 'h6'}

SCOPE = ['button', 'caption', 'html', 'table', 'td', 'template', 'th']

TABLE_PARTS = {'caption', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr'}

SECTIONS = ['tbody', 'tfoot', 'thead']


def build(html: str) -> Element:
    """The document node of `html`, whose line endings are already `\\n`."""
    builder = _Builder(html)
    builder.run()

    return builder.root


class _Builder:
    def __init__(self, html: str) -> None:
        self.html = html
        self.root = Element('#document')
        self.stack: list[Element] = []

    def run(self) -> None:
        length = len(self.html)
        at = 0
        while at < length:
            lt = self.html.find('<', at)
            if lt == -1:
                self._text(self.html[at:])
                break
            if lt > at:
                self._text(self.html[at:lt])
            at = self._markup(lt)

    def _markup(self, at: int) -> int:
        """Reads the markup token at `at`, a `<`, and returns the position after it."""
        if self.html.startswith('<!--', at):
            return self._after('-->', at + 4)
        if _BOGUS.match(self.html, at):
            return self._after('>', at + 2)
        end = _END_TAG.match(self.html, at)
        if end:
            self._end(ascii_lower(end.group(1)))
            return end.end()
        start = _START_TAG.match(self.html, at)
        if start:
            return self._start_tag(ascii_lower(start.group(1)), start.end())
        self._text('<')

        return at + 1

    def _after(self, needle: str, start: int) -> int:
        """The position just after the next `needle` from `start`, or the end of the input."""
        found = self.html.find(needle, start) if start <= len(self.html) else -1

        return len(self.html) if found == -1 else found + len(needle)

    def _start_tag(self, name: str, at: int) -> int:
        """Reads a start tag's attributes from `at`, just after its name. A tag the input ends inside is dropped."""
        length = len(self.html)
        attributes: dict[str, str] = {}
        self_closing = False

        while True:
            at = _SPACE.match(self.html, at).end()
            if at >= length:
                return length
            if self.html[at] == '>':
                at += 1
                break
            if self.html[at] == '/':
                at += 1
                if at < length and self.html[at] == '>':
                    self_closing = True
                    at += 1
                    break
                continue

            found = _ATTRIBUTE.match(self.html, at)
            attribute = found.group(0) if found else self.html[at]
            at += len(attribute)
            attribute = ascii_lower(attribute)
            value = ''
            equals = _EQUALS.match(self.html, at)
            if equals:
                at = equals.end()
                quoted = _VALUE.match(self.html, at)
                if quoted:
                    at = quoted.end()
                    raw = next((group for group in quoted.groups() if group is not None), '')
                    value = entities.decode(raw)
            attributes.setdefault(attribute, value)

        self._start(name, attributes, self_closing)

        return self._raw_text(name, at) if name in RAW and not self_closing else at

    def _raw_text(self, name: str, at: int) -> int:
        """The content of a raw text element runs to its end tag, which is then read as a tag."""
        closing = re.compile('</' + re.escape(name) + r'(?=[\t\n\f\r />]|\Z)', re.IGNORECASE | re.ASCII)
        found = closing.search(self.html, at)
        end = found.start() if found else len(self.html)
        if end > at:
            raw = self.html[at:end]
            self._current().append(entities.decode(raw) if name in ESCAPABLE_RAW else raw)

        return end

    def _start(self, name: str, attributes: dict[str, str], self_closing: bool) -> None:
        if name in CLOSES_P:
            self._close(['p'], SCOPE)

        if name == 'li':
            self._close(['li'], [*SCOPE, 'menu', 'ol', 'ul'])
        elif name in ('dd', 'dt'):
            self._close(['dd', 'dt'], [*SCOPE, 'dl'])
        elif name in HEADINGS:
            if self.stack and self._current().name in HEADINGS:
                self.stack.pop()
        elif name == 'tr':
            self._close(['tr'], ['table', *SECTIONS])
        elif name in ('td', 'th'):
            self._close(['td', 'th'], ['table', 'tr', *SECTIONS])
        elif name in SECTIONS and not self._close(SECTIONS, ['table']):
            self._close(['tr'], ['table'])

        element = Element(name, attributes)
        self._current().append(element)
        if not self_closing and name not in VOID:
            self.stack.append(element)

    def _end(self, name: str) -> None:
        if name == 'br':
            self._start('br', {}, False)
            return
        if name == 'table':
            boundaries: list[str] = []
        elif name in TABLE_PARTS:
            boundaries = ['table']
        else:
            boundaries = SCOPE
        self._close([name], boundaries)

    def _close(self, targets: list[str], boundaries: list[str]) -> bool:
        """
        Closes the nearest open element named in `targets`, with everything opened inside it,
        unless an element named in `boundaries` is reached first. The document node never closes.
        """
        for i in range(len(self.stack) - 1, -1, -1):
            name = self.stack[i].name
            if name in targets:
                del self.stack[i:]
                return True
            if name in boundaries:
                return False

        return False

    def _text(self, source: str) -> None:
        self._current().append(entities.decode(source))

    def _current(self) -> Element:
        return self.stack[-1] if self.stack else self.root
