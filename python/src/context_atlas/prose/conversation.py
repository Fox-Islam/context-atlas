"""
Conversations: transcripts, minutes, chat logs, discussion pages. Blocks carry who spoke in
`meta.speaker`, and optionally `turn`, `time` and `reply_to` (the turn a message answers).

Each turn becomes message units of at most PART_TOKENS tokens, headed `[section | speaker, time]`.
Links: `reply_to` (from the source, else a Jev choice over the six messages before), and, judged
per message part because a long speech hides its outcome at the end, `decides` (an outcome to
what it settles) and `corrects` (a later message to the earlier one it updates).

A block with `meta.attachment` (a tool result, a pasted file) is a turn of its own: `output`
units `a<n>` split by lines, linked `output_of` the message before. Jev judges messages only.
"""
from __future__ import annotations

import math
import re
from functools import cmp_to_key
from typing import Any

from typesafe_sdk import Choice, Noul

from .. import compat, jev, text
from ..blocks import Block
from ..config import Config
from ..document import Link, Unit
from ..index import terms
from ..jev import Jev
from . import profiles

PART_TOKENS = 400

REPLY_WINDOW = 6

_HEADER = re.compile(r'^\[[^\]\n]*\]( \(continued\))?\n')


def build(
    client: Jev | None,
    config: Config,
    section: list[tuple[int, Block, list[str]]],
    offset: int,
    prior: list[Unit] | None = None,
) -> dict[str, Any]:
    """
    `{units, links}` for one conversational section's blocks, given with their index and heading
    path: the new units, and links from them. `prior` holds the message and output units of the
    conversation these blocks continue, in order; new turns may link back to them.
    """
    prior = prior or []
    turns, first_part, by_source = _prior_turns(prior)
    fresh = len(turns)
    turns = [*turns, *_turns(section)]
    units: list[Unit] = []

    for t, turn in enumerate(turns):
        if t in first_part:
            continue
        by_source[turn['turn'] if turn['turn'] is not None else str(t)] = t
        attachment = turn['attachment']
        parts = (
            _split('\n'.join(turn['texts']), config.chars_per_token)
            if attachment
            else _parts(turn['texts'], config.chars_per_token)
        )
        for p, part in enumerate(parts):
            unit_id = f'{"a" if attachment else "m"}{offset + len(units) + 1}'
            first_part.setdefault(t, unit_id)
            meta = {
                'speaker': turn['speaker'],
                'time': turn['time'],
                'turn': first_part[t],
                'blocks': turn['blocks'],
                'profile': profiles.CONVERSATIONAL,
            }
            if attachment:
                meta['attachment'] = True
            if turn['turn'] is not None:
                meta['source_turn'] = turn['turn']
            body = _header(turn) + (' (continued)' if p > 0 else '') + '\n' + part
            units.append(Unit(unit_id, 'output' if attachment else 'message', body, turn['path'], meta))

    replies = _replies(
        client, config.conversation_replies, turns, by_source, first_part, [*prior, *units], fresh
    )
    earlier = [unit for unit in prior if unit.kind == 'message']
    outcomes, decisions = (
        _decisions(client, [unit for unit in units if unit.kind == 'message'], earlier)
        if config.conversation_outcomes
        else (set(), [])
    )
    units = [Unit(u.id, u.kind, u.text, u.path, {**u.meta, 'outcome': True}) if u.id in outcomes else u for u in units]
    corrections = (
        _corrections(
            client,
            [unit for unit in units if unit.kind == 'message'],
            earlier,
            [link for link in replies if link.type == 'reply_to'],
        )
        if config.conversation_corrections
        else []
    )

    return {'units': units, 'links': [*replies, *decisions, *corrections]}


def body(message: str) -> str:
    """A message's text without its `[section | speaker, time]` header line."""
    return _HEADER.sub('', message, count=1)


def term_counts(source: str) -> dict[str, int]:
    counts: dict[str, int] = {}
    for term in terms(body(source)):
        counts[term] = counts.get(term, 0) + 1

    return counts


def cosine(a: dict[str, int], b: dict[str, int]) -> float:
    dot = sum(count * b.get(term, 0) for term, count in a.items())
    norm = math.sqrt(sum(c * c for c in a.values())) * math.sqrt(sum(c * c for c in b.values()))

    return dot / norm if norm > 0 else 0.0


def similar(unit: Unit, units: list[Unit], limit: int) -> list[Unit]:
    """The units most similar to this one (cosine of term counts), most similar first, ties in document order."""
    vector = term_counts(unit.text)
    scores = [cosine(vector, term_counts(other.text)) for other in units]
    ranked = sorted(range(len(units)), key=lambda i: -scores[i])

    return [units[i] for i in ranked if scores[i] > 0.0][:limit]


def _prior_turns(prior: list[Unit]) -> tuple[list[dict[str, Any]], dict[int, str], dict[str, int]]:
    """
    The turns of the units a conversation continues from, with their first parts and their
    source turn ids, so new turns can link back to them.
    """
    turns: list[dict[str, Any]] = []
    first_part: dict[int, str] = {}
    by_source: dict[str, int] = {}
    for unit in prior:
        if unit.meta.get('turn', unit.id) != unit.id:
            continue
        t = len(turns)
        source = _string(unit.meta.get('source_turn'))
        speaker = unit.meta.get('speaker')
        turns.append({
            'turn': source,
            'speaker': speaker if isinstance(speaker, str) else None,
            'speaker_full': None,
            'time': None,
            'reply_to': None,
            'path': unit.path,
            'texts': [],
            'blocks': [],
            'attachment': unit.kind == 'output',
        })
        first_part[t] = unit.id
        by_source[source if source is not None else str(t)] = t

    return turns, first_part, by_source


def _split(source: str, chars_per_token: int) -> list[str]:
    """
    An attachment's text cut into parts of at most PART_TOKENS: whole lines while they fit, and a
    line longer than that cut every PART_TOKENS * chars_per_token characters.
    """
    width = PART_TOKENS * max(1, chars_per_token)
    parts: list[str] = []
    buffer: list[str] = []
    size = 0
    for line in source.split('\n'):
        pieces = [line[i : i + width] for i in range(0, len(line), width)] if len(line) > width else [line]
        for piece in pieces:
            tokens = text.tokens(piece, chars_per_token)
            if buffer and size + tokens > PART_TOKENS:
                parts.append('\n'.join(buffer))
                buffer, size = [], 0
            buffer.append(piece)
            size += tokens
    parts.append('\n'.join(buffer))

    return parts


def _header(turn: dict[str, Any]) -> str:
    """`[section | speaker, time]`, naming the speaker in full when the source does."""
    name = turn['speaker_full'] if turn['speaker_full'] is not None else turn['speaker']
    who = (name if name is not None else 'unattributed') + (f', {turn["time"]}' if turn['time'] is not None else '')
    where = ' > '.join(turn['path']) + ' | ' if turn['path'] else ''

    return f'[{where}{who}]'


def _turns(section: list[tuple[int, Block, list[str]]]) -> list[dict[str, Any]]:
    """
    Consecutive blocks with the same turn (or, without turn numbers, the same speaker) form a
    turn; a block with `meta.attachment` is a turn of its own.
    """
    turns: list[dict[str, Any]] = []
    for i, block, path in section:
        if block.is_heading():
            continue
        meta = block.meta if isinstance(block.meta, dict) else {}
        speaker = meta.get('speaker') if isinstance(meta.get('speaker'), str) else None
        turn = _string(meta.get('turn'))
        attachment = meta.get('attachment', False) is True
        last = turns[-1] if turns else None
        continues = last is not None and not attachment and not last['attachment'] and last['path'] == path
        if continues and (last['turn'] == turn if turn is not None else last['speaker'] == speaker):
            last['texts'].append(text.trim(block.text))
            last['blocks'].append(i)
            continue
        turns.append({
            'turn': turn,
            'speaker': speaker,
            'speaker_full': meta.get('speaker_full') if isinstance(meta.get('speaker_full'), str) else None,
            'time': _string(meta.get('time')),
            'reply_to': _string(meta.get('reply_to')),
            'path': path,
            'texts': [text.trim(block.text)],
            'blocks': [i],
            'attachment': attachment,
        })

    return turns


def _parts(texts: list[str], chars_per_token: int) -> list[str]:
    """A turn's text split into parts of at most PART_TOKENS, at block boundaries."""
    parts: list[str] = []
    buffer: list[str] = []
    size = 0
    for piece in texts:
        tokens = text.tokens(piece, chars_per_token)
        if buffer and size + tokens > PART_TOKENS:
            parts.append('\n'.join(buffer))
            buffer, size = [], 0
        buffer.append(piece)
        size += tokens
    if buffer:
        parts.append('\n'.join(buffer))

    return parts


def _replies(
    client: Jev | None,
    detect: bool,
    turns: list[dict[str, Any]],
    by_source: dict[str, int],
    first_part: dict[int, str],
    units: list[Unit],
    fresh: int,
) -> list[Link]:
    """
    Links from the turns after the first `fresh`, which already exist: source replies, outputs
    to the message before them, and, when `detect`, Jev's choice of the message replied to.
    """
    links: dict[int, Link] = {}
    ask: dict[int, list[int]] = {}
    messages: list[int] = []
    for t, turn in enumerate(turns):
        if t < fresh:
            if not turn['attachment']:
                messages.append(t)
            continue
        parent = by_source.get(turn['reply_to']) if turn['reply_to'] is not None else None
        if parent is not None and parent < t:
            links[t] = Link('output_of' if turn['attachment'] else 'reply_to', first_part[t], first_part[parent])
        elif turn['attachment'] and messages:
            links[t] = Link('output_of', first_part[t], first_part[messages[-1]])
        elif (
            detect and not turn['attachment'] and turn['reply_to'] is None and messages and turn['speaker'] is not None
        ):
            ask[t] = messages[-REPLY_WINDOW:]
        if not turn['attachment']:
            messages.append(t)

    by_id = {unit.id: unit.text for unit in units}
    first_text = {t: by_id[unit_id] for t, unit_id in first_part.items()}

    asked = list(ask)
    for start in range(0, len(asked), 6):
        window: dict[str, str] = {}
        questions: dict[str, list[str]] = {}
        for t in asked[start : start + 6]:
            earlier = ask[t]
            for m in [*earlier, t]:
                window[f'm{m}'] = text.head(first_text[m], 400)
            questions[f'r{t}'] = [f'm{m}' for m in earlier]
        ordered = {key: window[key] for key in sorted(window, key=cmp_to_key(compat.natural_compare))}
        response = (
            jev.send(
                client,
                {
                    'state': {'messages in order': ordered},
                    'questions': {
                        key: Choice(
                            instructions=(
                                f'Which earlier message is `m{key[1:]}` a direct reply to? A reply answers, agrees or '
                                'disagrees with, or takes up a point from that message. Pick `none` if '
                                f'`m{key[1:]}` starts a new point rather than replying.'
                            ),
                            criteria=dict.fromkeys([*options, 'none']),
                        )
                        for key, options in questions.items()
                    },
                },
            )
            if client is not None
            else None
        )
        if response is None:
            continue
        for key, options in questions.items():
            pick = jev.choice(response, key)
            if pick is not None and pick in options:
                t = int(key[1:])
                links[t] = Link('reply_to', first_part[t], first_part[int(pick[1:])])

    return [links[t] for t in sorted(links)]


def _decisions(client: Jev | None, units: list[Unit], prior: list[Unit]) -> tuple[set[str], list[Link]]:
    """
    The ids of the message parts in `units` that record an outcome, and each outcome's link to
    the earlier message it settles, which may be one of the `prior` parts before them.
    """
    outcomes: dict[str, Unit] = {}
    for start in range(0, len(units), 20):
        labels = {unit.id: unit for unit in units[start : start + 20]}
        response = jev.send(
            client,
            {
                'state': {'messages': {label: _head_and_end(unit.text, 600) for label, unit in labels.items()}},
                'questions': {
                    label: _noul(
                        f'Does `{label}` record an outcome of the discussion?',
                        f'`{label}` states what was decided, agreed, accepted, rejected or concluded, such as a '
                        'closing statement, a vote result or a resolution',
                        f'`{label}` argues, asks, proposes or comments without recording an outcome',
                    )
                    for label in labels
                },
            },
        ) if client is not None else None
        if response is None:
            continue
        outcomes.update({label: unit for label, unit in labels.items() if _probability(response, label) >= 0.5})

    found = []
    for outcome in outcomes.values():
        earlier = _earlier([*prior, *units], outcome)
        if not earlier:
            continue
        first_of_section: dict[str, Unit] = {}
        for unit in earlier:
            first_of_section.setdefault(' > '.join(unit.path), unit)
        candidates = _unique([*first_of_section.values(), *similar(outcome, earlier, 5)])
        labels = {unit.id: unit for unit in candidates}
        response = jev.send(
            client,
            {
                'state': {
                    'outcome': _head_and_end(outcome.text, 800),
                    'earlier messages': {label: text.head(unit.text, 400) for label, unit in labels.items()},
                },
                'questions': {
                    'settles': Choice(
                        instructions=(
                            'Which earlier message states the proposal, motion or question that the outcome settles? '
                            'Pick `none` if none of them does.'
                        ),
                        criteria=dict.fromkeys([*labels, 'none']),
                    )
                },
            },
        ) if client is not None else None
        pick = jev.choice(response, 'settles') if response is not None else None
        if pick is not None and pick in labels:
            found.append(Link('decides', outcome.id, pick))

    return set(outcomes), found


def _corrections(client: Jev | None, units: list[Unit], prior: list[Unit], replies: list[Link]) -> list[Link]:
    """
    Each part in `units` with its reply parent and three most similar earlier parts, `prior`
    included; Jev keeps the pairs where the later corrects the earlier.
    """
    parent = {link.from_: link.to for link in replies}

    pairs: list[tuple[Unit, Unit]] = []
    for unit in units:
        earlier = _earlier([*prior, *units], unit)
        if not earlier or unit.meta['speaker'] is None:
            continue
        candidates = similar(unit, earlier, 3)
        if unit.id in parent:
            candidates = [*(e for e in earlier if e.id == parent[unit.id]), *candidates]
        pairs.extend((candidate, unit) for candidate in _unique(candidates))
    keyed = {f'k{i}': pair for i, pair in enumerate(pairs)}

    found = []
    keys = list(keyed)
    for start in range(0, len(keys), 15):
        batch = keys[start : start + 15]
        response = jev.send(
            client,
            {
                'state': {
                    'pairs': {
                        key: {
                            'earlier': _head_and_end(keyed[key][0].text, 500),
                            'later': _head_and_end(keyed[key][1].text, 500),
                        }
                        for key in batch
                    }
                },
                'questions': {
                    key: _noul(
                        f'In pair {key}, does `later` correct or update something stated in `earlier`?',
                        '`later` says a fact, figure, claim or position in `earlier` is wrong, incomplete or out of '
                        'date, gives a different figure for the same thing, or the earlier speaker takes it back',
                        '`later` agrees with, adds to, answers or is unrelated to `earlier` without changing what it '
                        'states',
                    )
                    for key in batch
                },
            },
        ) if client is not None else None
        if response is None:
            continue
        found.extend(
            Link('corrects', keyed[key][1].id, keyed[key][0].id) for key in batch if _probability(response, key) >= 0.5
        )

    return found


def _earlier(units: list[Unit], unit: Unit) -> list[Unit]:
    out = []
    for other in units:
        if other.id == unit.id:
            break
        out.append(other)

    return out


def _head_and_end(message: str, chars: int) -> str:
    """A long message shown as its start and its end: outcomes and corrections often close it."""
    message = text.trim(message)
    if len(message) <= chars:
        return message

    return message[: chars // 2] + ' … ' + message[-(chars // 2) :]


def _unique(units: list[Unit]) -> list[Unit]:
    found: dict[str, Unit] = {}
    for unit in units:
        found.setdefault(unit.id, unit)

    return list(found.values())


def _string(value: Any) -> str | None:
    """PHP's `isset($v) ? (string) $v : null` for a meta value."""
    if value is None:
        return None
    if value is True:
        return '1'
    if value is False:
        return ''

    return compat.to_str(value)


def _noul(instructions: str, yes: str, no: str) -> Noul:
    return Noul(instructions=instructions, criteria={'true': yes, 'false': no})


def _probability(response: Any, name: str) -> float:
    found = jev.noul(response, name)

    return 0.0 if found is None else found
