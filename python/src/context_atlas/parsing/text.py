"""
Plain text: each run of lines between blank lines is a paragraph, its lines joined by single
spaces. Structure the layout shows is kept, by the rules spec/behaviour.md states under "Plain
text":

- a one-line paragraph numbered like `2.5.3.  Encryption` or `Appendix A.  Notes` is a heading,
  its level the count of numbers (the appendix letter counts as one);
- a short one-line paragraph at or left of the body's indent, starting with a capital and not
  ending in `.`, `,`, `;` or `:`, is a level-1 heading when the paragraph after it is more than
  twice as long;
- a paragraph whose lines are all indented at least two spaces deeper than the body is code.
  The body's indent is the indent of the paragraphs' first lines that holds the most text, so
  RFC-style text indented by three spaces reads like flush text.
"""
from __future__ import annotations

import re
import unicodedata

from .. import compat, text
from ..blocks import Block, BlockType
from ..text import WHITESPACE

SHORT = 60

_SPLIT = re.compile(r'\n[ \t]*\n')
# `re` has no `\p{Lu}`: group 3 is checked by hand. The greedy whitespace before it leaves group
# 3 starting at the first non-space, the only place a capital can start it.
_NUMBERED = re.compile(rf'^((?:Appendix|Annex)[{WHITESPACE}]+[A-Z]|\d+)((?:\.\d+)*)\.?[{WHITESPACE}]+(.*)$')
_END = re.compile(r'[.,;:]$')


def parse(source: str) -> list[Block]:
    source = source.replace('\r\n', '\n').replace('\r', '\n').replace('\f', '\n').removeprefix('﻿')
    chunks = [chunk for chunk in _SPLIT.split(source) if text.squash(chunk) != '']
    body = _body_indent(chunks)
    blocks = []

    for i, chunk in enumerate(chunks):
        lines = [line for line in chunk.split('\n') if compat.trim(line) != '']
        indents = [_indent(line) for line in lines]
        paragraph = text.squash(chunk)
        numbered = _numbered(paragraph) if len(lines) == 1 else None
        following = text.squash(chunks[i + 1]) if i + 1 < len(chunks) else ''

        if indents and min(indents) >= body + 2:
            dedent = min(indents)
            blocks.append(Block(BlockType.CODE, '\n'.join(line[dedent:].rstrip(' \t\n\r\x00\x0b') for line in lines)))
        elif numbered is not None:
            blocks.append(Block.heading(paragraph, 1 + numbered.count('.')))
        elif len(lines) == 1 and indents[0] <= body and _is_title(paragraph) and len(following) > 2 * len(paragraph):
            blocks.append(Block.heading(paragraph, 1))
        else:
            blocks.append(Block.paragraph(paragraph))

    return blocks


def _numbered(paragraph: str) -> str | None:
    """Group 2 (the dotted numbers after the first) of a numbered heading, or None."""
    match = _NUMBERED.match(paragraph)
    if match is None:
        return None
    title = match.group(3)
    if title == '' or unicodedata.category(title[0]) != 'Lu' or len(title) > 79 or _END.search(title):
        return None

    return match.group(2)


def _body_indent(chunks: list[str]) -> int:
    """The indent of the chunks' first lines that holds the most squashed text, the smallest on a tie."""
    counts: dict[int, int] = {}
    for chunk in chunks:
        for line in chunk.split('\n'):
            if compat.trim(line) != '':
                counts[_indent(line)] = counts.get(_indent(line), 0) + len(text.squash(chunk))
                break

    if not counts:
        return 0
    most = max(counts.values())

    return min(indent for indent, count in counts.items() if count == most)


def _indent(line: str) -> int:
    return len(line) - len(line.lstrip(' \t'))


def _is_title(line: str) -> bool:
    return len(line) <= SHORT and line != '' and unicodedata.category(line[0]) == 'Lu' and not _END.search(line)
