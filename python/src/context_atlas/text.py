"""
The text rules every implementation shares, stated in spec/behaviour.md under "Text rules".

Lengths are in code points, which is what `len` counts on a Python `str`.
"""
from __future__ import annotations

import math
import re
import unicodedata
from collections.abc import Iterator

# PCRE2's `\s` under PHP's `/u` flag. Python's own `\s` adds U+001C to U+001F and leaves out
# U+180E, so the class is spelled out to split and trim where the PHP package does.
WHITESPACE = '\t\n\x0b\x0c\r \x85\xa0\u1680\u180e\u2000-\u200a\u2028\u2029\u202f\u205f\u3000'

# The characters PCRE2 matches with [\\p{Han}\\p{Hiragana}\\p{Katakana}\\p{Hangul}] in PHP (script
# extensions included, so CJK punctuation counts); `re` has no script classes.
_WIDE = re.compile(
    '['
    '\U00001100-\U000011ff\U00002e80-\U00002e99\U00002e9b-\U00002ef3\U00002f00-\U00002fd5'
    '\U00003001-\U00003003\U00003005-\U00003011\U00003013-\U0000301f\U00003021-\U00003035'
    '\U00003037-\U0000303f\U00003041-\U00003096\U00003099-\U000030ff\U00003131-\U0000318e'
    '\U00003190-\U0000319f\U000031c0-\U000031e3\U000031f0-\U0000321e\U00003220-\U00003247'
    '\U00003260-\U0000327e\U00003280-\U000032b0\U000032c0-\U000032cb\U000032d0-\U00003370'
    '\U0000337b-\U0000337f\U000033e0-\U000033fe\U00003400-\U00004dbf\U00004e00-\U00009fff'
    '\U0000a700-\U0000a707\U0000a960-\U0000a97c\U0000ac00-\U0000d7a3\U0000d7b0-\U0000d7c6'
    '\U0000d7cb-\U0000d7fb\U0000f900-\U0000fa6d\U0000fa70-\U0000fad9\U0000fe45-\U0000fe46'
    '\U0000ff61-\U0000ffbe\U0000ffc2-\U0000ffc7\U0000ffca-\U0000ffcf\U0000ffd2-\U0000ffd7'
    '\U0000ffda-\U0000ffdc\U00016fe2-\U00016fe3\U00016ff0-\U00016ff1\U0001aff0-\U0001aff3'
    '\U0001aff5-\U0001affb\U0001affd-\U0001affe\U0001b000-\U0001b122\U0001b150-\U0001b152'
    '\U0001b164-\U0001b167\U0001d360-\U0001d371\U0001f200\U0001f250-\U0001f251\U00020000-\U0002a6df'
    '\U0002a700-\U0002b738\U0002b740-\U0002b81d\U0002b820-\U0002cea1\U0002ceb0-\U0002ebe0'
    '\U0002f800-\U0002fa1d\U00030000-\U0003134a'
    ']'
)
_RUN = re.compile(f'[{WHITESPACE}]+')
_EDGES = re.compile(f'^[{WHITESPACE}]+|[{WHITESPACE}]+$')
_AFTER_SENTENCE_END = re.compile(f'(?<=[.?!…])[{WHITESPACE}]+')
_NOT_SLUG = re.compile('[^a-z0-9]+')


def tokens(text: str, chars_per_token: int) -> int:
    """`ceil((length - wide) / chars_per_token + wide)`: a Han, Hiragana, Katakana or Hangul character is a token."""
    wide = len(_WIDE.findall(text))

    return math.ceil((len(text) - wide) / max(1, chars_per_token) + wide)


def trim(text: str) -> str:
    return _EDGES.sub('', text)


def squash(text: str) -> str:
    return _RUN.sub(' ', trim(text))


def sentences(text: str) -> list[str]:
    """Splits after `.`, `?`, `!` or `…` at whitespace followed by an uppercase letter (category Lu)."""
    text = trim(text)
    parts: list[str] = []
    start = 0

    for match in _sentence_breaks(text, ('Lu',)):
        parts.append(text[start:match.start()])
        start = match.end()

    parts.append(text[start:])

    return parts


def tail(text: str, count: int, chars_per_token: int) -> str:
    """
    The last `count` tokens of a text, starting at the first sentence that begins inside them,
    or failing that at the first whole word, so an overlap never opens mid-word.
    """
    chars = max(0, count * chars_per_token)

    if len(text) <= chars:
        return text

    piece = text[-chars:]
    sentence = next(_sentence_breaks(piece, ('Lu', 'Lt')), None)

    if sentence is not None:
        return piece[sentence.end():]

    word = _RUN.search(piece)

    return piece if word is None else piece[word.end():]


def slug(name: str, length: int) -> str:
    """Lowercase ASCII letters and digits joined by hyphens, accents dropped, cut to `length`."""
    plain = ''.join(char for char in unicodedata.normalize('NFKD', name) if unicodedata.category(char) != 'Mn')
    hyphenated = _NOT_SLUG.sub('-', plain.lower()).strip('-')

    return hyphenated[:length].rstrip('-')


def head(text: str, chars: int) -> str:
    return trim(text)[:chars]


def end(text: str, chars: int) -> str:
    text = trim(text)

    return text if len(text) <= chars else text[-chars:]


def _sentence_breaks(text: str, categories: tuple[str, ...]) -> Iterator[re.Match[str]]:
    """
    Each whitespace run after a sentence end that is followed by a letter in `categories`.

    `re` has no Unicode category classes, so the run is matched whole and the next code point
    checked by hand. A run cut short by backtracking is followed by more whitespace, never by
    a letter, so this finds the same places as PCRE's `(?<=[.?!…])\\s+(?=\\p{Lu})`.
    """
    for match in _AFTER_SENTENCE_END.finditer(text):
        if match.end() < len(text) and unicodedata.category(text[match.end()]) in categories:
            yield match
