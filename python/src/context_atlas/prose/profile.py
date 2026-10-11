"""
Ingests prose: chunks it, gives each passage its section's profile, and builds the links those
profiles use. Without Jev every passage is guidance with no links.
"""
from __future__ import annotations

import re
from collections.abc import Sequence
from typing import Any

from .. import text
from ..blocks import Block
from ..chunk import Chunk
from ..chunker import Chunker
from ..chunking.breadcrumbs import Crumb, breadcrumbs
from ..config import Config
from ..document import Document, Link, Unit
from ..errors import ContextAtlasError
from ..index import build
from ..jev import Jev
from . import conversation, detector, entries, links, profiles, speakers

_ADMONITION = re.compile(r'^(note|tip|warning|caution|important|hint|info)\s*:?$', re.IGNORECASE | re.ASCII)


def ingest(
    client: Jev | None, config: Config, blocks: Sequence[Block], document_id: str, profile: str | None = None
) -> Document:
    """:raises ContextAtlasError: for a profile that does not exist"""
    check_profile(profile)

    if profile is None or profile == profiles.CONVERSATIONAL:
        blocks = speakers.infer(list(blocks))
    chunked = Chunker(client, config).chunk(
        _fold_admonitions(blocks), profile is None or profile == profiles.CONVERSATIONAL
    )
    level = detector.depth(chunked.chunks)
    given = list(blocks)
    crumbs = breadcrumbs(given)
    by_section, detected = _profiles(client, chunked.chunks, profile, _speaking(given, crumbs, level))
    units: list[Unit] = []
    conversations: list[Link] = []
    built: set[str] = set()
    for i, chunk in enumerate(chunked.chunks):
        section = detector.key(chunk.heading_path, level)
        if by_section[section] not in (profiles.REFERENCE, profiles.CONVERSATIONAL):
            meta = {'blocks': chunk.blocks, 'profile': by_section[section]}
            units.append(Unit(f'p{i + 1}', 'passage', chunk.text, chunk.heading_path, meta))
        elif section not in built:
            built.add(section)
            found = _section(given, crumbs, section, level)
            if by_section[section] == profiles.REFERENCE:
                units.extend(_entries(found, len(units)))
            else:
                talk = conversation.build(client, config, found, len(units))
                units.extend(talk['units'])
                conversations.extend(talk['links'])

    return Document(
        document_id,
        detected['profile'],
        units,
        [*_links(client, units), *conversations],
        build(units),
        detected,
        {'outline': chunked.outline, 'sections': by_section},
    )


def check_profile(profile: str | None) -> None:
    """:raises ContextAtlasError: for a profile that is not a prose profile"""
    if profile is not None and (not profiles.exists(profile) or profile == profiles.MARKUP):
        names = ', '.join(name for name in profiles.DESCRIPTIONS if name != profiles.MARKUP)
        raise ContextAtlasError(f'Unknown prose profile "{profile}"; use one of: {names}.')


def _section(blocks: list[Block], crumbs: list[Crumb], section: str, level: int) -> list[tuple[int, Block, list[str]]]:
    """
    The non-empty blocks of one section with their index and heading path. Paths come from the
    blocks as given, before admonition headings are folded, as in the PHP package.
    """
    return [
        (i, block, crumbs[i].path)
        for i, block in enumerate(blocks)
        if detector.key(crumbs[i].path, level) == section and text.trim(block.text) != ''
    ]


def _speaking(blocks: list[Block], crumbs: list[Crumb], level: int) -> list[str]:
    """
    The keys of the sections whose blocks with a non-empty `meta.speaker` hold at least half the
    trimmed characters of their non-heading blocks.
    """
    spoken: dict[str, int] = {}
    total: dict[str, int] = {}
    for i, block in enumerate(blocks):
        if block.is_heading():
            continue
        key = detector.key(crumbs[i].path, level)
        length = len(text.trim(block.text))
        total[key] = total.get(key, 0) + length
        speaker = block.meta.get('speaker') if isinstance(block.meta, dict) else None
        if isinstance(speaker, str) and speaker != '':
            spoken[key] = spoken.get(key, 0) + length

    return [key for key, length in spoken.items() if length > 0 and 2 * length >= total[key]]


def _entries(section: list[tuple[int, Block, list[str]]], offset: int) -> list[Unit]:
    """The entries of one reference section, numbered on from the units before them."""
    units = []

    for n, entry in enumerate(entries.build([(block, path) for _, block, path in section])):
        prefix = '' if entry['kind'] == 'row' or not entry['path'] else f'[{" > ".join(entry["path"])}]\n\n'
        meta = {
            'name': entry['name'],
            'row': entry['kind'] == 'row',
            'group': entry.get('group'),
            'fields': [{'field': field, 'values': values} for field, values in entry['fields'].items()],
            'blocks': [section[b][0] for b in entry['blocks']],
            'profile': profiles.REFERENCE,
        }
        units.append(Unit(f'e{offset + n + 1}', 'entry', prefix + entry['text'], list(entry['path']), meta))

    return units


def _fold_admonitions(blocks: Sequence[Block]) -> list[Block]:
    """
    A heading such as "Note" or "Warning" titles an aside, not a section: it becomes a paragraph,
    so the aside stays in its section.
    """
    return [
        Block.paragraph(block.text, block.meta)
        if block.is_heading() and _ADMONITION.match(text.trim(block.text))
        else block
        for block in blocks
    ]


def _profiles(
    client: Jev | None, chunks: list[Chunk], profile: str | None, speaking: list[str]
) -> tuple[dict[str, str], dict[str, Any]]:
    """
    Section key to profile, and the document's `detected`: the profile whose sections hold the
    most tokens, scored by the highest confidence among them. Sections whose blocks name speakers
    are conversational without asking Jev, which is not asked at all when every section is.
    """
    titles = list(detector.sections(chunks))

    if profile is not None:
        return dict.fromkeys(titles, profile), {'profile': profile, 'score': None, 'by': 'caller'}

    sections = dict.fromkeys(titles, profiles.GUIDANCE)
    scores: dict[str, float] = {}
    asked = client is not None and any(title not in speaking for title in sections)
    detected = detector.detect(client, chunks) if asked and client is not None else None
    if detected is not None:
        sections, scores = dict(detected['sections']), dict(detected['scores'])
    for section in speaking:
        if section in sections:
            sections[section] = profiles.CONVERSATIONAL
            scores.pop(section, None)

    level = detector.depth(chunks)
    weights: dict[str, int] = {}
    for chunk in chunks:
        kind = sections[detector.key(chunk.heading_path, level)]
        weights[kind] = weights.get(kind, 0) + chunk.tokens
    main = sorted(weights, key=lambda name: -weights[name])[0]
    main_scores = [score for title, score in scores.items() if sections[title] == main]
    by = 'speakers' if main == profiles.CONVERSATIONAL else 'jev' if detected is not None else 'default'

    return sections, {'profile': main, 'score': max(main_scores) if main_scores else None, 'by': by}


def _links(client: Jev | None, units: list[Unit]) -> list[Link]:
    """Needs first, then defined_by, then refers_to, each over the units whose profile uses it."""
    using: dict[str, list[Unit]] = {}
    for unit in units:
        for kind in profiles.LINKS[unit.meta['profile']]:
            using.setdefault(kind, []).append(unit)

    return [
        *(links.needs(client, using['needs']) if 'needs' in using else []),
        *(links.definitions(client, using['defined_by']) if 'defined_by' in using else []),
        *(links.references(using['refers_to']) if 'refers_to' in using else []),
    ]
