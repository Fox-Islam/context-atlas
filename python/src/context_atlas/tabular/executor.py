"""
Runs a plan over a table and writes the context an LLM reads: the source, the plan in words, the
computed result, the records behind it with their sheet and row, and the notes they cite. The
arithmetic happens here, never in a model.
"""
from __future__ import annotations

import math
from functools import cmp_to_key
from typing import Any

from .. import compat
from .card import card_text
from .number import format_number
from .number import key as number_key

MAX_RECORDS = 60

SAMPLE_RECORDS = 40

MAX_GROUPS = 60

CHANGES = ('change', 'percent')

Table = dict[str, Any]
Row = dict[str, Any]


def run(table: Table, plan: dict[str, Any]) -> dict[str, Any]:
    """`{text, sources}`: the context, and the `sheet!row` sources of the records it shows."""
    lines = [f'Source: {table["title"]} [{table["id"]}], {len(table["rows"])} records. Columns: {_column_list(table)}']
    rows = select(table, plan)
    relaxed = plan.get('relaxed')
    lowest = plan['operation'] == 'min'
    measure, operation = _effective(plan)

    if not rows and relaxed is not None:
        lines.append('Query: ' + _describe(_shaped(plan, operation), measure))
        lines.append('Matched 0 records.')
        dropped = _constraint(plan, relaxed['drop'], relaxed['column'])
        lines.append(f'No record matches every filter, so this one is left out: {dropped}.')
        plan = _without(plan, relaxed['drop'], relaxed['column'])
        rows = select(table, plan)

    span = _span(table, plan) if operation in CHANGES else None
    no_span = operation in CHANGES and span is None
    if no_span:
        operation = 'sum'
    plan = _shaped(plan, operation)
    unit = compat.to_str(table['columns'][measure].get('unit')) if measure is not None else ''
    of = f' of {measure}' if measure is not None else ''

    lines.append('Query: ' + _describe(plan, measure))
    if no_span:
        lines.append(
            'A change needs a span of a period column with a start and an end, and none was chosen, so this is the sum.'
        )
    lines.extend(
        f'No {column} value matched the question, so the records are not filtered by {column}.'
        for column in plan.get('unmatched') or []
    )
    lines.append(f'Matched {len(rows)} records.')
    if not rows and _constraints(plan):
        lines.append(_unmatched_filters(table, plan))
    if measure is not None and operation != 'count' and rows and not _has_number(rows, measure):
        lines.append(_no_data(table, plan, measure, len(rows)))
    shown = rows

    if span is not None and measure is not None:
        lines.extend(_changes(rows, plan['group'], operation, measure, unit, span))
        ends = (span[1], span[2])
        shown = [row for row in rows if number_key(row['values'].get(span[0])) in ends] if plan['group'] is None else []
    elif plan['group'] is not None and operation != 'list':
        lines.extend(_grouped(rows, plan['group'], operation, measure, unit, lowest))
        shown = []
    elif operation != 'list':
        result = _aggregate(operation, rows, measure)
        skipped = f', {result["blank"]} blank skipped' if result['blank'] > 0 else ''
        lines.append(
            f'Result: {operation}{of} = {_amount(result["value"], unit)} (over {result["records"]} records{skipped})'
        )
        if operation in ('max', 'min') and len(rows) <= MAX_RECORDS:
            shown = _sort_by(rows, measure)
        elif result['row'] is not None:
            shown = [result['row']]
        else:
            shown = _sort_by(rows, measure)[:5]
    elif measure is not None and len(rows) > MAX_RECORDS:
        shown = _sort_by(rows, measure)

    limit = len(shown) if len(shown) <= MAX_RECORDS else SAMPLE_RECORDS
    if shown:
        lines.append(_records(table, shown[:limit], len(shown)))
    if len(shown) > limit:
        lines.append(_rest(shown[limit:], measure, unit))
    lines.extend(_notes(table, shown, plan))

    return {'text': '\n'.join(lines), 'sources': list(dict.fromkeys(row['source'] for row in shown[:limit]))}


def select(table: Table, plan: dict[str, Any]) -> list[Row]:
    """The records a plan's filters and blank-row columns select."""
    return [row for row in table['rows'] if _matches(row['values'], plan)]


def relax(table: Table, plan: dict[str, Any], scores: dict[str, float] | None = None) -> dict[str, Any] | None:
    """
    When a plan with two or more constraints selects no record, the one constraint to leave out.
    Of those whose removal lets some record through: the one the question names least (lowest
    score; a constraint without a score scores 1), then the one letting the fewest records
    through, then the first in order. None when the plan selects records, or no single removal
    helps.
    """
    scores = scores or {}
    constraints = _constraints(plan)
    if len(constraints) < 2 or select(table, plan):
        return None

    best = None
    best_key: tuple[float, int] | None = None
    for drop, column in constraints:
        matched = len(select(table, _without(plan, drop, column)))
        key = (scores.get(column, 1.0) if drop == 'filter' else 1.0, matched)
        if matched > 0 and (best_key is None or key < best_key):
            best = {'drop': drop, 'column': column, 'matched': matched}
            best_key = key

    return best


def meaning(table: Table) -> dict[str, Any]:
    """For a question about what the data means: the card, every record when there are 60 or fewer, and the notes."""
    lines = [f'Table: {table["title"]} [{table["id"]}]', card_text(table, 8)]
    rows = table['rows'] if len(table['rows']) <= 60 else []

    if rows:
        lines.append(_records(table, rows, len(rows)))

    refs: dict[int, list[str]] = {}
    for label, found in table['row_notes'].items():
        for number in found:
            refs.setdefault(number, []).append(str(label))
    for column, found in table['column_notes'].items():
        for number in found:
            refs.setdefault(number, []).append(f'column {column}')
    for number in sorted(refs):
        if str(number) in table['notes']:
            where = ', '.join(dict.fromkeys(refs[number]))
            lines.append(f'Note {number} ({where}): {table["notes"][str(number)]}')

    return {'text': '\n'.join(lines), 'sources': [row['source'] for row in rows]}


def _matches(values: dict[str, Any], plan: dict[str, Any]) -> bool:
    if any(values.get(column) is None for column in plan['drop_blank']):
        return False

    for column, rule in plan['filters'].items():
        value = values.get(column)
        if value is None or not _passes(value, rule):
            return False

    return True


def _passes(value: Any, rule: list[Any]) -> bool:
    if rule[0] == 'in':
        return number_key(value) in rule[1]
    if rule[0] == 'range':
        start, end = _ends(rule)
        return (start is None or _compare(value, start) >= 0) and (end is None or _compare(value, end) <= 0)
    if rule[0] == 'threshold':
        if not isinstance(value, float):
            return False
        return {
            'above': value > rule[2],
            'atleast': value >= rule[2],
            'below': value < rule[2],
        }.get(rule[1], value <= rule[2])

    return True


def _effective(plan: dict[str, Any]) -> tuple[str | None, str]:
    """A total, average, highest or lowest with no measure chosen is a count of records."""
    if plan['measure'] is None and plan['operation'] in ('sum', 'average', 'max', 'min', *CHANGES):
        return None, 'count'

    return plan['measure'], plan['operation']


def _shaped(plan: dict[str, Any], operation: str) -> dict[str, Any]:
    shaped = {**plan, 'operation': operation}
    if shaped['group'] is None:
        shaped['group'] = _implicit_group(shaped, operation)

    return shaped


def _ends(rule: list[Any]) -> tuple[Any, Any]:
    """A range's ends, swapped when the start is after the end."""
    start = rule[1] if len(rule) > 1 else None
    end = rule[2] if len(rule) > 2 else None
    if start is not None and end is not None and _compare(start, end) > 0:
        return end, start

    return start, end


def _constraints(plan: dict[str, Any]) -> list[tuple[str, str]]:
    """The plan's constraints in order: each filter, then each blank-row column."""
    filters = [('filter', str(column)) for column in plan['filters']]

    return filters + [('blank', column) for column in plan['drop_blank']]


def _without(plan: dict[str, Any], drop: str, column: str) -> dict[str, Any]:
    if drop == 'filter':
        return {**plan, 'filters': {name: rule for name, rule in plan['filters'].items() if name != column}}

    return {**plan, 'drop_blank': [name for name in plan['drop_blank'] if name != column]}


def _constraint(plan: dict[str, Any], drop: str, column: str) -> str:
    return _filter_text(column, plan['filters'][column]) if drop == 'filter' else f'{column} not blank'


def _unmatched_filters(table: Table, plan: dict[str, Any]) -> str:
    """How many records each constraint lets through alone, so a reader can say none match instead of abstaining."""
    parts = []
    for drop, column in _constraints(plan):
        alone = {
            'filters': {column: plan['filters'][column]} if drop == 'filter' else {},
            'drop_blank': [column] if drop == 'blank' else [],
        }
        parts.append(f'{_constraint(plan, drop, column)} ({len(select(table, alone))} records)')

    return 'No record matches the filters. Each on its own matches: ' + '; '.join(parts) + '.'


def _has_number(rows: list[Row], measure: str) -> bool:
    return any(isinstance(row['values'].get(measure), float) for row in rows)


def _no_data(table: Table, plan: dict[str, Any], measure: str, matched: int) -> str:
    """
    Every matched record is blank in the measure: a line stating that and, for each period the
    plan filters on, the periods that hold values, so a year the source left empty reads as
    missing, not 0.
    """
    line = f'{measure} is blank in all {matched} matched records.'

    for name, rule in plan['filters'].items():
        column = str(name)
        if rule[0] != 'range' or table['columns'].get(column, {}).get('role') != 'period':
            continue
        held = {
            number_key(row['values'][column])
            for row in table['rows']
            if isinstance(row['values'].get(measure), float) and row['values'].get(column) is not None
        }
        listed = [compat.to_str(value) for value in table['columns'][column].get('values') or []]
        periods = [value for value in listed if value in held]
        if periods:
            line += f' The table holds {measure} values for {column} from {periods[0]} to {periods[-1]} only.'
        else:
            line += f' The table holds no {measure} value for any {column}.'

    return line


def _amount(value: float | None, unit: str) -> str:
    return 'no data' if value is None else format_number(value) + (f' {unit}' if unit != '' else '')


def _rest(rows: list[Row], measure: str | None, unit: str) -> str:
    values = [] if measure is None else [row['values'].get(measure) for row in rows]
    numbers = [value for value in values if isinstance(value, float)]
    if not numbers:
        return f'Not shown: {len(rows)} more records.'
    suffix = f' {unit}' if unit != '' else ''
    low, high, total = format_number(min(numbers)), format_number(max(numbers)), format_number(compat.total(numbers))

    return f'Not shown: {len(rows)} more records, {measure} from {low} to {high}{suffix}, sum {total}{suffix}.'


def _implicit_group(plan: dict[str, Any], operation: str) -> str | None:
    """
    A total, average or count over several named values of one column is shown per value, so a
    comparison sees each side.
    """
    if operation not in ('sum', 'average', 'count', *CHANGES):
        return None

    for column, rule in plan['filters'].items():
        if rule[0] == 'in' and len(rule[1]) > 1:
            return str(column)

    return None


def _grouped(rows: list[Row], group: str, operation: str, measure: str | None, unit: str, lowest: bool) -> list[str]:
    groups: dict[str, list[Row]] = {}
    for row in rows:
        value = row['values'].get(group)
        groups.setdefault('(blank)' if value is None else number_key(value), []).append(row)

    results = {name: _aggregate(operation, members, measure) for name, members in groups.items()}
    ordered = sorted(results.items(), key=cmp_to_key(lambda a, b: _rank(a[1]['value'], b[1]['value'], lowest)))
    of = f' of {measure}' if measure is not None else ''
    lines = [f'Result: {operation}{of} by {group} ({len(results)} groups):']

    for name, result in ordered[:MAX_GROUPS]:
        blank = f', {result["blank"]} blank' if result['blank'] > 0 else ''
        lines.append(f'  {name}: {_amount(result["value"], unit)} ({result["records"]} records{blank})')

    return lines


def _rank(left: float | None, right: float | None, lowest: bool) -> int:
    """Groups highest first, or lowest first for min; no value last either way."""
    if left is None or right is None:
        return (left is None) - (right is None)
    if lowest:
        return (left > right) - (left < right)

    return (right > left) - (right < left)


def _span(table: Table, plan: dict[str, Any]) -> tuple[str, str, str] | None:
    """The first range filter on a period column with both ends, as `(column, start key, end key)`."""
    for name, rule in plan['filters'].items():
        start, end = _ends(rule) if rule[0] == 'range' else (None, None)
        if start is not None and end is not None and table['columns'].get(name, {}).get('role') == 'period':
            return str(name), number_key(start), number_key(end)

    return None


def _changes(
    rows: list[Row], group: str | None, operation: str, measure: str, unit: str, span: tuple[str, str, str]
) -> list[str]:
    """
    The change in the measure from the span's start to its end, overall or per group, highest
    first. Each end is the sum of the measure's numbers in the records at that period.
    """
    column, start_key, end_key = span
    groups: dict[str, list[Row]] = {}
    for row in rows:
        value = '' if group is None else row['values'].get(group)
        groups.setdefault('(blank)' if value is None else number_key(value), []).append(row)

    results: dict[str, dict[str, float | None]] = {}
    for name, members in groups.items():
        start = _at(members, measure, column, start_key)
        end = _at(members, measure, column, end_key)
        change = end - start if start is not None and end is not None else None
        if operation == 'change':
            value = change
        else:
            value = change / start * 100 if change is not None and start is not None and start != 0.0 else None
        results[name] = {'value': value, 'start': start, 'end': end}
    ordered = sorted(results.items(), key=lambda item: _or_lowest(item[1]['value']), reverse=True)

    what = f'{"change" if operation == "change" else "percent change"} of {measure} from {start_key} to {end_key}'
    shown_unit = unit if operation == 'change' else '%'

    def figure(result: dict[str, float | None]) -> str:
        start, end = _amount(result['start'], unit), _amount(result['end'], unit)
        return f'{_amount(result["value"], shown_unit)} ({start_key}: {start}; {end_key}: {end})'

    if group is None:
        return [f'Result: {what} = {figure(results[""])}']

    lines = [f'Result: {what} by {group} ({len(results)} groups):']
    lines.extend(f'  {name}: {figure(result)}' for name, result in ordered[:MAX_GROUPS])

    return lines


def _at(rows: list[Row], measure: str, column: str, period: str) -> float | None:
    values = [
        row['values'][measure]
        for row in rows
        if number_key(row['values'].get(column)) == period and isinstance(row['values'].get(measure), float)
    ]

    return compat.total(values) if values else None


def _aggregate(operation: str, rows: list[Row], measure: str | None) -> dict[str, Any]:
    """A count is of distinct source rows, so a melted table counts countries, not country-years."""
    if operation == 'count' or measure is None:
        sources = {row['source'] for row in rows}
        return {'value': float(len(sources)), 'records': len(rows), 'blank': 0, 'row': None}

    values: list[float] = []
    best: Row | None = None
    for row in rows:
        value = row['values'].get(measure)
        if not isinstance(value, float):
            continue
        values.append(value)
        if (
            best is None
            or (operation == 'max' and value > best['values'][measure])
            or (operation == 'min' and value < best['values'][measure])
        ):
            best = row

    result: float | None = None
    if values and operation == 'sum':
        result = compat.total(values)
    elif values and operation == 'average':
        result = compat.total(values) / len(values)
    elif values and operation == 'max':
        result = max(values)
    elif values and operation == 'min':
        result = min(values)

    return {
        'value': result,
        'records': len(values),
        'blank': len(rows) - len(values),
        'row': best if operation in ('max', 'min') else None,
    }


def _compare(value: Any, bound: Any) -> int:
    """Numerically when both sides are numeric, else the value's key and the bound as strings."""
    if compat.is_numeric(value) and compat.is_numeric(bound):
        left, right = compat.to_float(value), compat.to_float(bound)
        return (left > right) - (left < right)

    left_text, right_text = number_key(value).encode(), compat.to_str(bound).encode()

    return (left_text > right_text) - (left_text < right_text)


def _sort_by(rows: list[Row], measure: str | None) -> list[Row]:
    """Highest measure first, a missing one last, ties in record order."""
    if measure is None:
        return list(rows)

    def lowest(row: Row) -> Any:
        value = row['values'].get(measure)
        return -math.inf if value is None else value

    return sorted(rows, key=cmp_to_key(lambda a, b: compat.compare(lowest(b), lowest(a))))


def _describe(plan: dict[str, Any], measure: str | None) -> str:
    named = 'percent change' if plan['operation'] == 'percent' else plan['operation']
    parts = [named + (f' of {measure}' if measure is not None else '')]
    parts.extend(_filter_text(str(column), rule) for column, rule in plan['filters'].items())
    parts.extend(f'{column} not blank' for column in plan['drop_blank'])
    if plan['group'] is not None:
        parts.append(f'grouped by {plan["group"]}')

    return '; '.join(parts)


def _filter_text(column: str, rule: list[Any]) -> str:
    if rule[0] == 'in':
        return f'{column} is ' + ' or '.join(f'"{value}"' for value in rule[1])
    if rule[0] == 'range':
        start, end = _ends(rule)
        return f'{column} from {"start" if start is None else start} to {"end" if end is None else end}'

    return f'{column} {rule[1]} {format_number(rule[2])}'


def _column_list(table: Table) -> str:
    names = []

    for column in table['columns'].values():
        if column['role'] == 'constant':
            continue
        unit = column['unit']
        names.append(column['name'] + (f' ({unit})' if unit is not None and f'({unit})' not in column['name'] else ''))

    return ', '.join(names)


def _records(table: Table, rows: list[Row], total: int) -> str:
    columns = [str(column['name']) for column in table['columns'].values() if column['role'] != 'constant']
    lines = [f'Records ({len(rows)} of {total}):', 'source | ' + ' | '.join(columns)]

    for row in rows:
        values = [row['values'].get(name) for name in columns]
        lines.append(row['source'] + ' | ' + ' | '.join('' if value is None else number_key(value) for value in values))

    return '\n'.join(lines)


def _notes(table: Table, rows: list[Row], plan: dict[str, Any]) -> list[str]:
    refs: set[int] = set()

    for row in rows:
        for value in row['values'].values():
            refs.update(table['row_notes'].get(number_key(value)) or [])
    for column, rule in plan['filters'].items():
        refs.update(table['column_notes'].get(column) or [])
        for value in rule[1] if rule[0] == 'in' else []:
            refs.update(table['row_notes'].get(value) or [])

    return [f'Note {number}: {table["notes"][str(number)]}' for number in sorted(refs) if str(number) in table['notes']]


def _or_lowest(value: float | None) -> float:
    return -math.inf if value is None else value
