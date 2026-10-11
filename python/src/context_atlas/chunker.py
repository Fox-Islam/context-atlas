from __future__ import annotations

from collections.abc import Sequence

from . import text
from .blocks import Block
from .chunk import Chunked, Heading
from .chunking import topic_shifts
from .chunking.breadcrumbs import breadcrumbs
from .chunking.packer import Packer
from .chunking.topic_shifts import Shifts
from .config import Config
from .jev import Jev


class Chunker:
    """
    Splits a document's blocks into chunks at headings, at size limits, and, given Jev, where
    the topic changes inside a section.

    Without Jev, or when Jev does not answer, the chunks follow headings and sizes only.

        chunked = Chunker(TypeSafeClient()).chunk(parse_markdown(markdown))
    """

    def __init__(self, jev: Jev | None = None, config: Config | None = None) -> None:
        self._jev = jev
        self._config = config or Config()

    def chunk(self, blocks: Sequence[Block], quiet_speech: bool = False) -> Chunked:
        """
        Chunk `blocks` and outline indexes are positions in `blocks`, counting the empty ones left
        out. `quiet_speech` asks nothing about stretches mostly made of blocks that name a speaker.
        """
        config = self._config
        origin = [index for index, block in enumerate(blocks) if text.trim(block.text) != '']
        kept = [blocks[index] for index in origin]
        tokens = [text.tokens(text.trim(block.text), config.chars_per_token) for block in kept]
        crumbs = breadcrumbs(kept)
        shifts = None if self._jev is None else topic_shifts.find(self._jev, kept, tokens, crumbs, config, quiet_speech)
        chunks = Packer(kept, tokens, crumbs, shifts or Shifts(), config).pack()
        outline: list[Heading] = [
            {'block': origin[index], 'title': text.squash(block.text), 'level': int(block.level or 0)}
            for index, block in enumerate(kept)
            if block.is_heading()
        ]

        return Chunked(
            [chunk.with_blocks([origin[index] for index in chunk.blocks]) for chunk in chunks],
            outline,
            shifts is not None,
        )
