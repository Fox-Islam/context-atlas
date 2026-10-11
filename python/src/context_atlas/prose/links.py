"""
Builds the typed links between passages that retrieval follows.

- `needs`: a passage that depends on the one before it to be understood, such as a worked
  example after its rule. Asked of every consecutive pair.
- `defined_by`: a passage that uses a term another passage defines. Jev marks definition
  passages, then is asked only about passages sharing a distinctive term with one: asking about
  every pair links nearly every passage to every definition.
- `refers_to`: an explicit cross-reference such as "Section 3.1" or "regulation 34(9)", parsed
  without Jev.

A failed request leaves its links out; the document is usable without them.
"""
from __future__ import annotations

import math
import re
import unicodedata
from collections.abc import Callable
from typing import Any

from typesafe_sdk import Noul

from .. import jev, text
from ..document import Link, Unit
from ..index import terms as lexical_terms
from ..jev import Jev
from ..text import WHITESPACE

PAIR_BATCH = 30

DISTINCTIVE = 0.2

# The PHP patterns, ASCII-only except _OWNED: only the keywords ignore case, so `[A-Z]` is a
# capital letter as written.
_REFERENCE = re.compile(
    r'\b(?i:sections?|clauses?|articles?|regulations?|rules?|paragraphs?|schedules?|parts?)\s+'
    r'((?:(?:\d+[A-Z]?|[A-Z]\b)(?:\.\d+)*(?:\([a-z0-9]+\))*(?:\s*(?:,|and|or|to)\s*)?)+)',
    re.ASCII,
)
_NUMBER = re.compile(r'(?:\d+[A-Z]?|\b[A-Z]\b)(?:\.\d+)*(?:\([a-z0-9]+\))*', re.ASCII)
_OWNED = re.compile(
    rf'^(?:(?i:section|clause|article|regulation|rule|paragraph|schedule|part)[{WHITESPACE}]+)?'
    rf'(\d+[A-Z]?(?:\.\d+)*|[A-Z](?:\.\d+)*(?=\.[{WHITESPACE}]))(?=[{WHITESPACE}.:)\u2014\u2013-]|$)'
)
_ANCHOR_STOP = frozenset('#.,;:()\n')
_PARENTHESISED = re.compile(r'\(.*$')
_PREFIX = re.compile(rf'^\[[^\]\n]*\][{WHITESPACE}]*')

Pair = tuple[Unit, Unit]


def body(passage: str) -> str:
    """A passage's text without its `[heading > path]` prefix."""
    return _PREFIX.sub('', passage, count=1)


def needs(client: Jev | None, units: list[Unit]) -> list[Link]:
    pairs = {f'n{i - 1}': (units[i - 1], units[i]) for i in range(1, len(units))}
    yes = _ask(
        client,
        pairs,
        lambda key: _noul(
            f'In pair {key}, does the second passage depend on the first to be understood?',
            'it refers back to the first, continues its list or example, or answers it, so it makes little sense '
            'read alone',
            'it can be understood without the first',
        ),
        lambda a, b: {'end_of_first': text.end(a.text, 300), 'start_of_second': text.head(body(b.text), 300)},
    )

    return [Link('needs', pairs[key][1].id, pairs[key][0].id) for key in yes]


def definitions(client: Jev | None, units: list[Unit]) -> list[Link]:
    defining = _definition_units(client, units)
    if not defining:
        return []

    pairs = _sharing_terms(units, defining)
    yes = _ask(
        client,
        pairs,
        lambda key: _noul(
            f'In pair {key}, does the passage use a term whose meaning the definitions state?',
            'a term in the passage is one the definitions give the meaning of',
            'none of the defined terms appear in the passage',
        ),
        lambda a, b: {'passage': text.head(a.text, 500), 'definitions': text.head(b.text, 700)},
        batch=25,
    )

    return [Link('defined_by', pairs[key][0].id, pairs[key][1].id) for key in yes]


def references(units: list[Unit]) -> list[Link]:
    """
    Explicit references to numbered sections, resolved against the numbers that open each
    passage's headings or text. A reference to "34(9)" falls back to "34" when no passage is
    numbered "34(9)".
    """
    owners: dict[str, str] = {}
    for unit in units:
        for number in _numbers(unit):
            owners.setdefault(number, unit.id)

    anchors = _anchors(units)
    links = []
    for unit in units:
        targets: dict[str, bool] = {}
        for found in _REFERENCE.finditer(body(unit.text)):
            for number in _NUMBER.findall(found.group(1)):
                target = owners.get(number) or owners.get(_PARENTHESISED.sub('', number, count=1))
                if target is not None and target != unit.id:
                    targets[target] = True
        for heading in _hashes(body(unit.text)):
            for target in _anchor_for(text.trim(heading).lower(), anchors):
                if target != unit.id:
                    targets[target] = True
        links.extend(Link('refers_to', unit.id, target) for target in targets)

    return links


def _anchors(units: list[Unit]) -> dict[str, list[str]]:
    """Each last heading, lowercased, to the first two passages under it, for wiki-style `#Heading` references."""
    anchors: dict[str, list[str]] = {}
    for unit in units:
        heading = text.trim(unit.path[-1] if unit.path else '').lower()
        if heading != '' and len(anchors.get(heading, [])) < 2:
            anchors.setdefault(heading, []).append(unit.id)

    return anchors


def _anchor_for(written: str, anchors: dict[str, list[str]]) -> list[str]:
    """
    The targets of the longest heading the text after `#` starts with, followed by the end or a
    space, so "#Prepare first" finds the heading "Prepare".
    """
    best: str | None = None
    for heading in anchors:
        fits = written.startswith(heading) and (len(written) == len(heading) or written[len(heading)] == ' ')
        if fits and (best is None or len(heading) > len(best)):
            best = heading

    return [] if best is None else anchors[best]


def _hashes(passage: str) -> list[str]:
    """
    Each `#` followed by a capital letter (category Lu) and 2 to 60 characters up to `#`, `.`,
    `,`, `;`, `:`, a bracket or a newline, as PCRE's `#(\\p{Lu}[^#.,;:()\\n]{2,60})` finds them.
    """
    found = []
    at = passage.find('#')

    while at != -1:
        end = at + 2
        if end <= len(passage) and unicodedata.category(passage[at + 1 : end] or ' ') == 'Lu':
            while end < len(passage) and end - at - 2 < 60 and passage[end] not in _ANCHOR_STOP:
                end += 1
            if end - at - 2 >= 2:
                found.append(passage[at + 1 : end])
                at = passage.find('#', end)
                continue
        at = passage.find('#', at + 1)

    return found


def _numbers(unit: Unit) -> list[str]:
    """The section numbers a passage carries, from each heading in its path and from the start of its own text."""
    found: list[str] = []

    for line in [*unit.path, text.head(body(unit.text), 40)]:
        match = _OWNED.match(text.trim(line))
        if match and match.group(1) not in found:
            found.append(match.group(1))

    return found


def _definition_units(client: Jev | None, units: list[Unit]) -> list[Unit]:
    pairs = {f'd{i}': (unit, unit) for i, unit in enumerate(units)}
    yes = _ask(
        client,
        pairs,
        lambda key: _noul(
            f'Does `{key}` define what a term means, for use elsewhere in the document?',
            f'`{key}` states the meaning of one or more terms',
            f'`{key}` uses terms without defining them',
        ),
        lambda a, _: text.head(a.text, 500),
        state='passages',
    )

    return [pairs[key][0] for key in yes]


def _sharing_terms(units: list[Unit], defining: list[Unit]) -> dict[str, Pair]:
    """
    Passage and definition pairs sharing a distinctive term: one found in at most a fifth of the
    passages, and at least two.
    """
    frequency: dict[str, int] = {}
    for unit in units:
        for term in _terms(unit.text):
            frequency[term] = frequency.get(term, 0) + 1
    limit = max(2, math.floor(DISTINCTIVE * len(units)))
    pairs: dict[str, Pair] = {}

    for unit in units:
        own = set(_terms(unit.text))
        for definition in defining:
            if definition.id == unit.id:
                continue
            if any(term in own and frequency.get(term, 0) <= limit for term in _terms(definition.text)):
                pairs[f'u{len(pairs)}'] = (unit, definition)

    return pairs


def _terms(passage: str) -> list[str]:
    """Distinct lexical terms of four characters or more; shorter ones are rarely the defined term."""
    return list(dict.fromkeys(term for term in lexical_terms(body(passage)) if len(term) >= 4))


def _ask(
    client: Jev | None,
    pairs: dict[str, Pair],
    question: Callable[[str], Noul],
    show: Callable[[Unit, Unit], Any],
    batch: int = PAIR_BATCH,
    state: str = 'pairs',
) -> list[str]:
    """One noul per pair, in batches; the keys answered yes (0.5 or more). A failed batch is skipped."""
    if client is None or not pairs:
        return []

    keys = list(pairs)
    yes = []
    for start in range(0, len(keys), max(1, batch)):
        chunk = keys[start : start + max(1, batch)]
        response = jev.send(
            client,
            {
                'state': {state: {key: show(*pairs[key]) for key in chunk}},
                'questions': {key: question(key) for key in chunk},
            },
        )
        if response is None:
            continue
        yes.extend(key for key in chunk if (jev.noul(response, key) or 0.0) >= 0.5)

    return yes


def _noul(instructions: str, yes: str, no: str) -> Noul:
    return Noul(instructions=instructions, criteria={'true': yes, 'false': no})
