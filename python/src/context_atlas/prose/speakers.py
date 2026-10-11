"""
Finds conversations in documents whose reader cannot know who spoke: Markdown or HTML minutes,
transcripts and chat logs written as `Name: text`. Within a run of paragraphs under one heading,
when enough of them open with a speaker, each gets `meta.speaker`, so the section becomes
conversational.

A prefix is an optional time (`[10:02]` or `10:02`), then a name: one to four capitalised words
(lowercase joiners such as `of`, `de`, `von` or `y` may join them, and a lowercase title word may
end them), or up to 16 letters of a script without case, which need a colon after them or a `○`
marker before them. An optional `(role)` follows, then a colon (ASCII or fullwidth) or a full stop
and a dash. A run qualifies with at least 4 paragraphs, 60% of them prefixed, and 2 different
names. A paragraph without a prefix continues the speaker before it. Words that label text
instead of naming a speaker ("Note", "Example", "Step 2") are not names.
"""
from __future__ import annotations

import functools
import re
import sys
import unicodedata
from typing import Any

from .. import text
from ..blocks import Block, BlockType
from ..chunking.breadcrumbs import breadcrumbs
from ..text import WHITESPACE

NOT_NAMES = frozenset({
    'note', 'notes', 'tip', 'warning', 'caution', 'important', 'example', 'examples', 'answer', 'question',
    'summary', 'result', 'results', 'source', 'sources', 'see', 'definition', 'update', 'edit', 'info', 'hint',
    'step', 'action', 'actions', 'decision', 'resolved', 'agreed', 'outcome', 'status', 'date', 'time', 'location',
    'attendees', 'present', 'apologies', 'subject', 're', 'to', 'from', 'cc', 'title', 'author', 'version', 'q', 'a',
    'remarque', 'nota', 'hinweis', 'anmerkung', 'beispiel', 'exemple', 'ejemplo', 'esempio', 'r\u00e9ponse',
    'antwort', 'respuesta', 'risposta', 'r\u00e9sum\u00e9', 'zusammenfassung', 'resumen', 'quelle', 'fuente',
    'fonte', 'attention', 'achtung', 'atenci\u00f3n', 'attenzione', '\u6ce8', '\u4f8b', '\u6ce8\u610f',
    '\u5099\u8003',
})

MIN_PARAGRAPHS = 4

MIN_SHARE = 0.6

_JOINERS = 'of|for|the|and|de|du|des|del|della|di|da|do|dos|das|la|le|les|van|von|der|den|zu|y|e'

_MARKERS = '\u25cb\u25ef\u25ce\u25cf'


@functools.cache
def category_class(*prefixes: str) -> str:
    """
    The body of a regex character class matching every code point whose Unicode category starts
    with one of `prefixes` (`'Lu'`, or `'L'` for every letter), for patterns PCRE writes with
    `\\p{...}`, which `re` lacks. Built on first use from the runtime's Unicode tables.
    """
    ranges: list[tuple[int, int]] = []
    for code in range(sys.maxunicode + 1):
        if unicodedata.category(chr(code)).startswith(prefixes):
            if ranges and ranges[-1][1] == code - 1:
                ranges[-1] = (ranges[-1][0], code)
            else:
                ranges.append((code, code))

    return ''.join(
        re.escape(chr(low)) if low == high else f'{re.escape(chr(low))}-{re.escape(chr(high))}' for low, high in ranges
    )


@functools.cache
def _patterns() -> tuple[re.Pattern[str], re.Pattern[str], re.Pattern[str], re.Pattern[str]]:
    """
    The cased pattern, the uncased one, and the two checks an uncased match needs (a colon after
    the name, or a marker before it), as spec/prose.md "Speakers in text" gives them.
    """
    word = f"[{category_class('Lu')}][{category_class('L', 'M')}'\u2019.-]*"
    lower = f"[{category_class('Ll')}][{category_class('L', 'M')}'\u2019.-]*"
    letters = category_class('Lo')
    uncased = f'[{letters}]'
    space = f'[{WHITESPACE}]'
    time = rf'(?:\[?(\d{{1,2}}:\d{{2}}(?::\d{{2}})?)\]?{space}+)?'
    bare_time = rf'(?:\[?\d{{1,2}}:\d{{2}}(?::\d{{2}})?\]?{space}+)?'
    role = rf'(?:{space}*[(\uff08]([^()\uff08\uff09\n]{{1,80}})[)\uff09])?'
    bare_role = rf'(?:{space}*[(\uff08][^()\uff08\uff09\n]{{1,80}}[)\uff09])?'
    rest = rf'([^{WHITESPACE}].*)$'
    name = (
        rf'({word}(?:{space}+(?:(?:{_JOINERS}){space}+)*{word}){{0,3}}'
        rf'(?:{space}+(?:{_JOINERS}){space}+{lower})?)'
    )

    return (
        re.compile(
            rf'^{time}{name}{role}(?:{space}*:{space}+|{space}*\uff1a{space}*|\.{space}+[\u2013\u2014-]{space}+){rest}',
            re.DOTALL,
        ),
        re.compile(
            rf'^{time}(?:[{_MARKERS}]{space}*)?({uncased}{{1,16}}){role}'
            rf'(?:{space}*[:\uff1a]{space}*|(?<=[{letters})\uff09])[\u3000 ]+){rest}',
            re.DOTALL,
        ),
        re.compile(rf'^{bare_time}(?:[{_MARKERS}]{space}*)?{uncased}{{1,16}}{bare_role}{space}*[:\uff1a]'),
        re.compile(rf'^{bare_time}[{_MARKERS}]'),
    )


def infer(blocks: list[Block]) -> list[Block]:
    """The blocks with `meta.speaker` set on the paragraphs of every run that qualifies."""
    crumbs = breadcrumbs(blocks)
    runs: list[dict[str, Any]] = []
    current: int | None = None
    for i, block in enumerate(blocks):
        if block.is_heading():
            current = None
            continue
        key = '\x1f'.join(crumbs[i].path)
        if current is None or runs[current]['key'] != key:
            runs.append({'key': key, 'blocks': []})
            current = len(runs) - 1
        runs[current]['blocks'].append(i)

    out = list(blocks)
    for run in runs:
        for i, block in _run(blocks, run['blocks']).items():
            out[i] = block

    return out


def _run(blocks: list[Block], indices: list[int]) -> dict[int, Block]:
    paragraphs = [i for i in indices if blocks[i].type is BlockType.PARAGRAPH and text.trim(blocks[i].text) != '']
    if any('speaker' in _meta(blocks[i]) and _meta(blocks[i])['speaker'] is not None for i in paragraphs):
        return {}
    prefixes = {i: found for i in paragraphs if (found := _prefix(blocks[i].text)) is not None}
    names = {found['speaker'] for found in prefixes.values()}
    if len(paragraphs) < MIN_PARAGRAPHS or len(prefixes) < MIN_SHARE * len(paragraphs) or len(names) < 2:
        return {}

    out = {}
    last: dict[str, Any] | None = None
    for i in paragraphs:
        block = blocks[i]
        found = prefixes.get(i)
        if found is not None:
            last = found
            meta = {'speaker': found['speaker']}
            if found['full'] != found['speaker']:
                meta['speaker_full'] = found['full']
            if found['time'] is not None:
                meta['time'] = found['time']
            out[i] = Block.paragraph(found['text'], {**_meta(block), **meta})
        elif last is not None:
            meta = {'speaker': last['speaker']}
            if last['full'] != last['speaker']:
                meta['speaker_full'] = last['full']
            out[i] = Block.paragraph(block.text, {**_meta(block), **meta})

    return out


def _prefix(source: str) -> dict[str, Any] | None:
    trimmed = text.trim(source)
    cased, uncased, colon, marker = _patterns()
    match = cased.match(trimmed)
    if match is None:
        found = uncased.match(trimmed)
        # A name in a script without letter case needs a colon, or a marker before it when only a
        # space follows it (as in Japanese Diet records), so ordinary sentences do not read as speakers.
        if found is None or (colon.match(trimmed) is None and marker.match(trimmed) is None):
            return None
        match = found
    name = match.group(2)
    first = re.sub(f'[{WHITESPACE}]+', ' ', name).split(' ')[0].lower()
    if name.lower() in NOT_NAMES or first in NOT_NAMES or len(name) < 2 or re.search(r'\d', name):
        return None
    role = text.trim(match.group(3)) if match.group(3) else None

    return {
        'speaker': name,
        'full': name if role is None else f'{name} ({role})',
        'time': match.group(1) or None,
        'text': text.trim(match.group(4)),
    }


def _meta(block: Block) -> dict[str, Any]:
    return block.meta if isinstance(block.meta, dict) else {}
