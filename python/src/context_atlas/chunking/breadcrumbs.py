from __future__ import annotations

from dataclasses import dataclass

from .. import text
from ..blocks import Block


@dataclass(frozen=True)
class Crumb:
    path: list[str]
    section: int
    level: int | None


def breadcrumbs(blocks: list[Block]) -> list[Crumb]:
    """
    The heading path, section number and level of every block.

    The path holds only real headings, keyed by level, so a heading that skips a level (an h1
    followed by an h3) sits directly under its parent instead of repeating it to fill the gap.
    Each heading starts a section.
    """
    path: dict[int, str] = {}
    section = 0
    crumbs: list[Crumb] = []

    for block in blocks:
        if block.is_heading():
            level = int(block.level or 0)
            path = {existing: title for existing, title in path.items() if existing < level}
            path[level] = text.squash(block.text)
            path = dict(sorted(path.items()))
            section += 1

        crumbs.append(Crumb(list(path.values()), section, _level(block, path)))

    return crumbs


def _level(block: Block, path: dict[int, str]) -> int | None:
    if block.is_heading():
        return block.level

    return max(path) if path else None
