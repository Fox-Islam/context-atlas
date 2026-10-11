from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any


@dataclass(frozen=True)
class Passage:
    """A search hit to rank: the caller's id for it, its text, and the heading path that names it for Jev."""

    id: str
    text: str
    heading_path: list[str] = field(default_factory=list)

    @staticmethod
    def from_dict(value: dict[str, Any]) -> Passage:
        """Reads `{"id", "text", "heading_path"?}`; a heading path given as one string is a path of one."""
        path = value.get('heading_path')
        path = [] if path is None else list(path.values()) if isinstance(path, dict) else path

        return Passage(
            _string(value.get('id')),
            _string(value.get('text')),
            [_string(item) for item in path] if isinstance(path, list) else [_string(path)],
        )


@dataclass(frozen=True)
class RankedPassage:
    passage: Passage
    score: float

    def to_dict(self) -> dict[str, Any]:
        return {'id': self.passage.id, 'score': self.score}


def _string(value: Any) -> str:
    """The string PHP's `(string)` cast gives for a JSON value, for ids and texts given as numbers."""
    if value is None or value is False:
        return ''
    if value is True:
        return '1'
    if isinstance(value, float) and value.is_integer() and abs(value) < 1e15:
        return str(int(value))

    return str(value)
