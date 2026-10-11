"""
Reads the content of an HTML page into blocks: headings, paragraphs (a list as one paragraph of
`- ` or `1. ` lines), code from `pre`, and tables with their caption, header and rows in meta.
Reading starts at `main` (or `role="main"`), else `body`; page furniture such as scripts,
navigation, site headers and hidden elements is dropped, and so is a row of short links repeated
three times or more. Speaker markup in transcripts gives the paragraphs after it `meta.speaker`.
No HTML library is used; spec/formats/html.md states the rules.
"""
from __future__ import annotations

import re
from typing import Any

from .. import text
from ..blocks import Block, BlockType
from ..text import WHITESPACE
from .html_tree import content, table, tree
from .html_tree.element import Element

_START = re.compile(r'^-?[0-9]{1,9}$')

_WS = f'[{WHITESPACE}]'

_SEPARATORS = re.compile(rf'^[{WHITESPACE}\-\u2013\u2014:.,;]+')

_SEPARATORS_END = re.compile(rf'[{WHITESPACE}\-\u2013\u2014:.,;]+$')

_MARKER = re.compile(r'^(?:\(?(?:[0-9]{1,3}|[A-Za-z]{1,2}|[ivxlcIVXLC]{1,6})[.)]|[-\u2013\u2022*\u00b7])$')

_WORDS = re.compile(f'{_WS}+')

_DIGITS = re.compile(r'[0-9]+')

_ITEM_MARK = re.compile(r'^\s*(?:-|-?[0-9]+\.)\s', re.ASCII)

_SENTENCE_END = re.compile(r'[.!?]$')

BOILERPLATE_WORDS = 8

BOILERPLATE_LINK_WORDS = 4

BOILERPLATE_REPEATS = 3


def parse(source: str) -> list[Block]:
    root = tree.build(source.replace('\r\n', '\n').replace('\r', '\n'))
    start = (
        content.find(root, lambda e: e.name == 'main' or e.token('role') == 'main')
        or content.find(root, lambda e: e.name == 'body')
        or root
    )

    reader = _Reader(start)
    reader.walk(start, content.opens_article(start))
    reader.flush()

    return _titled(reader.without_boilerplate(), root)


def _titled(blocks: list[Block], root: Element) -> list[Block]:
    """The page's `title` as a level-1 heading in front, when no level-1 heading was read."""
    if any(block.is_heading() and block.level == 1 for block in blocks):
        return blocks

    title = content.find(root, lambda e: e.name == 'title', ['svg'])
    heading = '' if title is None else text.squash(''.join(child for child in title.children if isinstance(child, str)))

    return blocks if heading == '' else [Block.heading(heading, 1), *blocks]


def _code(pre: Element, in_article: bool) -> str:
    """
    A `pre` element's text as written, a `br` as a line break, without one leading line break
    and without trailing whitespace.
    """
    written = content.raw(pre, in_article)

    return (written[1:] if written.startswith('\n') else written).rstrip(' \t\n\f')


def _is_sole_button(parent: Element, child: Element) -> bool:
    """A `button` that is the only content of a `summary` or `dt`, which holds its label."""
    if child.name != 'button' or parent.name not in ('summary', 'dt'):
        return False

    return all(other is child or (isinstance(other, str) and text.trim(other) == '') for other in parent.children)


def _is_marker_list(dl: Element, in_article: bool) -> bool:
    """A `dl` whose `dt` children are all enumeration markers, as statutes number points: `1.`, `a)`, `(iv)`, `-`."""
    markers = 0
    for child in dl.children:
        if isinstance(child, str):
            if text.trim(child) != '':
                return False
        elif child.name == 'dt':
            if not _MARKER.match(text.squash(content.line(child, in_article))):
                return False
            markers += 1
        elif child.name != 'dd' and not content.dropped(child, in_article):
            return False

    return markers > 0


def _link(element: Element, in_article: bool, most: int | None) -> int | None:
    """The most words in one link's text so far, counting `element` when it is an `a`."""
    if element.name != 'a':
        return most

    found = text.squash(content.line(element, in_article))
    words = 0 if found == '' else len(_WORDS.split(found))

    return max(most if most is not None else 0, words)


def _is_link_line(body: str) -> bool:
    """Short lines, none ending a sentence: what a row of links such as "Reply | Share" reads as."""
    for row in body.split('\n'):
        row = text.trim(_ITEM_MARK.sub('', row, count=1))
        if len(_WORDS.split(row)) > BOILERPLATE_WORDS or _SENTENCE_END.search(row):
            return False

    return True


class _Reader:
    def __init__(self, start: Element) -> None:
        self.blocks: list[Block] = []
        self.buffer = ''
        self.lines: list[str] = []
        self.speaker: dict[str, str] | None = None
        self.speaker_scope: Element | None = None
        self.after_speaker = False
        self.open: list[tuple[Element, int]] = [(start, 0)]
        self.buffer_link: int | None = None
        self.list_link: int | None = None
        self.candidates: dict[int, str] = {}

    def walk(self, parent: Element, in_article: bool) -> None:
        for child in parent.children:
            if isinstance(child, str):
                self.buffer += child
            elif content.dropped(child, in_article, _is_sole_button(parent, child)):
                continue
            elif child.name == 'math':
                self.buffer += content.math(child)
            elif content.is_speaker(child):
                self._speak(child, in_article)
            else:
                self.buffer_link = _link(child, in_article, self.buffer_link)
                self._element(child, in_article or content.opens_article(child))

    def flush(self) -> None:
        """Emits the buffer as a paragraph; right after a speaker element, leading separators are left out."""
        found = text.squash(self.buffer)
        if self.after_speaker:
            found = _SEPARATORS.sub('', found, count=1)
            self.after_speaker = False
        self._emit(BlockType.PARAGRAPH, found, None, self.buffer_link)
        self.buffer = ''
        self.buffer_link = None

    def without_boilerplate(self) -> list[Block]:
        """
        The blocks without boilerplate: a paragraph holding a link, no link longer than four
        words, made of short lines, whose text without digits has an ASCII letter and recurs at
        least three times in the page.
        """
        repeats: dict[str, int] = {}
        for key in self.candidates.values():
            repeats[key] = repeats.get(key, 0) + 1

        return [
            block
            for i, block in enumerate(self.blocks)
            if i not in self.candidates or repeats[self.candidates[i]] < BOILERPLATE_REPEATS
        ]

    def _element(self, element: Element, in_article: bool) -> None:
        name = element.name
        if name == 'br':
            self.buffer += '\n'
            return
        level = content.heading_level(element)
        if level is None and not content.is_block(name):
            self.buffer += content.script(element, self.buffer)
            self.walk(element, in_article)
            return

        self.flush()
        if level is not None:
            self._emit(BlockType.HEADING, text.squash(content.line(element, in_article, True)), level)
        elif name == 'dl' and _is_marker_list(element, in_article):
            self._markers(element, in_article, 0)
        elif name == 'pre':
            self._emit(BlockType.CODE, _code(element, in_article))
        elif name == 'table':
            self._table(element, in_article)
        elif name in content.LISTS:
            self._items(element, in_article, 0)
        else:
            self._block(element, in_article)
        self._emit_list()
        self.flush()

    def _block(self, element: Element, in_article: bool) -> None:
        self.open.append((element, len(self.blocks)))
        self.walk(element, in_article)
        self.flush()
        self.open.pop()
        if self.speaker_scope is element:
            self.speaker = None
            self.speaker_scope = None

    def _speak(self, element: Element, in_article: bool) -> None:
        """
        A speaker element is not read as text: it names who speaks in the paragraphs after it, up
        to the next speaker element or the end of the block it sits in. When it opens a `p`, that
        block is the one around the `p`, so the speech's later paragraphs are included.
        """
        full = _SEPARATORS_END.sub('', text.squash(content.line(element, in_article)), count=1)
        if full == '':
            return

        named = content.find(element, lambda e: any('name' in token for token in e.tokens('class')))
        name = '' if named is None else _SEPARATORS_END.sub('', text.squash(content.line(named, in_article)), count=1)
        name = name if name != '' else full

        opens = text.trim(self.buffer) == ''
        self.flush()
        self.speaker = {'speaker': name} if name == full else {'speaker': name, 'speaker_full': full}

        top = len(self.open) - 1
        block, opened_at = self.open[top]
        if opens and block.name == 'p' and opened_at == len(self.blocks) and top > 0:
            block = self.open[top - 1][0]
        self.speaker_scope = block
        self.after_speaker = True

    def _markers(self, dl: Element, in_article: bool, depth: int) -> None:
        """Adds a marker list's lines: each `dt` marker, a space, and the text of the element after it."""
        marker = ''
        for child in dl.children:
            if not isinstance(child, Element) or content.dropped(child, in_article):
                continue
            if child.name == 'dt':
                marker = text.squash(content.line(child, in_article)) + ' '
                continue
            state = ['  ' * depth + marker, '']
            self._item(child, in_article or content.opens_article(child), depth, state)
            self._line(state, depth)
            marker = ''

    def _emit_list(self) -> None:
        self._emit(BlockType.PARAGRAPH, '\n'.join(self.lines), None, self.list_link)
        self.lines = []
        self.list_link = None

    def _emit(self, kind: BlockType, body: str, level: int | None = None, link: int | None = None) -> None:
        """`link` is the most words in one link's text inside the block, None when it has none."""
        if text.trim(body) == '':
            return

        meta: dict[str, Any] = dict(self.speaker) if kind is BlockType.PARAGRAPH and self.speaker is not None else {}
        key = text.squash(_DIGITS.sub('', body))
        if (
            link is not None
            and link <= BOILERPLATE_LINK_WORDS
            and re.search(r'[A-Za-z]', key)
            and _is_link_line(body)
        ):
            self.candidates[len(self.blocks)] = key
        self.blocks.append(Block(kind, body, level, meta))

    def _table(self, element: Element, in_article: bool) -> None:
        found = table.read(element, in_article)
        if found is not None:
            self.blocks.append(table.block(found))

    def _items(self, listing: Element, in_article: bool, depth: int) -> None:
        """Adds a list's lines: one per item, `- ` or `<n>. ` after two spaces per level of nesting."""
        ordered = listing.name == 'ol'
        start = (listing.attribute('start') or '').strip(' \t\n\f\r')
        number = [int(start) if ordered and _START.match(start) else 1]
        self._children(listing, in_article, depth, ordered, number)

    def _children(self, parent: Element, in_article: bool, depth: int, ordered: bool, number: list[int]) -> None:
        indent = '  ' * depth
        for child in parent.children:
            if isinstance(child, str):
                found = text.squash(child)
                if found != '':
                    self.lines.append(indent + (f'{number[0]}. ' if ordered else '- ') + found)
                    number[0] += 1
            elif content.dropped(child, in_article):
                continue
            elif child.name in content.LISTS:
                self._items(child, in_article or content.opens_article(child), depth + 1)
            elif child.name == 'li':
                state = [indent + (f'{number[0]}. ' if ordered else '- '), '']
                self._item(child, in_article or content.opens_article(child), depth, state)
                self._line(state, depth)
                number[0] += 1
            else:
                self._children(child, in_article or content.opens_article(child), depth, ordered, number)

    def _item(self, parent: Element, in_article: bool, depth: int, state: list[str]) -> None:
        """
        Reads an item's text in order, `state` being its line prefix and text. A list inside it
        adds its lines after the text so far; a `pre` or table ends the list paragraph so far, is
        emitted, and the list goes on after.
        """
        for child in parent.children:
            if isinstance(child, str):
                state[1] += child
                continue
            if content.dropped(child, in_article):
                continue
            inside = in_article or content.opens_article(child)
            name = child.name
            self.list_link = _link(child, inside, self.list_link)
            if name == 'br':
                state[1] += ' '
            elif name == 'math':
                state[1] += content.math(child)
            elif name == 'dl' and _is_marker_list(child, inside):
                self._line(state, depth)
                self._markers(child, inside, depth + 1)
            elif name in content.LISTS:
                self._line(state, depth)
                self._items(child, inside, depth + 1)
            elif name in ('pre', 'table'):
                self._line(state, depth)
                self._emit_list()
                if name == 'pre':
                    self._emit(BlockType.CODE, _code(child, inside))
                else:
                    self._table(child, inside)
            elif content.is_block(name):
                state[1] += ' '
                self._item(child, inside, depth, state)
                state[1] += ' '
            else:
                state[1] += content.script(child, state[1])
                self._item(child, inside, depth, state)

    def _line(self, state: list[str], depth: int) -> None:
        """Ends the item text as one line; later lines of the item are indented one level with no marker."""
        found = text.squash(state[1])
        state[1] = ''
        if found != '':
            self.lines.append(state[0] + found)
            state[0] = '  ' * (depth + 1)
