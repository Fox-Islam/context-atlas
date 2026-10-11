"""
Which elements are read and how their text is taken: the drop rules, block elements, speaker
markers, and the line and raw text of a subtree.
"""
from __future__ import annotations

import re
import unicodedata
from collections.abc import Callable, Iterable

from ... import text
from .element import Element, ascii_lower

BLOCK = {
    'address', 'article', 'blockquote', 'body', 'caption', 'center', 'dd', 'details', 'dialog', 'dir', 'div',
    'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'header', 'hgroup', 'hr', 'html', 'legend', 'li',
    'main', 'p', 'search', 'section', 'summary', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr',
}

LISTS = {'menu', 'ol', 'ul'}

HEADINGS = {'h1': 1, 'h2': 2, 'h3': 3, 'h4': 4, 'h5': 5, 'h6': 6}

_DROPPED = {
    'aside', 'audio', 'button', 'canvas', 'head', 'iframe', 'map', 'nav', 'noscript', 'object',
    'script', 'select', 'style', 'svg', 'template', 'textarea', 'title', 'video',
}

_DROPPED_CLASSES = {'catlinks', 'mw-editsection', 'mw-jump-link', 'navbox', 'noprint', 'printfooter'}

_HIDDEN_CLASSES = {'d-none', 'hidden', 'hide', 'invisible', 'is-hidden', 'js-hidden', 'u-hidden'}

_PRINT_CLASSES = {
    'print-only', 'printonly', 'show-for-print', 'visible-print', 'visible-print-block', 'visible-print-inline',
}

_RESPONSIVE_CLASS = re.compile(r':|^d-(?:sm|md|lg|xl|xxl)-')

_HIDING_STYLES = {'display:none', 'display:none!important', 'visibility:hidden', 'visibility:hidden!important'}

_FURNITURE = {'footer', 'header'}

_DROPPED_ROLES = {'complementary', 'navigation', 'search', 'tooltip'}

_FURNITURE_ROLES = {'banner', 'contentinfo'}

_PERMALINKS = {'#', '\u00a7', '\u00b6'}

_SPEAKER_CLASS = re.compile(
    r'(?:^|[-_])(?:speakers?|orateur|oratore|orador|redner|sprecher|\u767a\u8a00\u8005)(?:[-_]+(?:name|nom|nome|nombre))?$'
)

_SCRIPT_NUMBER = re.compile(r'^[-+\u2212]?[0-9][0-9.,]*$')

_SCRIPT_SIGN = re.compile(r'^[-+\u2212]?(.)$')

# PHP's pattern has no `u` flag: `\s` is ASCII, and `.` with `s` reaches across lines.
_TEX_DISPLAY = re.compile(r'^\{\\displaystyle\s*(.*)\}$', re.DOTALL | re.ASCII)

_LEVEL = re.compile(r'^[1-6]$')

_STYLE_SPACE = str.maketrans('', '', ' \t\n\f\r')


def dropped(element: Element, in_article: bool, keep_buttons: bool = False) -> bool:
    """`keep_buttons` is true inside a heading, where an accordion puts the heading's text in a `button`."""
    role = element.token('role')

    return (
        (element.name in _DROPPED and not _is_note(element) and not (keep_buttons and element.name == 'button'))
        or (element.name == 'form' and not _holds_content(element))
        or element.attribute('hidden') is not None
        or element.attribute('popover') is not None
        or element.token('aria-hidden') == 'true'
        or role in _DROPPED_ROLES
        or _hidden_by_class(element.tokens('class'))
        or (_hidden_by_style(element.attribute('style')) and find(element, lambda e: e.name == 'math') is None)
        or (not in_article and (element.name in _FURNITURE or role in _FURNITURE_ROLES))
        or _is_permalink(element)
    )


def opens_article(element: Element) -> bool:
    return element.name in ('article', 'main') or element.token('role') == 'main'


def is_block(name: str) -> bool:
    return name in HEADINGS or name in ('pre', 'table') or name in LISTS or name in BLOCK


def is_speaker(element: Element) -> bool:
    """
    An element naming who speaks next: a class token ending in a speaker word (`speaker`,
    `orateur`, `redner` and the others in `_SPEAKER_CLASS`), optionally followed by a name word,
    after a `-` or `_` or at its start, or an `itemprop` of `author`.
    """
    if 'author' in element.tokens('itemprop'):
        return True

    return any(_SPEAKER_CLASS.search(token) for token in element.tokens('class'))


def heading_level(element: Element) -> int | None:
    """The level of an `h1`-`h6`, or of an element with role `heading`: its `aria-level` when 1-6, else 2."""
    if element.name in HEADINGS:
        return HEADINGS[element.name]
    if element.token('role') != 'heading':
        return None

    level = element.token('aria-level') or ''

    return int(level) if _LEVEL.match(level) else 2


def line(parent: Element, in_article: bool, keep_buttons: bool = False) -> str:
    """
    The text of a subtree for one line: a `br` or the edge of a block element adds a space, and
    `math` reads as its text. Callers squash it.
    """
    written = ''
    for child in parent.children:
        if isinstance(child, str):
            written += child
        elif not dropped(child, in_article, keep_buttons):
            inside = in_article or opens_article(child)
            if child.name == 'br':
                written += ' '
            elif child.name == 'math':
                written += math(child)
            elif is_block(child.name):
                written += ' ' + line(child, inside, keep_buttons) + ' '
            else:
                written += script(child, written) + line(child, inside, keep_buttons)

    return written


def script(element: Element, before: str) -> str:
    """
    The mark written before a `sup` (`^`) or `sub` (`_`) whose text is a number or one letter and
    which follows a letter or digit, so `10<sup>23</sup>` reads `10^23`; nothing otherwise, and
    nothing for a footnote reference (a `sup` holding only a link whose `href` has a `#`).
    """
    if element.name not in ('sup', 'sub') or not _ends_alphanumeric(before):
        return ''

    held = [child for child in element.children if not isinstance(child, str) or text.trim(child) != '']
    reference = (
        len(held) == 1
        and isinstance(held[0], Element)
        and held[0].name == 'a'
        and '#' in (held[0].attribute('href') or '')
    )
    if reference or not _script_text(text.squash(line(element, True))):
        return ''

    return '^' if element.name == 'sup' else '_'


def math(element: Element) -> str:
    """
    A `math` element's `alttext` (trimmed, without a TeX `{\\displaystyle ...}` wrapper), else its
    text without `annotation` and `annotation-xml` elements, squashed.
    """
    alt = text.trim(element.attribute('alttext') or '')
    if alt != '':
        wrapped = _TEX_DISPLAY.match(alt)
        return text.trim(wrapped.group(1)) if wrapped else alt

    return text.squash(_math_text(element))


def _math_text(parent: Element) -> str:
    parts = []
    for child in parent.children:
        if isinstance(child, str):
            parts.append(child)
        elif child.name not in ('annotation', 'annotation-xml'):
            parts.append(_math_text(child))

    return ''.join(parts)


def _ends_alphanumeric(before: str) -> bool:
    """PCRE's `[\\p{L}\\p{N}]$`: the last character, or the one before a final line break, is a letter or digit."""
    candidates = [before[-1:]]
    if before.endswith('\n'):
        candidates.append(before[-2:-1])

    return any(char != '' and unicodedata.category(char)[0] in 'LN' for char in candidates)


def _script_text(found: str) -> bool:
    """PCRE's `^[-+\u2212]?(?:[0-9][0-9.,]*|\\p{L})$`, `$` also matching before a final line break."""
    body = found[:-1] if found.endswith('\n') else found
    if _SCRIPT_NUMBER.match(found) or _SCRIPT_NUMBER.match(body):
        return True
    letter = _SCRIPT_SIGN.match(body)

    return letter is not None and unicodedata.category(letter.group(1))[0] == 'L'


def raw(parent: Element, in_article: bool) -> str:
    """The text of a subtree as written, a `br` as a line break."""
    parts = []
    for child in parent.children:
        if isinstance(child, str):
            parts.append(child)
        elif dropped(child, in_article):
            continue
        elif child.name == 'br':
            parts.append('\n')
        else:
            parts.append(raw(child, in_article or opens_article(child)))

    return ''.join(parts)


def find(parent: Element, match: Callable[[Element], bool], skip: Iterable[str] = ()) -> Element | None:
    """The first element in document order that `match` accepts, not looking inside the elements named in `skip`."""
    skipped = set(skip)
    for child in parent.children:
        if not isinstance(child, Element):
            continue
        if match(child):
            return child
        if child.name not in skipped:
            found = find(child, match, skipped)
            if found is not None:
                return found

    return None


def _hidden_by_class(classes: list[str]) -> bool:
    """
    Furniture classes, print-only classes, and utility classes that hide an element unless a
    responsive class (Tailwind `md:block`, Bootstrap `d-md-block`) may show it again.
    """
    if any(token in _DROPPED_CLASSES or token in _PRINT_CLASSES for token in classes):
        return True
    if not any(token in _HIDDEN_CLASSES for token in classes):
        return False

    return not any(_RESPONSIVE_CLASS.search(token) for token in classes)


def _hidden_by_style(style: str | None) -> bool:
    """A `display:none` or `visibility:hidden` declaration, compared without ASCII whitespace and ASCII-lowercased."""
    if style is None:
        return False

    return any(declaration in _HIDING_STYLES for declaration in ascii_lower(style.translate(_STYLE_SPACE)).split(';'))


def _is_note(element: Element) -> bool:
    """An `aside` holding footnotes or a note, which is content instead of a sidebar."""
    if element.name != 'aside':
        return False

    return any('footnote' in token for token in element.tokens('class')) or element.token('role') == 'note'


def _holds_content(form: Element) -> bool:
    """A form wrapping a page's content instead of holding controls."""
    return find(form, lambda e: e.name in ('article', 'main') or e.name in HEADINGS) is not None


def _is_permalink(element: Element) -> bool:
    """A link to a place on the same page whose whole text is a permalink sign."""
    return (
        element.name == 'a'
        and (element.attribute('href') or '').startswith('#')
        and text.squash(raw(element, True)) in _PERMALINKS
    )
