"""
Reads MDX as Markdown after blanking what is not prose: import and export statements, lines
that hold only a component tag, `{/* ... */}` comments and lowercase HTML tags; an admonition
fence (`:::note Title`) keeps its title as a paragraph. Fenced code is left alone, except
that an `mdx-code-block` fence is unwrapped and its content read as MDX. The rules are stated in
spec/formats/mdx.md.
"""
from __future__ import annotations

import re

from .. import text
from ..blocks import Block
from . import markdown

_FENCE = re.compile(r'^ {0,3}(`{3,}|~{3,})[ \t]*([^ \t]*)')
_ESM = re.compile(r'^(?:import|export)(?=[ \t{*])')
_TAG = re.compile(r'^(?:</?[A-Z][A-Za-z0-9_.]*(?:[ \t][^<>]*)?/?>|</?>)$')
_WRAPPED = re.compile(r'^<([A-Z][A-Za-z0-9_.]*)(?:[ \t][^<>]*)?>(.*)</\1[ \t]*>$')
_TAG_START = re.compile(r'^<[A-Z][A-Za-z0-9_.]*(?:[ \t][^<>]*)?$')
_ADMONITION = re.compile(r'^:{3,}([A-Za-z][A-Za-z0-9_-]*)?(?:\[([^\]]*)\]|[ \t]+(.*))?$')
_HTML = re.compile(r'</?[a-z][a-z0-9-]*(?:[ \t][^<>]*)?/?>|<!--.*?-->')
_CODE_SPAN = re.compile(r'(`[^`]*`)')
_COMMENT_OPEN = re.compile(r'\{[ \t]*/\*')
_COMMENT_CLOSE = re.compile(r'\*/[ \t]*\}')
_OPENING = re.compile(r'[^{\[(]')
_CLOSING = re.compile(r'[^}\])]')


def parse(source: str) -> list[Block]:
    lines = source.replace('\r\n', '\n').replace('\r', '\n').split('\n')
    start = _front_matter_end(lines)
    out = lines[:start]
    fence: str | None = None
    unwrap: str | None = None
    depth = 0
    tag = False
    comment = [False]

    for line in lines[start:]:
        if fence is not None:
            fence = None if _closes(line, fence) else fence
            out.append(line)
            continue
        if unwrap is not None and _closes(line, unwrap):
            unwrap = None
            out.append('')
            continue
        if depth > 0:
            depth = 0 if text.trim(line) == '' else depth + _balance(line)
            out.append('')
            continue
        if tag:
            tag = not text.trim(line).endswith('>')
            out.append('')
            continue

        opening = _FENCE.match(line) if not comment[0] else None
        if opening is not None:
            if opening.group(2) == 'mdx-code-block' and unwrap is None:
                unwrap = opening.group(1)
                out.append('')
            else:
                fence = opening.group(1)
                out.append(line)
            continue
        if not comment[0] and _ESM.match(line):
            depth = max(0, _balance(line))
            out.append('')
            continue

        kept = _without_comments(line, comment)
        trimmed = text.trim(kept)
        admonition = _ADMONITION.match(trimmed)
        wrapped = _WRAPPED.match(trimmed) if admonition is None else None
        if admonition is not None:
            given = admonition.group(2) if admonition.group(2) is not None else admonition.group(3)
            title = text.trim(given or '')
            out.extend([''] if title == '' else ['', title, ''])
        elif wrapped is not None:
            out.extend(['', _without_html(wrapped.group(2)), ''])
        elif _TAG.match(trimmed) or trimmed == '':
            out.append('')
        elif _TAG_START.match(trimmed):
            tag = True
            out.append('')
        else:
            kept = _without_html(kept)
            out.append('' if text.trim(kept) == '' else kept)

    return markdown.parse('\n'.join(out))


def _front_matter_end(lines: list[str]) -> int:
    if lines[0] != '---':
        return 0
    for i in range(1, len(lines)):
        if lines[i] in ('---', '...'):
            return i + 1

    return 0


def _without_html(line: str) -> str:
    """The line without lowercase HTML tags and `<!-- -->` comments, outside backtick code spans."""
    parts = _CODE_SPAN.split(line)

    return ''.join(_HTML.sub('', part) if i % 2 == 0 else part for i, part in enumerate(parts))


def _closes(line: str, fence: str) -> bool:
    return re.match('^ {0,3}' + re.escape(fence[0]) + '{' + str(len(fence)) + r',}[ \t]*$', line) is not None


def _balance(line: str) -> int:
    """Opening minus closing brackets of all three kinds."""
    return len(_OPENING.sub('', line)) - len(_CLOSING.sub('', line))


def _without_comments(line: str, comment: list[bool]) -> str:
    """The line without its `{/* ... */}` comments; `comment[0]` carries an unclosed one to the next line."""
    if comment[0]:
        end = _COMMENT_CLOSE.search(line)
        if end is None:
            return ''
        line = line[end.end() :]
        comment[0] = False

    while True:
        opening = _COMMENT_OPEN.search(line)
        if opening is None:
            return line
        end = _COMMENT_CLOSE.search(line, opening.end())
        if end is None:
            comment[0] = True
            return line[: opening.start()]
        line = line[: opening.start()] + line[end.end() :]
