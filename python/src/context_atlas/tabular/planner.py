"""
Turns a question into a typed query plan over tables, in up to three Jev requests:

1. route: which table, whether the question asks what the data means, and whether the tables
   hold what it needs at all;
2. columns: the measure, the operation, the grouping, which columns the question filters on, and
   which blank-category rows (aggregates such as "World") to leave out;
3. values: the filter values, chosen from the column's real values.

Every option is built from the schema, so Jev picks and never writes; the executor computes.
The strings sent to Jev are the PHP package's, word for word.
"""
from __future__ import annotations

import re
import unicodedata
from typing import Any

from typesafe_sdk import Choice, Noul, SystemOneResponse

from .. import compat, jev, text
from ..jev import Jev, JevRequest
from ..text import WHITESPACE
from . import executor
from .card import card
from .number import format_number
from .number import key as number_key

QUESTION = 'the question being asked'

OPERATIONS = {
    'list': 'show the matching records themselves (one value, a few records, or the records that meet a condition)',
    'sum': 'add the values up into a total',
    'average': 'the average (mean) of the values',
    'count': 'count how many records there are',
    'max': 'the single highest value, and the record it belongs to',
    'min': 'the single lowest value, and the record it belongs to',
}

CHANGES = {
    'change': (
        'how much the value rose or fell from the start to the end of a span of periods (the difference), or which '
        'rose or fell the most'
    ),
    'percent': (
        'by what percentage the value rose or fell from the start to the end of a span of periods, or which rose or '
        'fell the most in percentage terms'
    ),
}

NUMBER_WORDS = {
    'a': 1, 'one': 1, 'two': 2, 'three': 3, 'four': 4, 'five': 5, 'six': 6, 'seven': 7, 'eight': 8, 'nine': 9,
    'ten': 10, 'twelve': 12, 'twenty': 20, 'fifty': 50, 'hundred': 100,
}

SHORTLIST = 12

STOP_WORDS = frozenset({
    'the', 'and', 'for', 'what', 'was', 'were', 'how', 'much', 'many', 'did', 'does', 'which', 'with', 'from',
    'that', 'this', 'each', 'all', 'any', 'are', 'who', 'its', 'their', 'than', 'more', 'less', 'over', 'under',
    'between', 'total', 'show', 'every', 'list', 'compare',
})

_NUMBER = re.compile(
    rf'(?:[£$€][{WHITESPACE}]?)?(\d{{1,3}}(?:,\d{{3}})+(?:\.\d+)?|\d+(?:\.\d+)?)'
    rf'[{WHITESPACE}]*(million|billion|thousand|bn|m|k)?\b',
    re.IGNORECASE,
)
_ZERO = re.compile(r'\bzero\b', re.IGNORECASE | re.ASCII)
_NUMBER_WORDS = re.compile(
    rf'\b({"|".join(NUMBER_WORDS)})[{WHITESPACE}]+(hundred|thousand|million|billion)\b', re.IGNORECASE
)
_SCALES = {'million': 1e6, 'm': 1e6, 'billion': 1e9, 'bn': 1e9, 'thousand': 1e3, 'k': 1e3, 'hundred': 1e2}

Table = dict[str, Any]


def plan(client: Jev, question: str, tables: list[Table]) -> dict[str, Any] | None:
    """
    `{table, meaning, plan, trace}`: the chosen table or None, whether the question asks what the
    data means, the plan (None when there is no table or it is a meaning question), and the
    trace. None when Jev did not answer.
    """
    route = _route(client, question, tables)
    if route is None:
        return None
    if route['table'] is None or route['meaning']:
        return {**route, 'plan': None}

    columns = _columns(client, route['table'], question)
    if columns is None:
        return None

    values = _values(client, route['table'], question, columns['filter_columns'])
    if values is None:
        return None

    filters, unmatched = values
    chosen: dict[str, Any] = {
        'measure': columns['measure'],
        'operation': columns['operation'],
        'group': columns['group'],
        'filters': filters,
        'drop_blank': columns['drop_blank'],
        'unmatched': unmatched,
        'relaxed': None,
    }
    chosen['relaxed'] = executor.relax(route['table'], chosen, _named(question, filters))
    trace = {**route['trace'], 'filters': filters, **{key: value for key, value in chosen.items() if key != 'filters'}}

    return {'table': route['table'], 'meaning': False, 'plan': chosen, 'trace': trace}


def numbers(question: str) -> list[tuple[float, str]]:
    """
    Numbers written in the question, with million, billion, thousand, bn, m and k expanded,
    "zero", and number words followed by a scale ("one billion").
    """
    found = [(0.0, 'zero')] if _ZERO.search(question) else []

    for match in _NUMBER.finditer(question):
        scale = _SCALES.get((match.group(2) or '').lower(), 1.0)
        found.append((compat.to_float(match.group(1).replace(',', '')) * scale, text.trim(match.group(0))))
    for match in _NUMBER_WORDS.finditer(question):
        found.append((NUMBER_WORDS[match.group(1).lower()] * _SCALES[match.group(2).lower()], match.group(0)))

    return found


def _route(client: Jev, question: str, tables: list[Table]) -> dict[str, Any] | None:
    labels = {f't{i + 1}': table for i, table in enumerate(tables)}
    options = {label: f'{table["title"]} (sheet {table["sheet"]})' for label, table in labels.items()}
    request: JevRequest = {
        'state': {
            QUESTION: question,
            'the tables in the workbook': {
                label: _with_hints(card(table, 6), table, question) for label, table in labels.items()
            },
        },
        'questions': {
            'table': Choice(
                instructions='Which table holds the data needed to answer the question?',
                criteria={**options, 'none': 'none of the tables holds the data the question needs'},
            ),
            'meaning': _noul(
                'Is the question about what the data means or covers (what a column, label or term means, what is '
                'recorded, the unit, the period covered), rather than asking for values from it?',
                'the question asks what something means, what is recorded, the unit or the coverage',
                'the question asks for one or more values, records, totals, counts or comparisons',
            ),
            'answerable': _noul(
                'Do the tables hold the data needed to answer the question?',
                'the measure, and the things, places and periods the question names, are all within what the '
                'tables record',
                'the question needs a measure, period, place or detail that none of the tables records',
            ),
        },
    }
    response = jev.send(client, request)
    if response is None:
        return None

    pick = _or(jev.choice(response, 'table'), 'none')
    answerable = _probability(response, 'answerable')
    meaning = _probability(response, 'meaning')
    table = labels.get(pick) if pick != 'none' and answerable >= 0.5 else None
    trace = {'table': table['id'] if table is not None else None, 'answerable': answerable, 'meaning': meaning}

    return {'table': table, 'meaning': table is not None and meaning >= 0.5, 'trace': trace}


def _columns(client: Jev, table: Table, question: str) -> dict[str, Any] | None:
    columns = list(table['columns'].values())
    measures = _labelled([c for c in columns if c['role'] == 'measure'], 'm')
    groups = _labelled([c for c in columns if c['role'] in ('category', 'period', 'label')], 'g')
    filterable = [c for c in columns if c['role'] not in ('constant', 'text', 'alias')]
    blanks = [c for c in columns if c['role'] == 'category' and c['blank'] > 0]
    periods = any(c['role'] == 'period' for c in columns)

    questions: dict[str, Noul | Choice] = {}
    if measures:
        named = {
            label: c['name'] + (f' ({c["unit"]})' if c['unit'] is not None else '') for label, c in measures.items()
        }
        questions['measure'] = Choice(
            instructions='Which number column does the answer come from?',
            criteria={**named, 'none': 'no number column is needed: the question counts or lists records'},
        )
    questions['operation'] = Choice(
        instructions='What does the answer need done with the matching records?',
        criteria={**OPERATIONS, **(CHANGES if measures and periods else {})},
    )
    questions['group'] = Choice(
        instructions=(
            'Does the question want a separate figure for each value of a column (for example each organisation, '
            'each region or each year)? Pick that column, or none.'
        ),
        criteria={
            'none': 'one answer overall, or the records themselves',
            **{label: f'a separate figure for each {c["name"]}' for label, c in groups.items()},
        },
    )
    if groups:
        questions['rank'] = Choice(
            instructions=(
                'Does the question ask which value of a column comes first or last (has the most or fewest records, '
                'the highest or lowest total, or rose or fell the most)? Pick that column, or none.'
            ),
            criteria={
                'none': 'no: it does not ask which one comes first or last',
                **{label: f'which {c["name"]} comes first or last' for label, c in groups.items()},
            },
        )
    for i, column in enumerate(filterable):
        name = column['name']
        what = 'a threshold on' if column['role'] == 'measure' else 'particular values or a range of'
        questions[f'f{i}'] = _noul(
            f'Does the question limit which records count by `{name}`?',
            f'the question names {what} `{name}` (directly or in other words)',
            f'the question does not restrict `{name}`',
        )
    for i, column in enumerate(blanks):
        questions[f'b{i}'] = _noul(
            f'Should the records with no `{column["name"]}` be left out? They are: '
            + '; '.join(_blank_examples(table, column['name'])),
            'the question is about a kind of thing those records are not (for example individual countries, not '
            'groups of countries)',
            'those records are among what the question asks about, or it makes no difference',
        )

    response = jev.send(client, {'state': {QUESTION: question, 'the table': card(table, 8)}, 'questions': questions})
    if response is None:
        return None

    measure = measures.get(_or(jev.choice(response, 'measure'), ''))
    operation = _or(jev.choice(response, 'operation'), 'list')
    picked = jev.choice(response, 'group')
    if _or(picked, 'none') == 'none' and _ranks(operation, measure):
        picked = jev.choice(response, 'rank')
    group = groups.get(_or(picked, ''))

    return {
        'measure': measure['name'] if measure is not None else None,
        'operation': operation,
        'group': group['name'] if group is not None else None,
        'filter_columns': [c['name'] for i, c in enumerate(filterable) if _probability(response, f'f{i}') >= 0.5],
        'drop_blank': [c['name'] for i, c in enumerate(blanks) if _probability(response, f'b{i}') >= 0.5],
    }


def _values(
    client: Jev, table: Table, question: str, names: list[str]
) -> tuple[dict[str, list[Any]], list[str]] | None:
    """The filters, and the value columns where none of the offered values was chosen."""
    asks = {}
    for name in names:
        ask = _ask(table['columns'][name], question)
        if ask is not None:
            asks[name] = ask

    if not asks:
        return {}, []

    questions: dict[str, Noul | Choice] = {}
    for c, (name, ask) in enumerate(asks.items()):
        questions.update(_ask_values(f'c{c}', name, ask))

    state = {
        QUESTION: question,
        'the table': {'title': table['title'], 'columns': [str(c['name']) for c in table['columns'].values()]},
        'the values to choose from': {name: ask['options'] or [] for name, ask in asks.items()},
    }
    response = jev.send(client, {'state': state, 'questions': questions})
    if response is None:
        return None

    filters = {}
    unmatched = []
    for c, (name, ask) in enumerate(asks.items()):
        found = _filter(response, f'c{c}', ask)
        if found is not None:
            filters[name] = found
        elif ask['kind'] == 'values':
            unmatched.append(name)

    return filters, unmatched


def _ask(column: dict[str, Any], question: str) -> dict[str, Any] | None:
    if column['role'] == 'period':
        return {'kind': 'range', 'options': [str(value) for value in column.get('values') or []]}

    if column['role'] == 'measure':
        found = numbers(question)
        if not found:
            return None
        options = {f'n{i}': f'{written} (= {format_number(value)})' for i, (value, written) in enumerate(found)}
        return {'kind': 'threshold', 'options': options, 'numbers': found}

    values = column['values'] if column['role'] == 'category' else _shortlist(question, column.get('values') or [])

    return {'kind': 'values', 'options': {f'v{i}': str(value) for i, value in enumerate(values)}}


def _ask_values(key: str, name: str, ask: dict[str, Any]) -> dict[str, Noul | Choice]:
    if ask['kind'] == 'range':
        values = dict.fromkeys(ask['options'])
        return {
            f'{key}_kind': Choice(
                instructions=f'How much of `{name}` does the question cover?',
                criteria={
                    'one': f'a single `{name}`',
                    'span': f'a span of `{name}` values, from one to another',
                    'all': f'every `{name}`, no limit',
                },
            ),
            f'{key}_one': Choice(instructions=f'If the question covers a single `{name}`, which one?', criteria=values),
            f'{key}_from': Choice(
                instructions=f'If the question covers a span of `{name}`, where does the span start?', criteria=values
            ),
            f'{key}_to': Choice(
                instructions=f'If the question covers a span of `{name}`, where does the span end?', criteria=values
            ),
        }

    if ask['kind'] == 'threshold':
        return {
            f'{key}_cmp': Choice(
                instructions=f'What kind of limit does the question put on `{name}`?',
                criteria={
                    'above': 'more than',
                    'atleast': 'at least',
                    'below': 'less than',
                    'atmost': 'at most',
                    'none': 'no threshold',
                },
            ),
            f'{key}_num': Choice(
                instructions=f'Which number in the question is the limit on `{name}`?', criteria=dict(ask['options'])
            ),
        }

    return {
        f'{key}_{label}': _noul(
            f'Is `{value}` one of the `{name}` values the question is about?',
            f'the question names `{value}`, or the same thing in other words or as an abbreviation',
            f'the question is about some other `{name}`, or does not name one',
        )
        for label, value in ask['options'].items()
    }


def _filter(response: SystemOneResponse, key: str, ask: dict[str, Any]) -> list[Any] | None:
    if ask['kind'] == 'range':
        kind = _or(jev.choice(response, f'{key}_kind'), 'all')
        one = jev.choice(response, f'{key}_one')
        start = jev.choice(response, f'{key}_from')
        end = jev.choice(response, f'{key}_to')
        if kind == 'one' and one is not None:
            return ['range', one, one]
        if kind == 'span' and (start is not None or end is not None):
            return ['range', start, end]
        return None

    if ask['kind'] == 'threshold':
        comparison = _or(jev.choice(response, f'{key}_cmp'), 'none')
        picked = jev.choice(response, f'{key}_num')
        index = int(compat.to_float((picked or '')[1:]))
        found = ask['numbers'][index] if 0 <= index < len(ask['numbers']) else None
        if comparison != 'none' and found is not None and picked is not None:
            return ['threshold', comparison, found[0]]
        return None

    chosen = [value for label, value in ask['options'].items() if _probability(response, f'{key}_{label}') >= 0.5]

    return ['in', chosen] if chosen else None


def _with_hints(described: dict[str, Any], table: Table, question: str) -> dict[str, Any]:
    """
    Adds to each label and id column the values sharing a word with the question, so Jev sees a
    named supplier or country is in the table though the card shows few examples.
    """
    wanted = _words(question)

    for column in table['columns'].values():
        if column['role'] not in ('label', 'id'):
            continue
        hits = {}
        for value in column.get('values') or []:
            shared = _overlap(wanted, _words(value))
            if shared > 0:
                hits[value] = shared
        if hits:
            ranked = sorted(hits, key=lambda value: -hits[value])[:5]
            described['columns'][column['name']] += '; values sharing a word with the question: ' + '; '.join(
                value[:60] for value in ranked
            )

    return described


def _shortlist(question: str, values: list[Any]) -> list[str]:
    """
    The values of a large column most worth offering. Each question word a value shares scores
    1 / the number of the column's values sharing that word, so a rare name ("Blackpool")
    outranks a word half the column has ("council"); highest score first, column order among
    equals.
    """
    value_words = [_words(compat.to_str(value)) for value in values]
    hits = []
    for word in _words(question):
        matched = [v for v, candidate in enumerate(value_words) if _overlap([word], candidate) > 0]
        if matched:
            hits.append(matched)

    scores = [0.0] * len(values)
    for matched in hits:
        for v in matched:
            scores[v] += 1 / len(matched)
    ranked = sorted(range(len(values)), key=lambda v: -scores[v])

    return [compat.to_str(values[v]) for v in ranked[:SHORTLIST]]


def _overlap(words: list[str], candidate: list[str]) -> int:
    """Two words match when equal, or when both have four characters or more and one starts with the other."""
    hits = 0

    for word in words:
        for other in candidate:
            prefix = len(word) >= 4 and len(other) >= 4 and (other.startswith(word) or word.startswith(other))
            if word == other or prefix:
                hits += 1
                break

    return hits


def _words(source: str) -> list[str]:
    found: list[str] = []
    run: list[str] = []

    for char in source.lower() + ' ':
        if unicodedata.category(char)[0] in 'LN':
            run.append(char)
            continue
        if run:
            found.append(''.join(run))
        run = []

    return [word for word in found if len(word) >= 3 and word not in STOP_WORDS]


def _ranks(operation: str, measure: dict[str, Any] | None) -> bool:
    """
    Whether a ranked column becomes the grouping: for totals, averages, counts and changes, and
    for a highest or lowest with no measure (most records); a highest or lowest of a measure
    keeps its single record.
    """
    return operation in ('sum', 'average', 'count', 'change', 'percent') or (
        operation in ('max', 'min') and measure is None
    )


def _named(question: str, filters: dict[str, list[Any]]) -> dict[str, float]:
    """
    How fully the question names each value filter: of its chosen values, the highest share of a
    value's words that match a question word.
    """
    wanted = _words(question)
    scores: dict[str, float] = {}
    for column, rule in filters.items():
        if rule[0] != 'in':
            continue
        scores[str(column)] = 0.0
        for value in rule[1]:
            own = _words(compat.to_str(value))
            if own:
                share = sum(1 for word in own if _overlap([word], wanted) > 0) / len(own)
                scores[str(column)] = max(scores[str(column)], share)

    return scores


def _labelled(columns: list[dict[str, Any]], prefix: str) -> dict[str, dict[str, Any]]:
    return {f'{prefix}{i + 1}': column for i, column in enumerate(columns)}


def _blank_examples(table: Table, name: str) -> list[str]:
    label = next(
        (
            c['name']
            for c in table['columns'].values()
            if c['role'] == 'label' or (c['role'] == 'category' and c['blank'] == 0)
        ),
        None,
    )
    seen: dict[str, bool] = {}

    for row in table['rows']:
        if label is not None and row['values'].get(name) is None:
            seen[number_key(row['values'].get(label))] = True
        if len(seen) >= 12:
            break

    return list(seen)


def _noul(instructions: str, yes: str, no: str) -> Noul:
    return Noul(instructions=instructions, criteria={'true': yes, 'false': no})


def _probability(response: SystemOneResponse, name: str) -> float:
    found = jev.noul(response, name)

    return 0.0 if found is None else found


def _or(value: str | None, default: str) -> str:
    return default if value is None else value
