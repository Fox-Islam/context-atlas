from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any


@dataclass(frozen=True)
class Context:
    """
    What retrieval hands to the LLM: the text to put in its context, where each part came from,
    and how it was chosen. `abstained` means the source does not hold what the question needs.
    """

    text: str
    sources: list[dict[str, Any]]
    abstained: bool
    trace: dict[str, Any] = field(default_factory=dict)

    @staticmethod
    def abstain(trace: dict[str, Any] | None = None) -> Context:
        return Context('', [], True, trace or {})

    def to_dict(self) -> dict[str, Any]:
        return {'text': self.text, 'sources': self.sources, 'abstained': self.abstained, 'trace': self.trace}
