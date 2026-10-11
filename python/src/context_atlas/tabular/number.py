"""
How numbers are written: whole numbers plain below 10,000, so years read as years, and with
thousands separators from 10,000; others to at most four decimal places, trailing zeros dropped.
"""
from __future__ import annotations

import math
from typing import Any

from ..compat import number_format, to_str


def format_number(value: float | str | None) -> str:
    if value is None:
        return '(none)'
    if isinstance(value, str):
        return value

    return _written(float(value), ',' if abs(value) >= 10000 else '')


def key(value: Any) -> str:
    """
    The string a cell value is matched by in filters and grouping: numbers without separators,
    so an identifier such as 2000034784 matches as the question writes it.
    """
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        return to_str(value)

    return _written(float(value), '')


def _written(value: float, separator: str) -> str:
    if math.isfinite(value) and math.floor(value) == value and abs(value) < 1e15:
        return number_format(value, 0, separator)

    return number_format(value, 4, separator).rstrip('0').rstrip('.')
