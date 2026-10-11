"""
Adds blocks to the end of a prose document without rebuilding what it holds, so a growing
transcript costs Jev only for its new turns.

When the document ends in a conversation and the new blocks open with speaker blocks holding at
least half the text before their first heading, those blocks continue that conversation: their
turns may reply to, settle or correct the existing messages. Every other new block is ingested as
prose on its own, its units and links renumbered to follow the document's.
"""
from __future__ import annotations

import re
from collections.abc import Sequence
from typing import Any

from .. import text
from ..blocks import Block
from ..config import Config
from ..document import Document, Link, Unit
from ..errors import ContextAtlasError
from ..index import build
from ..jev import Jev
from ..tabular import profile as tabular
from . import conversation, profiles, speakers
from . import profile as prose

_LAST_NUMBER = re.compile(r'(\d+)$', re.ASCII)

_NUMBERED = re.compile(r'^([a-z]+)(\d+)$', re.ASCII)


def append(
    client: Jev | None, config: Config, document: Document, blocks: Sequence[Block], profile: str | None = None
) -> Document:
    """:raises ContextAtlasError: for a markup or tabular document, or a profile that does not exist"""
    if document.profile in (profiles.MARKUP, tabular.NAME):
        raise ContextAtlasError(f'A {document.profile} document cannot be appended to; ingest it again.')
    prose.check_profile(profile)
    given = list(blocks)
    if profile is None or profile == profiles.CONVERSATIONAL:
        given = speakers.infer(given)
    offset = _last_number(document)
    block_offset = _next_block(document)
    tail = _tail(document)
    lead = _lead(given)

    units: list[Unit] = []
    links: list[Link] = []
    if tail and _spoken(given[:lead]):
        path = tail[-1].path
        section = [
            (block_offset + i, block, path) for i, block in enumerate(given[:lead]) if text.trim(block.text) != ''
        ]
        talk = conversation.build(client, config, section, offset, tail)
        units, links = talk['units'], talk['links']
        block_offset += lead
        given = given[lead:]

    data = document.data
    if given:
        added = prose.ingest(client, config, given, document.id, profile)
        shift = offset + len(units)
        units = [*units, *(_moved(unit, shift, block_offset) for unit in added.units)]
        links = [
            *links,
            *(Link(link.type, _renumber(link.from_, shift), _renumber(link.to, shift)) for link in added.links),
        ]
        data = _merge_data(data, added.data, block_offset)

    every = [*document.units, *units]

    return Document(
        document.id, document.profile, every, [*document.links, *links], build(every), document.detected, data
    )


def _tail(document: Document) -> list[Unit]:
    """
    The message and output units of the conversation the document ends in: those after the last
    unit of another kind or path, or none when the last unit is not a message or output.
    """
    tail: list[Unit] = []
    for unit in reversed(document.units):
        last = tail[0] if tail else unit
        if unit.kind not in ('message', 'output') or unit.path != last.path:
            break
        tail.insert(0, unit)

    return tail


def _lead(blocks: list[Block]) -> int:
    return next((i for i, block in enumerate(blocks) if block.is_heading()), len(blocks))


def _spoken(blocks: list[Block]) -> bool:
    """Whether blocks naming a speaker hold at least half the text."""
    total = 0
    spoken = 0
    for block in blocks:
        length = len(text.trim(block.text))
        total += length
        speaker = block.meta.get('speaker') if isinstance(block.meta, dict) else None
        if isinstance(speaker, str) and speaker != '':
            spoken += length

    return spoken > 0 and 2 * spoken >= total


def _last_number(document: Document) -> int:
    last = 0
    for unit in document.units:
        match = _LAST_NUMBER.search(unit.id)
        if match is not None:
            last = max(last, int(match.group(1)))

    return last


def _next_block(document: Document) -> int:
    following = 0
    for unit in document.units:
        found = unit.meta.get('blocks')
        for block in found if isinstance(found, list) else ([] if found is None else [found]):
            following = max(following, int(block) + 1)
    outline = document.data.get('outline')
    for heading in outline if isinstance(outline, list) else []:
        following = max(following, int(heading.get('block', -1)) + 1)

    return following


def _moved(unit: Unit, shift: int, block_offset: int) -> Unit:
    meta = dict(unit.meta)
    if meta.get('turn') is not None:
        meta['turn'] = _renumber(str(meta['turn']), shift)
    if meta.get('blocks') is not None:
        meta['blocks'] = [block + block_offset for block in meta['blocks']]

    return Unit(_renumber(unit.id, shift), unit.kind, unit.text, unit.path, meta)


def _renumber(unit_id: str, shift: int) -> str:
    return _NUMBERED.sub(lambda match: match.group(1) + str(int(match.group(2)) + shift), unit_id)


def _merge_data(data: dict[str, Any], added: dict[str, Any], block_offset: int) -> dict[str, Any]:
    outline = [
        {'block': int(heading['block']) + block_offset, **{k: v for k, v in heading.items() if k != 'block'}}
        for heading in added.get('outline') or []
    ]
    sections = data.get('sections')
    new_sections = added.get('sections')

    return {
        **data,
        'outline': [*(data.get('outline') or []), *outline],
        'sections': {
            **(sections if isinstance(sections, dict) else {}),
            **(new_sections if isinstance(new_sections, dict) else {}),
        },
    }
