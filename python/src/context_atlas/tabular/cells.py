"""
Turns cell text into values: blanks and placeholders into None, currency, percentages and
thousands separators into numbers, dates into ISO form. Without this a money column exported as
"£487,671,000.00" is text and nothing can be summed.
"""
from __future__ import annotations

import re

from .. import compat
from ..text import WHITESPACE

BLANKS = ('', '#', '[x]', '[c]', '[z]', '[u]', '-', '\u2013', '..', 'n/a', 'N/A', 'NA', ':')

# The PHP patterns without the `u` flag match ASCII digits and letters only, hence re.ASCII.
_DATE = re.compile(r'^([0-9]{1,2})/([0-9]{1,2})/([0-9]{4})$')
_CURRENCY = re.compile(f'^(-?)[{WHITESPACE}]*([£$€])[{WHITESPACE}]*(.*)$')
_NUMBER = re.compile(r'^-?[0-9]+(\.[0-9]+)?([eE][-+]?[0-9]+)?$')
_NOTE = re.compile(r'\[note ([0-9]+)\]', re.IGNORECASE | re.ASCII)
_MARKERS = re.compile(r'\[note [0-9]+\]|\[provisional\]|\[revised\]', re.IGNORECASE | re.ASCII)
_RUN = re.compile(f'[{WHITESPACE}]+')
_YEAR = re.compile(r'^([0-9]{4})$')
_QUARTER = re.compile(r'^([0-9]{4}) (1st|2nd|3rd|4th) quarter$', re.IGNORECASE | re.ASCII)
_SHORT_QUARTER = re.compile(r'^([0-9]{4}) ?Q([1-4])$', re.IGNORECASE | re.ASCII)

Value = float | str | None


def parse(raw: str, date_order: str) -> tuple[Value, str | None]:
    """The value, and the unit the cell carried: a currency sign, `%`, or `date`."""
    text = compat.trim(raw)

    if text in BLANKS:
        return None, None

    unit = None
    currency = _CURRENCY.match(text)
    if currency is not None:
        unit = currency.group(2)
        text = currency.group(1) + currency.group(3)
    if text.endswith('%'):
        unit = '%'
        text = text.rstrip('% ')

    number = text.replace(',', '').replace(' ', '')
    if _NUMBER.match(number) is not None:
        return float(number), unit

    date = _DATE.match(text)
    if date is not None:
        day, month = (date.group(2), date.group(1)) if date_order == 'mdy' else (date.group(1), date.group(2))
        return f'{date.group(3)}-{int(month):02d}-{int(day):02d}', 'date'

    return raw, None


def date_order(cells: list[str]) -> str:
    """
    Day first unless the cells prove otherwise: a first field above 12 is a day, and a second
    field above 12 makes the first the month.
    """
    for cell in cells:
        date = _DATE.match(compat.trim(cell))
        if date is None:
            continue
        if int(date.group(1)) > 12:
            return 'dmy'
        if int(date.group(2)) > 12:
            return 'mdy'

    return 'dmy'


def notes(text: str) -> tuple[str, list[int]]:
    """The label without `[note N]`, `[provisional]` and `[revised]` markers, and the note numbers."""
    return squash(_MARKERS.sub('', text)), [int(number) for number in _NOTE.findall(text)]


def squash(text: str) -> str:
    return compat.trim(_RUN.sub(' ', text))


def period(header: str) -> tuple[str, int, int | None] | None:
    """Label, year and quarter of a period header such as `2021`, `2021 3rd quarter` or `2021Q3`."""
    label = notes(header)[0]

    year = _YEAR.match(label)
    if year is not None:
        return label, int(year.group(1)), None

    quarter = _QUARTER.match(label) or _SHORT_QUARTER.match(label)
    if quarter is None:
        return None

    return label, int(quarter.group(1)), int(quarter.group(2)[0])
