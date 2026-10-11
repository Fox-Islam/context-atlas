from __future__ import annotations

from dataclasses import dataclass, replace
from typing import Any, TypedDict


class Heading(TypedDict):
    block: int
    title: str
    level: int


@dataclass(frozen=True)
class Chunk:
    """
    A passage ready to embed.

    `text` is the heading path in brackets, any overlap carried from the previous chunk, then
    the chunk's own text. `tokens` counts all of it and `overlap` the carried part. `blocks`
    are indexes into the blocks given to `Chunker.chunk`.
    """

    text: str
    blocks: list[int]
    heading_path: list[str]
    heading_level: int | None
    section: int
    tokens: int
    overlap: int
    topic_start: bool

    def with_blocks(self, blocks: list[int]) -> Chunk:
        return replace(self, blocks=blocks)

    def to_dict(self) -> dict[str, Any]:
        return {
            'text': self.text,
            'blocks': list(self.blocks),
            'heading_path': list(self.heading_path),
            'heading_level': self.heading_level,
            'section': self.section,
            'tokens': self.tokens,
            'overlap': self.overlap,
            'topic_start': self.topic_start,
        }


@dataclass(frozen=True)
class Chunked:
    """The chunks of a document, with its outline: every heading in order."""

    chunks: list[Chunk]
    outline: list[Heading]
    used_jev: bool

    def to_dict(self) -> dict[str, Any]:
        return {
            'chunks': [chunk.to_dict() for chunk in self.chunks],
            'outline': [dict(heading) for heading in self.outline],
            'used_jev': self.used_jev,
        }
