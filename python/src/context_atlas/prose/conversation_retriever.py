"""
Gathers messages for a question about a conversation. One request ranks the candidates, asks
what shape a full answer has and whose words it is about; the shape decides which links are
followed:

- every kept message shorter than 60 tokens brings the message it replies to, since "I agree"
  means nothing alone;
- every kept output (a tool result or other attachment) brings the message it is `output_of`;
- `exchange` adds the message replied to and the two replies closest to the question;
- `correction` adds what the message corrects and what corrects it;
- `outcome` adds what a kept message decides or is decided by, and the outcome closest to the
  question with what it settles;
- `one person` asks of up to 12 more of that speaker's messages whether they add to the answer;
- `overview` asks the same of the next 18 candidates.

"Closest to the question" is term overlap with the question, so retrieval needs no vectors.
"""
from __future__ import annotations

import re
from typing import Any

from typesafe_sdk import Choice, Noul

from .. import jev, text
from ..document import Document, Unit
from ..jev import Jev
from . import conversation, profiles
from . import retriever as shared

KEEP = 0.4

MESSAGE_CHARS = 1600

CHECK_CHARS = 1200

SHORT_TOKENS = 60

NOBODY = 'nobody in particular'

_FULL_NAME = re.compile(r'^\[(?:[^\]\n]* \| )?([^\]\n]*?)(?:, [^,\]\n]*)?\]')

Candidate = tuple[Document, Unit]


def retrieve(client: Jev, query: str, candidates: list[Candidate], chars_per_token: int = 4) -> dict[str, Any] | None:
    """`{units, trace}` for candidates given best first, or None when Jev did not answer; no units is an abstention."""
    pool = candidates[: profiles.POOL[profiles.CONVERSATIONAL]]
    labels = {f'message-{i}': candidate for i, candidate in enumerate([*pool, *_outcomes_to_add(query, pool)])}
    speakers = _speakers(labels)

    questions: dict[str, Noul | Choice] = {f'c_{label}': shared._answers(label) for label in labels}
    questions['best'] = Choice(
        instructions='Which single one answers the question most directly?', criteria=dict.fromkeys(labels)
    )
    shapes = profiles.SCOPES[profiles.CONVERSATIONAL]
    questions['scope'] = Choice(instructions='What does a full answer to the question need?', criteria=dict(shapes))
    if speakers:
        questions['speaker'] = Choice(
            instructions='Whose words is the question about?',
            criteria={**speakers, NOBODY: 'the question does not single out one person'},
        )
    state = {
        shared.QUESTION: query,
        'the messages the search found': {
            label: text.head(unit.text, MESSAGE_CHARS) for label, (_, unit) in labels.items()
        },
    }

    response = jev.send(client, {'state': state, 'questions': questions})
    if response is None:
        return None

    scores = {label: _probability(response, f'c_{label}') for label in labels}
    kept = sorted((label for label, score in scores.items() if score >= KEEP), key=lambda label: -scores[label])
    best = jev.choice(response, 'best')
    if best is not None and best in kept:
        kept = [best, *(label for label in kept if label != best)]
    chosen = jev.choice(response, 'scope')
    shape = chosen if chosen is not None and chosen in shapes else 'one message'
    picked = jev.choice(response, 'speaker')
    speaker = picked if picked is not None and picked in speakers else NOBODY
    trace = {'profile': profiles.CONVERSATIONAL, 'scope': shape, 'speaker': speaker, 'scores': scores}

    if not kept:
        return {'units': [], 'trace': trace}

    chosen_units = [labels[label] for label in kept[: 6 if shape == 'overview' else 3]]
    gathered = _expand(client, query, chosen_units, candidates, shape, speaker, chars_per_token)

    return {'units': shared._in_document_order(shared._unique(gathered)), 'trace': trace}


def _outcomes_to_add(query: str, pool: list[Candidate]) -> list[Candidate]:
    """The two outcome parts of the pool's documents closest to the question, when the search missed them."""
    have = {shared._key(candidate) for candidate in pool}

    return [found for found in _closest(query, _outcomes(pool)) if shared._key(found) not in have][:2]


def _outcomes(candidates: list[Candidate]) -> list[Candidate]:
    return [
        (document, unit)
        for document in _documents(candidates)
        for unit in document.units
        if unit.meta.get('outcome') is True
    ]


def _speakers(labels: dict[str, Candidate]) -> dict[str, str]:
    """The speakers of the candidates' documents, keyed by slug, with the name the source gives them in full."""
    speakers: dict[str, str] = {}
    for document in _documents(list(labels.values())):
        for unit in document.units:
            speaker = unit.meta.get('speaker')
            if isinstance(speaker, str) and speaker != '':
                name = _full_name(unit)
                speakers.setdefault(_slug(speaker), name if name is not None else speaker)

    return speakers


def _full_name(unit: Unit) -> str | None:
    match = _FULL_NAME.match(unit.text)

    return match.group(1) if match and match.group(1) != '' else None


def _slug(speaker: str) -> str:
    """A speaker's key: the slug, or `speaker` where PHP's `?:` treats it as empty (`''` or `'0'`)."""
    found = text.slug(speaker, 30)

    return found if found not in ('', '0') else 'speaker'


def _expand(
    client: Jev,
    query: str,
    kept: list[Candidate],
    candidates: list[Candidate],
    shape: str,
    speaker: str,
    chars_per_token: int,
) -> list[Candidate]:
    add: list[Candidate] = []
    for candidate in kept:
        if candidate[1].kind == 'output':
            add.extend(_closest_part(query, owner) for owner in _linked_from(candidate, 'output_of'))
            continue
        parent = _parent(candidate)
        if parent is not None and (shape == 'exchange' or _turn_tokens(candidate, chars_per_token) < SHORT_TOKENS):
            add.append(_closest_part(query, parent))
        if shape == 'exchange':
            add.extend(_closest_part(query, reply) for reply in _closest(query, _replies(candidate))[:2])
        elif shape == 'correction':
            add.extend(_linked(candidate, 'corrects'))
        elif shape == 'outcome':
            add.extend(_linked(candidate, 'decides'))

    if shape == 'outcome':
        add.extend(_nearest_outcome(query, kept))
    if shape == 'one person' and speaker != NOBODY:
        add.extend(_check(client, query, _closest(query, _spoken_by(kept, speaker))[:12]))
    if shape == 'overview':
        have = {shared._key(candidate) for candidate in kept}
        add.extend(_check(client, query, [found for found in candidates[:18] if shared._key(found) not in have]))

    return [*kept, *add]


def _parent(candidate: Candidate) -> Candidate | None:
    """The first part of the turn a message replies to."""
    parts = _turn(candidate)
    if not parts:
        return None
    document = candidate[0]
    for link in document.links:
        if link.type == 'reply_to' and link.from_ == parts[0][1].id:
            unit = document.unit(link.to)
            return None if unit is None else (document, unit)

    return None


def _replies(candidate: Candidate) -> list[Candidate]:
    """The first parts of the turns that reply to this message's turn."""
    parts = _turn(candidate)
    document = candidate[0]
    found = []
    for link in document.links:
        unit = document.unit(link.from_) if link.type == 'reply_to' and parts and link.to == parts[0][1].id else None
        if unit is not None:
            found.append((document, unit))

    return found


def _linked_from(candidate: Candidate, kind: str) -> list[Candidate]:
    """Units that the first part of this unit's turn links to by `kind`."""
    parts = _turn(candidate)
    first = parts[0][1].id if parts else candidate[1].id
    document = candidate[0]
    found = []
    for link in document.links:
        unit = document.unit(link.to) if link.type == kind and link.from_ == first else None
        if unit is not None:
            found.append((document, unit))

    return found


def _linked(candidate: Candidate, kind: str) -> list[Candidate]:
    """Units linked to any part of this message's turn by `kind`, in either direction."""
    parts = {unit.id for _, unit in _turn(candidate)}
    document = candidate[0]
    found = []
    for link in document.links:
        if link.type != kind:
            continue
        other = link.to if link.from_ in parts else link.from_ if link.to in parts else None
        unit = document.unit(other) if other is not None else None
        if unit is not None:
            found.append((document, unit))

    return found


def _nearest_outcome(query: str, kept: list[Candidate]) -> list[Candidate]:
    """
    Of the outcome messages in the kept messages' documents, the one closest to the question,
    with what it settles.
    """
    ranked = _closest(query, _outcomes(kept))
    if not ranked:
        return []
    document, top = ranked[0]
    settled = [
        (document, unit)
        for link in document.links
        if link.type == 'decides' and link.from_ == top.id and (unit := document.unit(link.to)) is not None
    ]

    return [ranked[0], *settled]


def _spoken_by(kept: list[Candidate], speaker: str) -> list[Candidate]:
    """Every message part of the kept messages' documents by this speaker, except those kept."""
    have = {shared._key(candidate) for candidate in kept}

    return [
        (document, unit)
        for document in _documents(kept)
        for unit in document.units
        if isinstance(unit.meta.get('speaker'), str)
        and _slug(unit.meta['speaker']) == speaker
        and shared._key((document, unit)) not in have
    ]


def _turn(candidate: Candidate) -> list[Candidate]:
    """The parts of the turn a message or output part belongs to, in order."""
    document, unit = candidate
    turn = unit.meta.get('turn')
    if turn is None:
        return []

    return [(document, other) for other in document.units if other.kind == unit.kind and other.meta.get('turn') == turn]


def _turn_tokens(candidate: Candidate, chars_per_token: int) -> int:
    return sum(text.tokens(conversation.body(unit.text), chars_per_token) for _, unit in _turn(candidate))


def _closest_part(query: str, candidate: Candidate) -> Candidate:
    """Of the parts of the turn this message part starts, the one closest to the question."""
    ranked = _closest(query, _turn(candidate))

    return ranked[0] if ranked else candidate


def _closest(query: str, candidates: list[Candidate]) -> list[Candidate]:
    """Candidates by term overlap with the question, closest first, ties in the order given."""
    question = conversation.term_counts(query)
    scores = [conversation.cosine(question, conversation.term_counts(unit.text)) for _, unit in candidates]

    return [candidates[i] for i in sorted(range(len(candidates)), key=lambda i: -scores[i])]


def _check(client: Jev, query: str, candidates: list[Candidate]) -> list[Candidate]:
    """The messages Jev judges to add to the answer."""
    if not candidates:
        return []

    labels = {f'message-{i}': candidate for i, candidate in enumerate(candidates)}
    response = jev.send(
        client,
        {
            'state': {
                shared.QUESTION: query,
                'messages': {label: text.head(unit.text, CHECK_CHARS) for label, (_, unit) in labels.items()},
            },
            'questions': {
                f'c_{label}': Noul(
                    instructions=f'Does `{label}` contain part of what the question asks for?',
                    criteria={
                        'true': f'`{label}` states something a full answer would include',
                        'false': f'`{label}` adds nothing the answer needs',
                    },
                )
                for label in labels
            },
        },
    )
    if response is None:
        return []

    return [candidate for label, candidate in labels.items() if _probability(response, f'c_{label}') >= 0.5]


def _documents(candidates: list[Candidate]) -> list[Document]:
    found: dict[str, Document] = {}
    for document, _ in candidates:
        found.setdefault(document.id, document)

    return list(found.values())


def _probability(response: Any, name: str) -> float:
    found = jev.noul(response, name)

    return 0.0 if found is None else found
