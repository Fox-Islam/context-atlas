"""
Chooses a profile per section in one Jev request, so a document that appends an FAQ to its
terms gets the normative profile for the terms and guidance for the FAQ.

Sections are taken at the first heading depth where the document splits into two or more, so a
document under a single title is split by the headings below the title.
"""
from __future__ import annotations

from typing import Any

from typesafe_sdk import Choice

from .. import jev, text
from ..chunk import Chunk
from ..jev import Jev
from . import profiles
from .links import body

OPENING = 600


def detect(client: Jev, chunks: list[Chunk]) -> dict[str, dict[str, Any]] | None:
    """`{sections: title => profile, scores: title => confidence}`, or None when Jev did not answer."""
    found = sections(chunks)
    labels = {f's{i + 1}': title for i, title in enumerate(found)}
    response = jev.send(
        client,
        {
            'state': {'sections': {label: found[title] for label, title in labels.items()}},
            'questions': {
                label: Choice(
                    instructions=f'What kind of text is section `{label}`?', criteria=profiles.detectable()
                )
                for label in labels
            },
        },
    )
    if response is None:
        return None

    chosen: dict[str, str] = {}
    scores: dict[str, float] = {}
    for label, title in labels.items():
        choice = jev.choice(response, label)
        chosen[title] = choice if choice is not None and choice in profiles.detectable() else profiles.GUIDANCE
        answer = response.choices.get(label)
        scores[title] = float(answer.confidence) if answer is not None else 0.0

    return {'sections': chosen, 'scores': scores}


def sections(chunks: list[Chunk]) -> dict[str, dict[str, Any]]:
    """
    Sections keyed by `key`, each with its title (the last heading of its key), the opening of
    its first chunk, and its distinct deeper headings, at most 20.
    """
    level = depth(chunks)
    found: dict[str, dict[str, Any]] = {}

    for chunk in chunks:
        path = chunk.heading_path[: level + 1]
        title = path[-1] if path else ''
        opening = text.head(body(chunk.text), OPENING)
        section = found.setdefault(key(chunk.heading_path, level), {'title': title, 'opening': opening, 'headings': []})
        for heading in chunk.heading_path[level + 1 :]:
            if heading not in section['headings'] and len(section['headings']) < 20:
                section['headings'].append(heading)

    return found


def depth(chunks: list[Chunk]) -> int:
    """The first heading depth, from 0, at which the chunks' heading paths take two or more values; 0 when none does."""
    deepest = max([0, *(len(chunk.heading_path) for chunk in chunks)])

    for level in range(deepest):
        if len({chunk.heading_path[level] for chunk in chunks if len(chunk.heading_path) > level}) >= 2:
            return level

    return 0


def key(path: list[str], level: int) -> str:
    """A chunk's section key: its headings down to the section depth, joined by " > "."""
    return ' > '.join(path[: level + 1])
