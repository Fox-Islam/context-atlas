"""Markdown read into blocks, by the rules spec/behaviour.md states under "Markdown"."""
from __future__ import annotations

import re

from .. import text
from ..blocks import Block, BlockType

# The Markdown patterns are ASCII-only: `[0-9]` instead of `\d`, which in Python matches any decimal digit.
_FENCE = re.compile(r'^ {0,3}(`{3,}|~{3,})')
_ATX = re.compile(r'^ {0,3}(#{1,6})[ \t]+(.+?)(?:[ \t]+#+)?[ \t]*$')
_SETEXT = re.compile(r'^ {0,3}(=+|-+)[ \t]*$')
_BREAK = re.compile(r'^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$')
_TABLE = re.compile(r'^ {0,3}\|')
_LIST_ITEM = re.compile(r'^ {0,3}(?:[-*+]|[0-9]{1,9}[.)])[ \t]+')
_QUOTE = re.compile(r'^ {0,3}>[ \t]?')


def parse(markdown: str) -> list[Block]:
    """ATX and setext headings, fenced code, pipe tables, lists, block quotes and paragraphs; inline markup is kept."""
    return _MarkdownReader().read(_without_front_matter(lines(markdown).split('\n')))


def lines(source: str) -> str:
    return source.replace('\r\n', '\n').replace('\r', '\n')


def _without_front_matter(lines: list[str]) -> list[str]:
    if lines[0] != '---':
        return lines

    for index in range(1, len(lines)):
        if lines[index] in ('---', '...'):
            return lines[index + 1 :]

    return lines


class _MarkdownReader:
    """Collects the open paragraph, list or table line by line, and emits it as a block when it closes."""

    def __init__(self) -> None:
        self._blocks: list[Block] = []
        self._lines: list[str] = []
        self._open = BlockType.PARAGRAPH
        self._list = False

    def read(self, lines: list[str]) -> list[Block]:
        index = 0

        while index < len(lines):
            fence = _FENCE.match(lines[index])
            if fence is None:
                self._line(lines[index])
            else:
                self._close()
                index = self._fenced(lines, index, fence.group(1))
            index += 1

        self._close()

        return self._blocks

    def _line(self, line: str) -> None:
        if text.trim(line) == '':
            self._close()
            return

        atx = _ATX.match(line)
        if atx is not None:
            self._close()
            self._blocks.append(Block.heading(text.trim(atx.group(2)), len(atx.group(1))))
            return

        setext = _SETEXT.match(line) if self._open is BlockType.PARAGRAPH and not self._list and self._lines else None
        if setext is not None:
            self._blocks.append(Block.heading(' '.join(self._lines), 1 if setext.group(1).startswith('=') else 2))
            self._lines = []
        elif _BREAK.match(line) is not None:
            self._close()
        else:
            self._content(line)

    def _content(self, line: str) -> None:
        is_table = _TABLE.match(line) is not None
        is_item = _LIST_ITEM.match(line) is not None
        kind = BlockType.TABLE if is_table else BlockType.PARAGRAPH

        if self._lines and (kind is not self._open or (is_item and not self._list)):
            self._close()

        self._open = kind
        self._list = self._list or is_item
        self._lines.append(text.trim(line) if is_table else text.trim(_QUOTE.sub('', line, count=1)))

    def _fenced(self, lines: list[str], start: int, fence: str) -> int:
        """Emits the code block opened at `start` and returns the index of its closing fence, or the last line."""
        closer = re.compile('^ {0,3}' + re.escape(fence[0]) + '{' + str(len(fence)) + r',}[ \t]*$')
        index = start + 1

        while index < len(lines) and closer.match(lines[index]) is None:
            index += 1

        body = '\n'.join(lines[start + 1 : index])
        if text.trim(body) != '':
            self._blocks.append(Block(BlockType.CODE, body))

        return index

    def _close(self) -> None:
        if self._lines:
            glue = '\n' if self._open is BlockType.TABLE or self._list else ' '
            self._blocks.append(Block(self._open, glue.join(self._lines)))

        self._lines = []
        self._open = BlockType.PARAGRAPH
        self._list = False
