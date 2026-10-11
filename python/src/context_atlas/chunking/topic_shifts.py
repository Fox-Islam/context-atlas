from __future__ import annotations

import re
from dataclasses import dataclass, field

from .. import text
from ..blocks import Block, BlockType
from ..config import Config
from ..jev import Jev
from . import cut_planner, seam_scorer
from .breadcrumbs import Crumb
from .seam_scorer import Unit

_KEY = re.compile(r'b(\d+)(?:s(\d+))?')


@dataclass(frozen=True)
class Shifts:
    """Indexes of the blocks that open a topic, and of the sentences that do inside long paragraphs."""

    blocks: set[int] = field(default_factory=set)
    sentences: dict[int, set[int]] = field(default_factory=dict)


def find(
    client: Jev,
    blocks: list[Block],
    tokens: list[int],
    crumbs: list[Crumb],
    config: Config,
    quiet_speech: bool = False,
) -> Shifts | None:
    """
    Where long stretches of text change topic, so they are chunked there instead of wherever the
    size limit falls. None when there was nothing to ask about or Jev did not answer.

    Headings already split sections, so the stretches asked about are the body blocks of a
    section holding more than `min_tokens`, and the sentences of a paragraph longer than
    `split_long_paragraph_at`. With `quiet_speech`, stretches that are part of a conversation are
    left out.
    """
    stretches = [*_section_stretches(blocks, tokens, crumbs, config), *_sentence_stretches(blocks, tokens, config)]
    if quiet_speech:
        stretches = [units for units in stretches if not _spoken(blocks, units)]
    pairs = {
        units[i].key: (units[i - 1].text, units[i].text) for units in stretches for i in range(1, len(units))
    }

    if not pairs:
        return None

    scores = seam_scorer.score(client, pairs, config)

    if scores is None:
        return None

    shifts = Shifts()

    for units in stretches:
        for key in cut_planner.cuts(units, scores, config):
            match = _KEY.fullmatch(key)
            if match is None:
                continue
            if match.group(2) is None:
                shifts.blocks.add(int(match.group(1)))
            else:
                shifts.sentences.setdefault(int(match.group(1)), set()).add(int(match.group(2)))

    return shifts


def _spoken(blocks: list[Block], units: list[Unit]) -> bool:
    """
    Whether the blocks of a stretch that name a speaker hold at least half its text: the stretch
    is part of a conversation, which is not chunked.
    """
    total = 0
    spoken = 0
    for unit in units:
        match = _KEY.match(unit.key)
        block = blocks[int(match.group(1))] if match is not None else None
        length = len(text.trim(unit.text))
        total += length
        speaker = block.meta.get('speaker') if block is not None and isinstance(block.meta, dict) else None
        if isinstance(speaker, str) and speaker != '':
            spoken += length

    return spoken > 0 and 2 * spoken >= total


def _section_stretches(blocks: list[Block], tokens: list[int], crumbs: list[Crumb], config: Config) -> list[list[Unit]]:
    by_section: dict[int, list[Unit]] = {}

    for index, block in enumerate(blocks):
        if not block.is_heading():
            by_section.setdefault(crumbs[index].section, []).append(Unit(f'b{index}', block.text, tokens[index]))

    return [
        units
        for units in by_section.values()
        if len(units) > 1 and sum(unit.tokens for unit in units) > config.min_tokens
    ]


def _sentence_stretches(blocks: list[Block], tokens: list[int], config: Config) -> list[list[Unit]]:
    stretches: list[list[Unit]] = []

    for index, block in enumerate(blocks):
        if block.type is not BlockType.PARAGRAPH or tokens[index] <= config.split_long_paragraph_at:
            continue
        units = [
            Unit(f'b{index}s{i}', sentence, text.tokens(sentence, config.chars_per_token))
            for i, sentence in enumerate(text.sentences(block.text))
        ]
        if len(units) > 1:
            stretches.append(units)

    return stretches
