"""
PHP behaviour the tabular profile depends on, so the Python package computes, sorts and writes
numbers exactly as the PHP package does: number_format's rounding, the float-to-string cast,
PHP 8's comparison of numbers with strings, natural ordering, and the ASCII-only `trim`.
"""
from __future__ import annotations

import math
import re
from collections.abc import Iterable
from decimal import ROUND_HALF_EVEN, Context, Decimal
from typing import Any

# PHP's `trim()` with no character list: ASCII space, tab, newline, carriage return, NUL and vertical tab.
_TRIM = ' \t\n\r\x00\x0b'

_NUMERIC = re.compile(r'[ \t\n\r\v\f]*[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?[ \t\n\r\v\f]*')

_LEADING_NUMBER = re.compile(r'[ \t\n\r\v\f]*[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?')

_POWERS = [10.0**power for power in range(23)]

_LOG_STEPS = [10.0**power for power in range(-8, 23)]


def trim(text: str) -> str:
    return text.strip(_TRIM)


def float_str(value: float) -> str:
    """
    `(string) $float`: 14 significant digits, in exponent form as `1.0E+25` from 1e14 and below
    1e-4. PHP's dtoa drops trailing zeros except after an exact tie rounded down, where it keeps
    all 14 digits: 100000000000005.0 is `1.0000000000000E+14`.
    """
    if math.isnan(value):
        return 'NAN'
    if math.isinf(value):
        return 'INF' if value > 0 else '-INF'
    if value == 0:
        return '-0' if math.copysign(1.0, value) < 0 else '0'

    digits, point = _significant(Decimal(value).copy_abs())
    sign = '-' if value < 0 else ''

    if point < -3 or point > 14:
        exponent = point - 1
        return f'{sign}{digits[0]}.{digits[1:] or "0"}E{"+" if exponent >= 0 else "-"}{abs(exponent)}'
    if point <= 0:
        return f'{sign}0.{"0" * -point}{digits}'
    if point >= len(digits):
        return sign + digits + '0' * (point - len(digits))

    return f'{sign}{digits[:point]}.{digits[point:]}'


def _significant(exact: Decimal) -> tuple[str, int]:
    """The digits PHP's dtoa gives for 14 significant digits, and the decimal point's position."""
    rounded = Context(prec=14, rounding=ROUND_HALF_EVEN).plus(exact)
    exact_digits = ''.join(map(str, exact.as_tuple().digits))
    digits = ''.join(map(str, rounded.as_tuple().digits))
    discarded = exact_digits[14:]
    tie_down = discarded[:1] == '5' and discarded[1:].strip('0') == '' and rounded < exact

    if not tie_down:
        digits = digits.rstrip('0') or '0'

    return digits, rounded.adjusted() + 1


def to_str(value: Any) -> str:
    """`(string) $value` for the values a table holds: a float, a string or null."""
    if value is None:
        return ''
    if isinstance(value, float):
        return float_str(value)

    return str(value)


def is_numeric(value: Any) -> bool:
    """`is_numeric()`: a number, or a string that reads whole as one, surrounding whitespace allowed."""
    if isinstance(value, (int, float)) and not isinstance(value, bool):
        return True

    return isinstance(value, str) and _NUMERIC.fullmatch(value) is not None


def to_float(value: Any) -> float:
    """`(float) $value`: a string's leading number, or 0."""
    if isinstance(value, (int, float)):
        return float(value)
    if value is None:
        return 0.0

    match = _LEADING_NUMBER.match(str(value))

    return float(match.group(0)) if match else 0.0


def compare(left: Any, right: Any) -> int:
    """PHP 8's `<=>` between floats and strings."""
    if isinstance(left, str) and isinstance(right, str):
        if is_numeric(left) and is_numeric(right):
            return _sign(to_float(left), to_float(right))
        return _sign(left, right)
    if isinstance(left, str):
        return -compare(right, left)
    if isinstance(right, str):
        if is_numeric(right):
            return _sign(float(left), to_float(right))
        return _sign(float_str(float(left)), right)

    return _sign(float(left), float(right))


def php_min(values: Iterable[Any]) -> Any:
    """`min()`: the first value no later value compares below."""
    iterator = iter(values)
    best = next(iterator)

    for value in iterator:
        if compare(value, best) < 0:
            best = value

    return best


def php_max(values: Iterable[Any]) -> Any:
    iterator = iter(values)
    best = next(iterator)

    for value in iterator:
        if compare(value, best) > 0:
            best = value

    return best


def total(values: Iterable[float]) -> float:
    """`array_sum()`: plain left-to-right addition. Python's `sum` compensates for rounding, and differs."""
    result = 0.0

    for value in values:
        result += value

    return result


def number_format(value: float, decimals: int, separator: str) -> str:
    """`number_format($value, $decimals, '.', $separator)`, including PHP's pre-rounding to 15 digits."""
    negative = value < 0
    rounded = _round(-value if negative else value, decimals)
    fixed = f'{abs(rounded):.{max(0, decimals)}f}'
    whole, _, fraction = fixed.partition('.')

    if separator:
        groups = []
        while len(whole) > 3:
            groups.insert(0, whole[-3:])
            whole = whole[:-3]
        whole = separator.join([whole, *groups])

    sign = '-' if negative and rounded != 0 else ''

    return sign + whole + (f'.{fraction}' if decimals > 0 else '')


def natural_compare(left: str, right: str) -> int:
    """`strnatcmp()`: PHP's strnatcmp_ex, comparing UTF-8 bytes."""
    a = left.encode()
    b = right.encode()

    if not a or not b:
        return 0 if len(a) == len(b) else (1 if len(a) > len(b) else -1)

    return _natural(a, b)


def _natural(a: bytes, b: bytes) -> int:
    a_end, b_end = len(a), len(b)
    ai = bi = 0
    leading = True

    def at(text: bytes, index: int) -> int:
        return text[index] if index < len(text) else 0

    while True:
        ca, cb = at(a, ai), at(b, bi)

        while leading and ca == 48 and ai + 1 < a_end and _digit(a[ai + 1]):
            ai += 1
            ca = a[ai]
        while leading and cb == 48 and bi + 1 < b_end and _digit(b[bi + 1]):
            bi += 1
            cb = b[bi]
        leading = False

        while _space(ca):
            ai += 1
            ca = at(a, ai)
        while _space(cb):
            bi += 1
            cb = at(b, bi)

        if _digit(ca) and _digit(cb):
            compared, ai, bi = (_compare_left if ca == 48 or cb == 48 else _compare_right)(a, ai, b, bi)
            if compared != 0:
                return compared
            if ai == a_end and bi == b_end:
                return 0
            if ai == a_end:
                return -1
            if bi == b_end:
                return 1
            ca, cb = at(a, ai), at(b, bi)

        if ca != cb:
            return -1 if ca < cb else 1

        ai += 1
        bi += 1
        if ai >= a_end and bi >= b_end:
            return 0
        if ai >= a_end:
            return -1
        if bi >= b_end:
            return 1


def _compare_right(a: bytes, ai: int, b: bytes, bi: int) -> tuple[int, int, int]:
    """Right-aligned digit runs: the longer run wins, else the first differing digit."""
    bias = 0

    while True:
        a_done = ai >= len(a) or not _digit(a[ai])
        b_done = bi >= len(b) or not _digit(b[bi])
        if a_done and b_done:
            return bias, ai, bi
        if a_done:
            return -1, ai, bi
        if b_done:
            return 1, ai, bi
        if bias == 0 and a[ai] != b[bi]:
            bias = -1 if a[ai] < b[bi] else 1
        ai += 1
        bi += 1


def _compare_left(a: bytes, ai: int, b: bytes, bi: int) -> tuple[int, int, int]:
    """Left-aligned digit runs, as after a leading zero: the first differing digit wins."""
    while True:
        a_done = ai >= len(a) or not _digit(a[ai])
        b_done = bi >= len(b) or not _digit(b[bi])
        if a_done and b_done:
            return 0, ai, bi
        if a_done:
            return -1, ai, bi
        if b_done:
            return 1, ai, bi
        if a[ai] != b[bi]:
            return (-1 if a[ai] < b[bi] else 1), ai, bi
        ai += 1
        bi += 1


def _digit(byte: int) -> bool:
    return 48 <= byte <= 57


def _space(byte: int) -> bool:
    return byte in (9, 10, 11, 12, 13, 32)


def _sign(left: Any, right: Any) -> int:
    return (left > right) - (left < right)


def _round(value: float, places: int) -> float:
    """PHP 8.3's `_php_math_round` in half-up mode."""
    if not math.isfinite(value) or value == 0.0:
        return value

    precision = 14 - _log10_floor(value)
    f1 = _pow10(abs(places))

    if places < precision < places + 15:
        f2 = _pow10(abs(precision))
        scaled = value * f2 if precision >= 0 else value / f2
        scaled = _round_half_up(scaled)
        scaled = scaled / _pow10(abs(places - precision))
    else:
        scaled = value * f1 if places >= 0 else value / f1
        if abs(scaled) >= 1e15:
            return value

    scaled = _round_half_up(scaled)

    return scaled / f1 if places > 0 else scaled * f1


def _round_half_up(value: float) -> float:
    return float(math.floor(value + 0.5)) if value >= 0.0 else float(math.ceil(value - 0.5))


def _pow10(power: int) -> float:
    return _POWERS[power] if 0 <= power <= 22 else 10.0**power


def _log10_floor(value: float) -> int:
    """`php_intlog10abs`: a binary search over exact powers of ten, as PHP computes it."""
    value = abs(value)

    if value < 1e-8 or value > 1e22:
        return math.floor(math.log10(value))

    result = 15
    for step in (8, 4, 2, 1):
        result += -step if value < _LOG_STEPS[result] else step
    if value < _LOG_STEPS[result]:
        result -= 1

    return result - 8
