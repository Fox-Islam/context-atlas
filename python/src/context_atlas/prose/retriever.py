"""
Gathers passages for a question: one request ranks the candidates and, for profiles with
scopes, asks how much the question needs; then the links of the kept passages are followed,
each type with its own question.

- `needs` links are always followed: the passage a kept one depends on comes too.
- `refers_to` targets are kept when the answer needs what they say.
- `defined_by` targets get a question of their own plus a pick of the one the answer turns on:
  asked whether a definition is "needed", Jev scores the right one about 0.3.
"""
from __future__ import annotations

import re
import unicodedata
from typing import Any

from typesafe_sdk import Choice, Noul

from .. import compat, jev, text
from ..config import Config
from ..document import Document, Unit
from ..jev import Jev
from . import conversation_retriever, profiles
from .links import body

QUESTION = 'the question being asked'

KEEP = 0.4

KEEP_SEVERAL = 0.5

MIN_STEP_TOKENS = 25

PASSAGE_CHARS = 2400

Candidate = tuple[Document, Unit]


def retrieve(
    client: Jev, query: str, candidates: list[Candidate], config: Config | None = None
) -> dict[str, Any] | None:
    """
    `{units, trace}` for candidates given best first, or None when Jev did not answer; no units is
    an abstention. When the first five candidates span several profiles, each profile gathers
    from its own candidates and the trace is `{profiles: {profile: trace}}` for those answered.
    """
    candidates = _diverse(candidates)
    found = _profiles(candidates)
    if len(found) == 1:
        return retrieve_as(found[0], client, query, candidates, config)

    units: list[Candidate] = []
    traces: dict[str, Any] = {}
    for profile in found:
        result = retrieve_as(profile, client, query, [c for c in candidates if _profile_of(c) == profile], config)
        if result is not None:
            units.extend(result['units'])
            traces[profile] = result['trace']

    return None if not traces else {'units': _in_document_order(_unique(units)), 'trace': {'profiles': traces}}


def _diverse(candidates: list[Candidate]) -> list[Candidate]:
    """Each document's first three candidates, in order, ahead of the rest, so one document cannot fill the pool."""
    seen: dict[str, int] = {}
    first: list[Candidate] = []
    later: list[Candidate] = []
    for candidate in candidates:
        seen[candidate[0].id] = seen.get(candidate[0].id, 0) + 1
        (first if seen[candidate[0].id] <= 3 else later).append(candidate)

    return [*first, *later]


def retrieve_as(
    profile: str, client: Jev, query: str, candidates: list[Candidate], config: Config | None = None
) -> dict[str, Any] | None:
    """Gathers as `profile` from candidates given best first; None when Jev did not answer."""
    if profile == profiles.REFERENCE:
        return _reference(client, query, candidates)
    if profile == profiles.CONVERSATIONAL:
        return conversation_retriever.retrieve(client, query, candidates, (config or Config()).chars_per_token)
    size = profiles.POOL[profile]
    chars = max(PASSAGE_CHARS, 4 * (config or Config()).max_tokens) if profile == profiles.NARRATIVE else PASSAGE_CHARS
    ranked = _rank(client, query, candidates[:size], profiles.SCOPES[profile], chars, profile == profiles.PROCEDURAL)

    if ranked is None:
        return None
    if not ranked['kept']:
        return {'units': [], 'trace': {'profile': profile, 'scores': ranked['scores']}}

    if profile == profiles.NARRATIVE:
        gathered = _narrative(ranked)
    elif profile == profiles.PROCEDURAL:
        gathered = _procedural(client, query, ranked, chars)
    else:
        gathered = _sections(ranked)

    several = ranked['scope'] == 'several places' and profile in (profiles.NORMATIVE, profiles.GUIDANCE)
    if several and _complete(client, query, gathered) is False:
        gathered = [*gathered, *_more(client, query, candidates[size : size * 2], chars)]

    gathered = _expand(client, query, _unique(gathered))
    if profile == profiles.MARKUP:
        gathered = _references(client, query, gathered)

    return {
        'units': _in_document_order(gathered),
        'trace': {'profile': profile, 'scope': ranked['scope'], 'scores': ranked['scores']},
    }


def _reference(client: Jev, query: str, candidates: list[Candidate]) -> dict[str, Any] | None:
    """
    Reference lookups. Entries the question names join the pool with a second question each:
    asked only whether it answers, the entry a comparison names scores low ("does Path.walk
    answer?" 0.08), while "is it one the question names?" finds it. A question about every entry
    with a property is answered by a filter over the entries' fields.
    """
    pool = candidates[: profiles.POOL[profiles.REFERENCE]]
    named = _named_entries(query, candidates)
    named_keys = {_key(candidate) for candidate in named}
    pool_keys = {_key(candidate) for candidate in pool}
    labels: dict[str, Candidate] = {}
    is_named: dict[str, bool] = {}
    for i, candidate in enumerate([*pool, *(found for found in named if _key(found) not in pool_keys)]):
        label = _entry_label(candidate, i)
        labels[label] = candidate
        is_named[label] = _key(candidate) in named_keys

    questions: dict[str, Noul | Choice] = {}
    for label in labels:
        questions[f'c_{label}'] = _answers(label)
        if is_named[label]:
            questions[f'n_{label}'] = _noul(
                f'Is `{label}` one of the entries the question names or asks about?',
                f'the question refers to the entry `{label}` describes, by its name or unmistakably',
                'the question is about a different entry that merely has a similar name',
            )
    questions['best'] = Choice(
        instructions='Which single one answers the question most directly?', criteria=dict.fromkeys(labels)
    )
    shapes = profiles.SCOPES[profiles.REFERENCE]
    questions['scope'] = Choice(instructions='What does a full answer to the question need?', criteria=dict(shapes))
    state = {
        QUESTION: query,
        'the entries the search found': {
            label: text.head(unit.text, PASSAGE_CHARS) for label, (_, unit) in labels.items()
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
    shape = chosen if chosen is not None and chosen in shapes else 'one entry'
    kept_units = [labels[label] for label in kept]
    trace: dict[str, Any] = {'profile': profiles.REFERENCE, 'scope': shape, 'scores': scores}

    if shape == 'named entries':
        confirmed = [
            labels[label] for label in labels if is_named[label] and _probability(response, f'n_{label}') >= 0.5
        ]
        gathered = [*confirmed, *kept_units[:3]]
    elif shape == 'all entries with a property':
        gathered = _entries_with_property(client, query, candidates, trace) or kept_units[:3]
    else:
        gathered = kept_units[:3]

    return {'units': _in_document_order(_unique(gathered)), 'trace': trace}


def _named_entries(query: str, candidates: list[Candidate]) -> list[Candidate]:
    """
    Entries of the candidates' documents whose name the question contains as a whole word,
    longest names first, at most 6.
    """
    found: dict[str, tuple[int, Candidate]] = {}
    for document in _documents(candidates):
        for unit in document.units:
            if unit.kind != 'entry':
                continue
            for alias in _aliases(str(unit.meta.get('name') or '')):
                pattern = r'(?<![\w.])' + re.escape(alias) + r'(?![\w(])'
                if re.search(pattern, query, re.IGNORECASE):
                    found[_key((document, unit))] = (len(alias), (document, unit))
                    break

    ranked = sorted(found.values(), key=lambda item: -item[0])

    return [candidate for _, candidate in ranked][:6]


def _aliases(name: str) -> list[str]:
    """An entry's name and, for a call signature, the name before the bracket; three characters or more."""
    aliases = [text.trim(name)]
    match = re.match(r'^([\w.]+)\(', name)
    if match:
        aliases.append(match.group(1))

    return [alias for alias in dict.fromkeys(aliases) if len(alias) >= 3]


def _entries_with_property(
    client: Jev, query: str, candidates: list[Candidate], trace: dict[str, Any]
) -> list[Candidate]:
    """
    Every entry with a field value the question contains, offered as filters with their counts;
    Jev picks the one that selects what the question asks for. Without a fitting filter, the
    candidates are asked one by one whether they are among the entries meant.
    """
    question = query.replace('\u2236', ':').lower()
    options: dict[tuple[str, str, str], dict[str, Any]] = {}
    for document in _documents(candidates):
        for unit in document.units:
            pairs = unit.meta.get('fields')
            for pair in pairs if isinstance(pairs, list) else []:
                field = compat.to_str(pair.get('field')) if isinstance(pair, dict) else ''
                values = pair.get('values') if isinstance(pair, dict) else None
                for value in values if isinstance(values, list) else [values]:
                    written = text.trim(compat.to_str(value))
                    needle = written.replace('\u2236', ':').lower()
                    if len(needle) < 2 or not _contains_token(question, needle):
                        continue
                    group = compat.to_str(unit.meta.get('group')) if unit.meta.get('row') else ''
                    empty = {'field': field, 'group': group, 'value': written, 'entries': []}
                    option = options.setdefault((field, group, written), empty)
                    option['entries'].append((document, unit))

    if options:
        picked = _pick_filter(client, query, list(options.values()))
        if picked is not None:
            trace['filter'] = {'field': picked['field'], 'group': picked['group'], 'value': picked['value']}
            return _unique(picked['entries'])[:30]

    return _entries_meant(client, query, candidates[:24])


def _contains_token(question: str, needle: str) -> bool:
    """Whether `needle` occurs in `question` without a letter, digit or `_` touching either side."""
    at = question.find(needle)
    while at != -1:
        before = question[at - 1] if at > 0 else ''
        after = question[at + len(needle)] if at + len(needle) < len(question) else ''
        if not _word_char(before) and not _word_char(after):
            return True
        at = question.find(needle, at + 1)

    return False


def _word_char(char: str) -> bool:
    return char != '' and (char == '_' or unicodedata.category(char)[0] in 'LN')


def _pick_filter(client: Jev, query: str, options: list[dict[str, Any]]) -> dict[str, Any] | None:
    labels = {}
    for i, option in enumerate(options):
        count = len(_unique(option['entries']))
        where = f'whose `{option["field"]}` is {option["value"]} ({count} entries)'
        labels[f'f{i}'] = f'entries {where}' if option['group'] == '' else f'rows of `{option["group"]}` {where}'

    response = jev.send(
        client,
        {
            'state': {QUESTION: query},
            'questions': {
                'filter': Choice(
                    instructions=(
                        'Which filter selects exactly the entries the question asks for? Pick `none` if none of them '
                        'does.'
                    ),
                    criteria={**labels, 'none': 'none of these selects the entries the question asks for'},
                )
            },
        },
    )
    pick = jev.choice(response, 'filter') if response is not None else None

    return options[int(pick[1:])] if pick is not None and pick in labels else None


def _entries_meant(client: Jev, query: str, candidates: list[Candidate]) -> list[Candidate]:
    found = []
    for start in range(0, len(candidates), 12):
        labels = {_entry_label(candidate, i): candidate for i, candidate in enumerate(candidates[start : start + 12])}
        questions: dict[str, Noul | Choice] = {
            f'c_{label}': _noul(
                f'Is `{label}` one of the entries the question asks for?',
                f'`{label}` has what the question asks about',
                f'`{label}` lacks it, or is not the kind of entry the question asks about',
            )
            for label in labels
        }
        state = {
            QUESTION: query,
            'the entries': {label: text.head(unit.text, PASSAGE_CHARS) for label, (_, unit) in labels.items()},
        }
        response = jev.send(client, {'state': state, 'questions': questions})
        if response is None:
            continue
        found.extend(candidate for label, candidate in labels.items() if _probability(response, f'c_{label}') >= 0.5)

    return found


def _entry_label(candidate: Candidate, i: int) -> str:
    """The entry's name, or the passage's heading path, as a slug with its position on the end."""
    unit = candidate[1]
    name = unit.meta.get('name')
    found = text.slug(compat.to_str(name) if name is not None else ' '.join(unit.path), 40)

    return f'{found or "passage"}-{i}'


def _documents(candidates: list[Candidate]) -> list[Document]:
    documents: dict[str, Document] = {}
    for document, _ in candidates:
        documents.setdefault(document.id, document)

    return list(documents.values())


def _references(client: Jev, query: str, gathered: list[Candidate]) -> list[Candidate]:
    """
    Follows `ref` links from the gathered units, both ways, for two hops: each hop asks about up
    to 25 units linked to the previous hop's and gathers those scoring 0.4 or more.
    """
    frontier = gathered
    for _ in range(2):
        if not frontier:
            break
        have = {_key(candidate) for candidate in gathered}
        targets: dict[str, dict[str, Any]] = {}
        for document, unit in frontier:
            for link in document.links:
                if link.type != 'ref':
                    continue
                other = link.to if link.from_ == unit.id else link.from_ if link.to == unit.id else None
                found = document.unit(other) if other is not None else None
                if found is None or _key((document, found)) in have:
                    continue
                targets.setdefault(
                    _key((document, found)), {'target': (document, found), 'from': unit, 'uses': other == link.from_}
                )
        frontier = _referenced(client, query, gathered, list(targets.values())[:25])
        gathered = [*gathered, *frontier]

    return gathered


def _referenced(client: Jev, query: str, gathered: list[Candidate], targets: list[dict[str, Any]]) -> list[Candidate]:
    if not targets:
        return []

    labels = {f'linked-{i}': target for i, target in enumerate(targets)}
    questions: dict[str, Noul | Choice] = {}
    for label, target in labels.items():
        source = ' > '.join(target['from'].path) if target['from'].path else '(top level)'
        if target['uses']:
            questions[f'c_{label}'] = _noul(
                f'`{label}` points to `{source}` (it uses it by reference). Is `{label}` the place the question asks '
                'about, such as the endpoint or service that uses that definition?',
                f'the answer names or needs `{label}`, the user of the definition',
                f'`{label}` is another user of the definition that the question is not about',
            )
        else:
            questions[f'c_{label}'] = _noul(
                f'`{source}` takes part of its content from `{label}` by reference. Does the answer turn on what '
                f'`{label}` defines, such as which fields are required, their types, default values or settings?',
                f'a required field, type, default or setting the answer needs is defined in `{label}`',
                f'the answer does not need anything defined in `{label}`',
            )
    state = {
        QUESTION: query,
        'the parts found so far': [text.head(unit.text, 1600) for _, unit in gathered],
        'linked parts': {label: text.head(target['target'][1].text, 1200) for label, target in labels.items()},
    }
    response = jev.send(client, {'state': state, 'questions': questions})
    if response is None:
        return []

    return [target['target'] for label, target in labels.items() if _probability(response, f'c_{label}') >= KEEP]


def _sections(ranked: dict[str, Any]) -> list[Candidate]:
    kept = ranked['kept']
    if ranked['scope'] == 'a whole section':
        return _section(kept[0])

    return kept[:6] if ranked['scope'] == 'several places' else kept[:3]


def _narrative(ranked: dict[str, Any]) -> list[Candidate]:
    """For several places, every kept passage scoring 0.5 or more, up to 8, or the best 3 when fewer than 2 do."""
    if ranked['scope'] != 'several places':
        return ranked['kept'][:3]

    strong = [ranked['labels'][label] for label in ranked['kept_labels'] if ranked['scores'][label] >= KEEP_SEVERAL]

    return strong[:8] if len(strong) >= 2 else ranked['kept'][:3]


def _procedural(client: Jev, query: str, ranked: dict[str, Any], chars: int) -> list[Candidate]:
    """
    The whole section of the best passage for all the steps; for the step after or before a
    named one, the step next to the passage Jev names as that step, plus the neighbours of the
    best passages that Jev confirms.
    """
    kept = ranked['kept']
    gathered = kept[: 6 if ranked['scope'] == 'several places' else 3]

    if ranked['scope'] == 'all the steps':
        gathered = [*gathered, *_section(kept[0])]

    if ranked['scope'] == 'the next or previous step':
        step = -1 if ranked['direction'] == 'before' else 1
        following = _neighbour(ranked['anchor'], step) if ranked['anchor'] is not None else None
        if following is not None:
            gathered = [*gathered, following]
        around = [
            found
            for candidate in kept[:2]
            for offset in (-2, -1, 1, 2)
            if (found := _neighbour(candidate, offset)) is not None
        ]
        gathered = [*gathered, *_confirm_steps(client, query, _unique(around), chars)]

    return gathered


def _neighbour(candidate: Candidate, offset: int) -> Candidate | None:
    """The content passage `offset` steps away in its document, skipping passages of fewer than 25 tokens."""
    document, chosen = candidate
    units = [
        unit
        for unit in document.units
        if unit.kind == 'passage' and (unit.id == chosen.id or text.tokens(body(unit.text), 4) >= MIN_STEP_TOKENS)
    ]
    for i, unit in enumerate(units):
        if unit.id == chosen.id:
            return (document, units[i + offset]) if 0 <= i + offset < len(units) else None

    return None


def _confirm_steps(client: Jev, query: str, candidates: list[Candidate], chars: int) -> list[Candidate]:
    if not candidates:
        return []

    labels = {f'step-{i}': candidate for i, candidate in enumerate(candidates)}
    questions: dict[str, Noul | Choice] = {
        f'c_{label}': _noul(
            f'Is `{label}` the step the question asks for, the one done right after or right before the step it names?',
            f'`{label}` describes the step the question asks for',
            f'`{label}` describes a different step, or no step',
        )
        for label in labels
    }
    state = {
        QUESTION: query,
        'the passages around the steps found': {
            label: text.head(unit.text, chars) for label, (_, unit) in labels.items()
        },
    }
    response = jev.send(client, {'state': state, 'questions': questions})
    if response is None:
        return []

    return [candidate for label, candidate in labels.items() if _probability(response, f'c_{label}') >= 0.5]


def _profiles(candidates: list[Candidate]) -> list[str]:
    """The profiles of the first five candidates, most common first, ties to the first seen."""
    votes: dict[str, int] = {}
    for candidate in candidates[:5]:
        found = _profile_of(candidate)
        votes[found] = votes.get(found, 0) + 1

    return sorted(votes, key=lambda name: -votes[name]) if votes else [profiles.GUIDANCE]


def _profile_of(candidate: Candidate) -> str:
    found = str(candidate[1].meta.get('profile') or profiles.GUIDANCE)

    return found if profiles.exists(found) else profiles.GUIDANCE


def _rank(
    client: Jev, query: str, pool: list[Candidate], scopes: dict[str, str], chars: int, steps: bool = False
) -> dict[str, Any] | None:
    labels = {f'p{i}': candidate for i, candidate in enumerate(pool)}
    questions: dict[str, Noul | Choice] = {f'c_{label}': _answers(label) for label in labels}
    questions['best'] = Choice(
        instructions='Which single one answers the question most directly?', criteria=dict.fromkeys(labels)
    )
    if scopes:
        questions['scope'] = Choice(instructions='What does a full answer to the question need?', criteria=dict(scopes))
    if steps:
        questions['anchor'] = Choice(
            instructions=(
                'If the question asks what to do after or before a step it names, which passage describes that named '
                'step? Pick `none` if the question does not name a step.'
            ),
            criteria=dict.fromkeys([*labels, 'none']),
        )
        questions['direction'] = Choice(
            instructions='Does the question ask for the step after or the step before the one it names?',
            criteria={
                'after': 'the question asks for the step done right after the one it names',
                'before': 'the question asks for the step done right before the one it names',
            },
        )
    state = {
        QUESTION: query,
        'the passages the search found': {label: text.head(unit.text, chars) for label, (_, unit) in labels.items()},
    }

    response = jev.send(client, {'state': state, 'questions': questions})
    if response is None:
        return None

    scores = {label: _probability(response, f'c_{label}') for label in labels}
    kept = sorted((label for label, score in scores.items() if score >= KEEP), key=lambda label: -scores[label])
    best = jev.choice(response, 'best')
    if best is not None and best in kept:
        kept = [best, *(label for label in kept if label != best)]
    scope = jev.choice(response, 'scope')
    anchor = jev.choice(response, 'anchor')

    return {
        'kept': [labels[label] for label in kept],
        'kept_labels': kept,
        'scope': scope if scope is not None and scope in scopes else 'one passage',
        'scores': scores,
        'labels': labels,
        'anchor': labels.get(anchor) if anchor is not None else None,
        'direction': 'before' if jev.choice(response, 'direction') == 'before' else 'after',
    }


def _section(candidate: Candidate) -> list[Candidate]:
    """Every passage of the document under the same headings as this one."""
    document, chosen = candidate

    return [(document, unit) for unit in document.units if unit.kind == 'passage' and unit.path == chosen.path]


def _complete(client: Jev, query: str, gathered: list[Candidate]) -> bool | None:
    response = jev.send(
        client,
        {
            'state': {QUESTION: query, 'the passages gathered': [text.head(unit.text, 1200) for _, unit in gathered]},
            'questions': {
                'complete': _noul(
                    'Do the passages gathered contain everything needed to answer the question in full?',
                    'every part of the question is answered by something in the passages',
                    'some part of the question is not covered by any of the passages',
                )
            },
        },
    )
    score = jev.noul(response, 'complete') if response is not None else None

    return None if score is None else score >= 0.5


def _more(client: Jev, query: str, candidates: list[Candidate], chars: int) -> list[Candidate]:
    """Further candidates that answer part of the question."""
    if not candidates:
        return []

    ranked = _rank(client, query, candidates, {}, chars)

    return ranked['kept'] if ranked is not None else []


def _expand(client: Jev, query: str, gathered: list[Candidate]) -> list[Candidate]:
    have = {_key(candidate) for candidate in gathered}
    needed: dict[str, Candidate] = {}
    referenced: dict[str, Candidate] = {}
    defining: dict[str, Candidate] = {}

    for document, unit in gathered:
        for link in document.links:
            if link.from_ != unit.id:
                continue
            target = document.unit(link.to)
            if target is None or f'{document.id}#{target.id}' in have:
                continue
            entry = (document, target)
            bucket = {'needs': needed, 'refers_to': referenced, 'defined_by': defining}.get(link.type)
            if bucket is not None:
                bucket[_key(entry)] = entry

    return _unique([
        *gathered,
        *needed.values(),
        *_keep(client, query, gathered, list(referenced.values()), 'reference'),
        *_keep(client, query, gathered, list(defining.values()), 'definition'),
    ])


def _keep(
    client: Jev, query: str, gathered: list[Candidate], candidates: list[Candidate], kind: str
) -> list[Candidate]:
    if not candidates:
        return []

    labels = {f'{kind}-{i}': candidate for i, candidate in enumerate(candidates)}
    questions: dict[str, Noul | Choice] = {}
    for label in labels:
        if kind == 'definition':
            questions[f'c_{label}'] = _noul(
                f'Does `{label}` give the meaning of a term that the answer to the question turns on?',
                f'whether the answer applies depends on what a term defined in `{label}` means',
                f'`{label}` defines terms the answer does not depend on',
            )
        else:
            questions[f'c_{label}'] = _noul(
                f'The gathered passages send the reader to `{label}`. Does the answer to the question need what '
                f'`{label}` says?',
                f'`{label}` states a rule, condition or detail the answer depends on',
                f'the answer is complete without what `{label}` says',
            )
    if kind == 'definition':
        questions['definition'] = Choice(
            instructions=(
                'Which single definition does the answer depend on most? Pick `none` if the answer depends on '
                'none of them.'
            ),
            criteria=dict.fromkeys([*labels, 'none']),
        )
    state = {
        QUESTION: query,
        'the passages gathered': [text.head(unit.text, 800) for _, unit in gathered],
        'definitions' if kind == 'definition' else 'referenced passages': {
            label: text.head(unit.text, 800) for label, (_, unit) in labels.items()
        },
    }

    response = jev.send(client, {'state': state, 'questions': questions})
    if response is None:
        return []

    pick = jev.choice(response, 'definition') if kind == 'definition' else None

    return [
        candidate
        for label, candidate in labels.items()
        if _probability(response, f'c_{label}') >= 0.5 or pick == label
    ]


def _answers(label: str) -> Noul:
    return _noul(
        f'Does `{label}` answer the question?',
        f'`{label}` states the answer, or a part of it.',
        f'`{label}` is on the same subject but does not answer the question, or only asserts that it does.',
    )


def _key(candidate: Candidate) -> str:
    return f'{candidate[0].id}#{candidate[1].id}'


def _unique(candidates: list[Candidate]) -> list[Candidate]:
    found: dict[str, Candidate] = {}
    for candidate in candidates:
        found.setdefault(_key(candidate), candidate)

    return list(found.values())


def _in_document_order(candidates: list[Candidate]) -> list[Candidate]:
    """By document id compared as byte strings, then position in the document."""
    position = {}
    for candidate in candidates:
        for i, unit in enumerate(candidate[0].units):
            if unit.id == candidate[1].id:
                position[_key(candidate)] = i

    return sorted(candidates, key=lambda candidate: (candidate[0].id.encode(), position.get(_key(candidate), 0)))


def _noul(instructions: str, yes: str, no: str) -> Noul:
    return Noul(instructions=instructions, criteria={'true': yes, 'false': no})


def _probability(response: Any, name: str) -> float:
    found = jev.noul(response, name)

    return 0.0 if found is None else found
