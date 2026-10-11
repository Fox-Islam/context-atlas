from __future__ import annotations

from dataclasses import dataclass, field
from enum import Enum
from typing import Any

from .errors import ContextAtlasError


class BlockType(str, Enum):
    HEADING = 'heading'
    PARAGRAPH = 'paragraph'
    TABLE = 'table'
    CODE = 'code'


@dataclass(frozen=True)
class Block:
    """
    One unit of a document.

    A paragraph is the only kind split into sentences when it is too long; a table or a code
    block is kept whole however long it runs. Only a heading has a `level`. `meta` is carried
    through and never read, so a caller can map chunks back to pages or offsets.
    """

    type: BlockType
    text: str
    level: int | None = None
    meta: dict[str, Any] | list[Any] = field(default_factory=dict)

    @staticmethod
    def heading(text: str, level: int, meta: dict[str, Any] | None = None) -> Block:
        return Block(BlockType.HEADING, text, level, meta or {})

    @staticmethod
    def paragraph(text: str, meta: dict[str, Any] | None = None) -> Block:
        return Block(BlockType.PARAGRAPH, text, None, meta or {})

    @staticmethod
    def from_dict(value: Any) -> Block:
        """
        Reads `{"type", "text", "level"?, "meta"?}`.

        :raises ContextAtlasError: on an unknown type, a text that is not a string, or a
            heading without an integer level
        """
        value = value if isinstance(value, dict) else {}
        kind = next((member for member in BlockType if member.value == value.get('type')), None)
        text = value.get('text')
        level = value.get('level')

        if kind is None or not isinstance(text, str):
            raise ContextAtlasError(
                'A block needs a "type" (heading, paragraph, table or code) and a "text".',
            )
        if kind is BlockType.HEADING and (not isinstance(level, int) or isinstance(level, bool)):
            raise ContextAtlasError('A heading block needs an integer "level".')

        return Block(kind, text, level if kind is BlockType.HEADING else None, _meta(value.get('meta')))

    def is_heading(self) -> bool:
        return self.type is BlockType.HEADING

    def to_dict(self) -> dict[str, Any]:
        """The block as `from_dict` reads it, without an empty `level` or `meta`."""
        value: dict[str, Any] = {'type': self.type.value, 'text': self.text}

        if self.level is not None:
            value['level'] = self.level
        if self.meta:
            value['meta'] = self.meta

        return value


def _meta(value: Any) -> dict[str, Any] | list[Any]:
    """`meta` as PHP's `(array)` cast leaves it: an object or list kept, another value wrapped in a list."""
    if value is None:
        return {}

    return value if isinstance(value, (dict, list)) else [value]
