"""
Turns LaTeX inline markup into plain text: formatting commands unwrapped, citations and
references kept as their keys, accents and symbols as characters, inline maths kept as written.
The rules are stated in spec/formats/latex.md.

Offsets are code points where the PHP package counts bytes; every character either reads is
ASCII, so the results agree except for a `\\verb` delimited by a non-ASCII character.
"""
from __future__ import annotations

import re
import unicodedata

from .. import text

UNWRAP = frozenset({
    'textbf', 'textit', 'emph', 'texttt', 'underline', 'textsc', 'textrm', 'textsf', 'textsl', 'textup', 'textmd',
    'textnormal', 'mbox', 'text', 'uline',
})

CITE = frozenset({
    'cite', 'citep', 'citet', 'citealp', 'citealt', 'citeauthor', 'citeyear', 'parencite', 'textcite', 'autocite',
    'footcite', 'Cite', 'Citep', 'Citet',
})

REF = frozenset({'ref', 'eqref', 'autoref', 'cref', 'Cref', 'pageref', 'nameref', 'vref'})

DROP = frozenset({
    'begin', 'end', 'label', 'nocite', 'documentclass', 'usepackage', 'RequirePackage', 'newcommand', 'renewcommand',
    'providecommand', 'newenvironment', 'renewenvironment', 'newtheorem', 'theoremstyle', 'setlength', 'addtolength',
    'setcounter', 'addtocounter', 'includegraphics', 'input', 'include', 'bibliography', 'bibliographystyle', 'vspace',
    'hspace', 'pagestyle', 'thispagestyle', 'pagenumbering', 'author', 'date', 'thanks', 'affil', 'affiliation',
    'address', 'email', 'institute', 'index', 'graphicspath', 'hypersetup', 'lstset', 'definecolor', 'color',
    'geometry', 'newgeometry', 'captionsetup', 'numberwithin', 'lstinputlisting', 'inputminted', 'fontfamily',
    'fontseries', 'fontshape', 'fontsize', 'usetikzlibrary', 'setmainfont', 'setsansfont', 'setmonofont',
    'setfloatalignment',
})

SYMBOLS = {
    'LaTeX': 'LaTeX', 'TeX': 'TeX', 'LaTeXe': 'LaTeX2e', 'ldots': '\u2026', 'dots': '\u2026', 'textellipsis': '\u2026',
    'textendash': '\u2013', 'textemdash': '\u2014', 'S': '\u00a7', 'P': '\u00b6', 'copyright': '\u00a9',
    'textcopyright': '\u00a9', 'textregistered': '\u00ae', 'texttrademark': '\u2122', 'pounds': '\u00a3',
    'textsterling': '\u00a3', 'euro': '\u20ac', 'texteuro': '\u20ac', 'textbackslash': '\\', 'textasciitilde': '~',
    'textasciicircum': '^', 'textless': '<', 'textgreater': '>', 'textbar': '|', 'textunderscore': '_', 'slash': '/',
    'quad': ' ', 'qquad': ' ', 'newline': ' ', 'linebreak': ' ', 'textquoteleft': "'", 'textquoteright': "'",
    'textquotedblleft': '"', 'textquotedblright': '"', 'textbullet': '\u2022', 'textdegree': '\u00b0',
    'dag': '\u2020', 'ddag': '\u2021', 'i': '\u0131', 'j': '\u0237', 'ss': '\u00df', 'o': '\u00f8', 'O': '\u00d8',
    'ae': '\u00e6', 'AE': '\u00c6', 'oe': '\u0153', 'OE': '\u0152', 'aa': '\u00e5', 'AA': '\u00c5', 'l': '\u0142',
    'L': '\u0141',
}

ACCENTS = {"'": '\u0301', '`': '\u0300', '^': '\u0302', '"': '\u0308', '~': '\u0303', '=': '\u0304', '.': '\u0307'}

LETTER_ACCENTS = {'c': '\u0327', 'v': '\u030c', 'u': '\u0306', 'H': '\u030b', 'k': '\u0328', 'r': '\u030a'}

SPACES = {
    ' ': ' ', '\n': ' ', '\t': ' ', ',': ' ', ';': ' ', ':': ' ', '>': ' ', '!': '', '-': '', '/': '', '@': '',
}

# Quantity commands and their brace arguments.
QUANTITIES = {'num': 1, 'numrange': 2, 'qty': 2, 'SI': 2, 'qtyrange': 3, 'SIrange': 3, 'unit': 1, 'si': 1}

FRACTIONS = frozenset({'frac', 'tfrac', 'dfrac', 'nicefrac', 'sfrac', 'xfrac'})

SI_PREFIXES = {
    'yocto': 'y', 'zepto': 'z', 'atto': 'a', 'femto': 'f', 'pico': 'p', 'nano': 'n', 'micro': '\u00b5', 'milli': 'm',
    'centi': 'c', 'deci': 'd', 'deca': 'da', 'deka': 'da', 'hecto': 'h', 'kilo': 'k', 'mega': 'M', 'giga': 'G',
    'tera': 'T', 'peta': 'P', 'exa': 'E', 'zetta': 'Z', 'yotta': 'Y',
}

SI_UNITS = {
    'metre': 'm', 'meter': 'm', 'gram': 'g', 'kilogram': 'kg', 'second': 's', 'ampere': 'A', 'kelvin': 'K',
    'mole': 'mol', 'candela': 'cd', 'hertz': 'Hz', 'newton': 'N', 'pascal': 'Pa', 'joule': 'J', 'watt': 'W',
    'volt': 'V', 'ohm': '\u03a9', 'coulomb': 'C', 'farad': 'F', 'henry': 'H', 'tesla': 'T', 'weber': 'Wb',
    'siemens': 'S', 'becquerel': 'Bq', 'gray': 'Gy', 'sievert': 'Sv', 'lumen': 'lm', 'lux': 'lx',
    'radian': 'rad', 'steradian': 'sr', 'litre': 'L', 'liter': 'L', 'tonne': 't', 'hour': 'h', 'minute': 'min',
    'day': 'd', 'hectare': 'ha', 'electronvolt': 'eV', 'degreeCelsius': '\u00b0C', 'degree': '\u00b0',
    'arcminute': '\u2032', 'arcsecond': '\u2033', 'percent': '%', 'bel': 'B', 'decibel': 'dB', 'neper': 'Np',
    'bit': 'bit', 'byte': 'B', 'angstrom': '\u00c5', 'astronomicalunit': 'au', 'dalton': 'Da', 'bar': 'bar',
}

# Marks around an unknown command's leftover argument, and before a fraction, removed from the output.
OPEN = '\x01'
CLOSE = '\x02'
FRACTION = '\x03'

# The PHP pattern has no `u` flag: `\d` is ASCII.
_DIMENSION = re.compile(
    r'^[ \t\n]*[-+]?(?:\d+(?:\.\d+)?|\.\d+)[ \t\n]*(?:pt|em|ex|in|cm|mm|bp|pc|sp|mu|px|\\[A-Za-z]+)[ \t\n]*$', re.ASCII
)
_SPAN = re.compile('\x01([^\x01\x02]*)\x02')
_LEFTOVER = re.compile(r'^[A-Za-z0-9._-]{1,12}$')
_UNIT_TOKEN = re.compile(r'\\([A-Za-z]+)|([^\\]+)')
_NAME = re.compile(r'[A-Za-z]+')
_VERB = re.compile(r'\\[vV]erb\*?([^A-Za-z \t\n*])')
_LETTER = re.compile(r'[A-Za-z]')


def convert(source: str) -> str:
    return _unmark(_marked(source))


def paragraph(source: str) -> str:
    """
    A paragraph's text, or `''` when it holds nothing but leftover arguments of unknown commands,
    each one short token of letters, digits, `.`, `_` or `-` (`\\pgfsetlayers{main}`).
    """
    marked = _marked(source)
    spans = _SPAN.findall(marked)
    outside = text.squash(_SPAN.sub('', marked))
    words = [span for span in spans if text.trim(span) != '']
    single = [span for span in words if _LEFTOVER.match(text.trim(span))]

    return '' if outside == '' and words and len(single) == len(words) else _unmark(marked)


def _unmark(marked: str) -> str:
    return marked.replace(OPEN, '').replace(CLOSE, '')


def _marked(source: str) -> str:
    """Converts, wrapping each unknown command's leftover argument in OPEN and CLOSE."""
    out = ''
    n = len(source)
    i = 0

    while i < n:
        char = source[i]
        if char == '\\':
            piece, i = _command(source, i)
            if piece.startswith(FRACTION):
                piece = (' ' if _ends_with_digit(out) else '') + piece[1:]
        elif char == '{':
            inner, i = group(source, i)
            piece = convert(inner)
        elif char == '$':
            piece, i = _math(source, i)
        else:
            piece, i = _character(source, i)
        out += piece

    return out


def _ends_with_digit(written: str) -> bool:
    """PCRE's `[0-9]$`: the last character, or the one before a final line break, is an ASCII digit."""
    digits = tuple('0123456789')

    return written.endswith(digits) or (written.endswith('\n') and written[:-1].endswith(digits))


def space(source: str, at: int, newline: bool = True) -> int:
    """The offset after spaces and tabs and, when allowed, one line break with the spaces after it."""
    n = len(source)
    while at < n and source[at] in ' \t':
        at += 1
    if newline and at < n and source[at] == '\n':
        at += 1
        while at < n and source[at] in ' \t':
            at += 1

    return at


def group(source: str, at: int) -> tuple[str, int]:
    """The content of the brace group opening at `at` and the offset after it; an unclosed group runs to the end."""
    depth = 0
    i = at
    n = len(source)
    while i < n:
        char = source[i]
        if char == '\\':
            i += 1
        elif char == '{':
            depth += 1
        elif char == '}':
            depth -= 1
            if depth == 0:
                return source[at + 1 : i], i + 1
        i += 1

    return source[at + 1 :], n


def optional(source: str, at: int) -> tuple[str, int]:
    """The content of the `[...]` opening at `at` and the offset after it: the first `]` outside braces closes it."""
    depth = 0
    i = at + 1
    n = len(source)
    while i < n:
        char = source[i]
        if char == '\\':
            i += 1
        elif char == '{':
            depth += 1
        elif char == '}':
            depth -= 1
        elif char == ']' and depth <= 0:
            return source[at + 1 : i], i + 1
        i += 1

    return source[at + 1 :], n


def argument(source: str, at: int) -> tuple[str | None, int]:
    """
    A command's required argument after any optional ones, each allowed to follow spaces and one
    line break; None, and `at` unchanged, when no brace group follows.
    """
    i = space(source, at)
    while source[i : i + 1] == '[':
        i = space(source, optional(source, i)[1])

    return group(source, i) if source[i : i + 1] == '{' else (None, at)


def direct_arguments(source: str, at: int) -> tuple[list[str], int]:
    """The `[...]` and `{...}` groups directly after `at`; returns the brace groups' contents."""
    groups = []
    while True:
        char = source[at : at + 1]
        if char == '[':
            at = optional(source, at)[1]
        elif char == '{':
            inner, at = group(source, at)
            groups.append(inner)
        else:
            return groups, at


def _command(source: str, at: int) -> tuple[str, int]:
    following = source[at + 1 : at + 2]
    if following == '':
        return '', at + 1
    match = _NAME.match(source, at + 1)
    if match is None:
        return _symbol(source, at, following)

    name = match.group(0)
    i = at + 1 + len(name)
    i = i + 1 if source[i : i + 1] == '*' else i

    if name in ('verb', 'Verb'):
        return _verb(source, at)
    if name in LETTER_ACCENTS and source[i : i + 1] == '{':
        inner, i = group(source, i)
        return _accent(convert(inner), LETTER_ACCENTS[name]), i
    if name in SYMBOLS:
        return SYMBOLS[name], i

    return _named(source, name, i)


def _quantity(source: str, name: str, i: int) -> tuple[str, int]:
    """
    siunitx quantities and the `units` package: `\\qty{9.81}{\\metre\\per\\second\\squared}` gives
    `9.81 m/s\u00b2`, `\\unit[11]{inches}` gives `11 inches`.
    """
    value = None
    j = space(source, i)
    if name == 'unit' and source[j : j + 1] == '[':
        option, after = optional(source, j)
        if '=' not in option:
            value, i = option, after
    parts = []
    for _ in range(QUANTITIES[name]):
        found, i = argument(source, i)
        parts.append(found or '')
    plain = name in ('num', 'numrange')
    counted = [] if name in ('unit', 'si') else parts[: len(parts) if plain else len(parts) - 1]
    numbers = [text.trim(convert(part)) for part in counted]
    unit = '' if plain else _units(parts[-1])
    number = '\u2013'.join(([] if value is None else [text.trim(convert(value))]) + numbers)

    return text.trim(f'{number} {unit}'), i


def _units(raw: str) -> str:
    """
    A siunitx unit: prefixes and units as symbols, `\\per` as `/`, `\\squared` and `\\cubed` (or
    `\\square` and `\\cubic` before the unit) as powers; other macros by name, other text converted.
    """
    out = ''
    prefix = power = joiner = ''
    for token in _UNIT_TOKEN.finditer(raw):
        macro = token.group(1)
        if macro is None:
            out += joiner + text.trim(convert(token.group(2)))
            joiner = ''
        elif macro in SI_PREFIXES:
            prefix += SI_PREFIXES[macro]
        elif macro == 'per':
            joiner = '/'
        elif macro in ('square', 'cubic'):
            power = '\u00b2' if macro == 'square' else '\u00b3'
        elif macro in ('squared', 'cubed'):
            out += '\u00b2' if macro == 'squared' else '\u00b3'
        else:
            symbol = SI_UNITS.get(macro, macro)
            out += (joiner if joiner != '' else ('' if out == '' else ' ')) + prefix + symbol + power
            prefix = power = joiner = ''

    return text.trim(out)


def _named(source: str, name: str, i: int) -> tuple[str, int]:
    if name in QUANTITIES:
        return _quantity(source, name, i)
    if name in FRACTIONS:
        top, i = argument(source, i)
        bottom, i = argument(source, i)
        return f'{FRACTION}{text.trim(convert(top or ""))}/{text.trim(convert(bottom or ""))}', i
    if name in UNWRAP:
        found, i = argument(source, i)
        return ('' if found is None else convert(found)), i
    if name == 'url':
        found, i = argument(source, i)
        return text.trim(found or ''), i
    if name == 'href':
        url, i = argument(source, i)
        label, i = argument(source, i)
        return f'{convert(label or "")} ({text.trim(url or "")})', i
    if name == 'footnote':
        found, i = argument(source, i)
        return ('' if found is None else f' ({convert(found)})'), i
    if name in CITE:
        found, i = argument(source, i)
        keys = [key for key in (text.trim(part) for part in (found or '').split(',')) if key != '']
        return f'[{", ".join(keys)}]', i
    if name in REF:
        found, i = argument(source, i)
        return text.trim(found or ''), i

    groups, i = direct_arguments(source, i)
    last = groups[-1] if groups else None
    if last is None or name in DROP or _DIMENSION.match(last):
        return '', i

    return OPEN + convert(last) + CLOSE, i


def _symbol(source: str, at: int, following: str) -> tuple[str, int]:
    if following == '\\':
        i = at + 2
        i = i + 1 if source[i : i + 1] == '*' else i
        return ' ', (optional(source, i)[1] if source[i : i + 1] == '[' else i)
    if following == '(':
        end = source.find('\\)', at + 2)
        end = len(source) if end == -1 else end + 2
        return source[at:end], end
    if following in ACCENTS:
        return _symbol_accent(source, at + 2, ACCENTS[following])

    return SPACES.get(following, following), at + 2


def _symbol_accent(source: str, i: int, mark: str) -> tuple[str, int]:
    char = source[i : i + 1]
    if char == '{':
        inner, i = group(source, i)
        return _accent(convert(inner), mark), i
    if char == '\\':
        base, i = _command(source, i)
        return _accent(base, mark), i
    if char != '' and _LETTER.match(char):
        return _accent(char, mark), i + 1

    return '', i


def _accent(base: str, mark: str) -> str:
    if base == '':
        return ''

    return unicodedata.normalize('NFC', base[0] + mark) + base[1:]


def _verb(source: str, at: int) -> tuple[str, int]:
    match = _VERB.match(source, at)
    if match is None:
        return '', at + 5
    start = match.end()
    end = source.find(match.group(1), start)
    end = len(source) if end == -1 else end

    return source[start:end], min(len(source), end + 1)


def _math(source: str, at: int) -> tuple[str, int]:
    double = source[at + 1 : at + 2] == '$'
    i = at + (2 if double else 1)
    n = len(source)
    while i < n:
        if source[i] == '\\':
            i += 1
        elif source[i] == '$' and (not double or source[i + 1 : i + 2] == '$'):
            end = i + (2 if double else 1)
            return source[at:end], end
        i += 1

    return '$', at + 1


def _character(source: str, at: int) -> tuple[str, int]:
    char = source[at]
    if char == '}':
        return '', at + 1
    if char == '~':
        return ' ', at + 1
    if source.startswith('---', at):
        return '\u2014', at + 3
    if source.startswith('--', at):
        return '\u2013', at + 2
    if source.startswith('``', at) or source.startswith("''", at):
        return '"', at + 2
    if char == '`':
        return "'", at + 1

    return char, at + 1
