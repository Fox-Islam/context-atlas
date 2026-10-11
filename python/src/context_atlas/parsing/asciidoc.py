"""
Reads the block structure of AsciiDoc: section titles, paragraphs, lists, listing and literal
blocks, compound blocks read for their content, and tables. Attribute entries are recorded and
their references substituted; block attributes, comments and block macros are dropped; an
include becomes a placeholder paragraph. Cross references and links keep their text; other
inline markup is left in the text. The rules are stated in spec/formats/asciidoc.md.
"""
from __future__ import annotations

import re
import unicodedata

from .. import text
from ..blocks import Block
from .block_builder import BlockBuilder, dedent, delimited

# The PHP patterns have no `u` flag: `\d` and `\w` are ASCII.
_HEADING = re.compile(r'^(={1,6}|#{1,6})[ \t]+(.+?)(?:[ \t]+\1)?$')
_SETEXT = re.compile(r'^(=+|-+|~+|\^+|\++)$')
_DELIMITER = re.compile(r'^(?:-{4,}|\.{4,}|\+{4,}|_{4,}|={4,}|\*{4,}|--|[|,:]={3,})$')
_ATTRIBUTE_ENTRY = re.compile(r'^:!?[A-Za-z0-9_][A-Za-z0-9_-]*!?:(?:[ \t].*)?$')
_BLOCK_ATTRIBUTES = re.compile(r'^\[(|[#.%A-Za-z0-9_{,"\'\[].*)\]$')
_BLOCK_TITLE = re.compile(r'^\.([^. \t].*)$')
_PREPROCESSOR = re.compile(r'^(?:ifdef|ifndef|ifeval|endif)::[^ \t]*\[.*\]$')
_INCLUDE = re.compile(r'^include(?:-code)?::([^\[]*)\[.*\]$')
_ATTRIBUTE_DEFINITION = re.compile(r'^:(!?)([A-Za-z0-9_][A-Za-z0-9_-]*)(!?):(?:[ \t]+(.*))?$')
_REFERENCE = re.compile(r'\{([A-Za-z0-9_][A-Za-z0-9_-]*)\}')
_XREF = re.compile(r'xref:([^ \t\[]+)\[([^\]]*)\]')
_ANCHOR = re.compile(r'<<[^,>]+,[ \t]*([^>]+?)[ \t]*>>')
_LINK = re.compile(
    r'(?:link:([^ \t\[]+)|\b((?:https?|ftp|irc)://[^ \t\[]+|mailto:[^ \t\[]+))\[([^\]]*)\]', re.ASCII
)
_BLOCK_MACRO = re.compile(r'^[a-z][A-Za-z0-9_-]*::[^ \t]*\[.*\]$')
_LIST_ITEM = re.compile(
    r'^[ \t]*(\*{1,5}|-|\.{1,5}|\d+\.|[A-Za-z]\.|[ivxIVX]+\)|<(?:\d+|\.)>)[ \t]+(?=[^ \t])', re.ASCII
)
_DESCRIPTION = re.compile(r'^[ \t]*([^ \t].*?)(?::{2,4}|;;)(?:[ \t]+(.*))?$')
_CELL_SPEC = re.compile(r'^(?:(\d+)\*)?(?:(\d+)?(?:\.\d+)?\+)?[<^>]?(?:\.[<^>])?[adehlmsv]?$', re.ASCII)
_CELL_SPEC_END = re.compile(r'[ \t\n]((?:\d+\*)?(?:\d*(?:\.\d+)?\+)?[<^>]?(?:\.[<^>])?[adehlmsv]?)\Z', re.ASCII)
_COMMENT_BLOCK = re.compile(r'^/{4,}$')
_PASS_OR_BREAK = re.compile(r"^(?:'{3,}|<{3,})$")
_TABLE_DELIMITER = re.compile(r'^[|,:]=')
_FORMAT = re.compile(r'\bformat="?(csv|dsv|tsv)\b', re.ASCII)
_HEADER = re.compile(r'%header\b|\b(?:options|opts)="?[^"\]]*\bheader\b', re.ASCII)
_NO_HEADER = re.compile(r'%noheader\b|\b(?:options|opts)="?[^"\]]*\bnoheader\b', re.ASCII)
_CELL_BAR = re.compile(r'(?<!\\)\|')
_COLS = re.compile(r'\bcols="([^"]*)"|\bcols=([^,\]]*)', re.ASCII)
_DIGITS = re.compile(r'^\d+$', re.ASCII)
_SEPARATORS = re.compile(r'[,;]')
_MULTIPLIER = re.compile(r'^\s*(\d+)\*', re.ASCII)
_STYLE = re.compile(r'^([A-Za-z][\w-]*)', re.ASCII)

SETEXT_LEVELS = {'=': 1, '-': 2, '~': 3, '^': 4, '+': 5}

# The built-in character attributes, which a document can redefine or unset.
BUILT_IN = {
    'asterisk': '*', 'nbsp': '\u00a0', 'sp': ' ', 'empty': '', 'blank': '', 'zwsp': '\u200b',
    'amp': '&', 'lt': '<', 'gt': '>', 'quot': '"', 'apos': "'", 'plus': '+', 'caret': '^',
    'tilde': '~', 'backtick': '`', 'startsb': '[', 'endsb': ']', 'vbar': '|', 'pp': '++',
    'cpp': 'C++', 'deg': '\u00b0', 'two-colons': '::', 'two-semicolons': ';;', 'brvbar': '\u00a6',
    'ldquo': '\u201c', 'rdquo': '\u201d', 'lsquo': '\u2018', 'rsquo': '\u2019', 'wj': '\u2060',
}


def parse(source: str) -> list[Block]:
    lines = [line.rstrip(' \t') for line in source.replace('\r\n', '\n').replace('\r', '\n').split('\n')]
    reader = _Reader()
    reader.run(lines, True)

    return reader.out.blocks()


class _Reader:
    def __init__(self) -> None:
        self.out = BlockBuilder()
        self.attributes = dict(BUILT_IN)

    def run(self, lines: list[str], document: bool) -> None:
        n = len(lines)
        blank = True
        attached = False
        attributes = ''
        title = ''
        list_kind: str | None = None
        i = 0

        while i < n:
            line = lines[i]

            if line == '':
                if not self.out.in_list():
                    self.out.close()
                blank = True
                attached = False
                i += 1
                continue
            if _COMMENT_BLOCK.match(line):
                i = _closing(lines, i) + 1
                continue
            if line.startswith('//') or _PREPROCESSOR.match(line):
                i += 1
                continue
            included = _INCLUDE.match(line)
            if included:
                self.out.paragraph('[include: ' + self._inline(text.trim(included.group(1))) + ']')
                blank = True
                i += 1
                continue

            kind = _kind(line)
            is_item = kind is not None
            if self.out.in_list() and blank and kind != list_kind:
                self.out.close()

            setext = self._setext(line)
            if setext is not None:
                if document and setext[1] == 1 and self.out.is_empty():
                    i = self._header(lines, i)
                self.out.heading(self._inline(setext[0]), setext[1])
                blank = True
                i += 1
                continue

            start = blank or attached or not self.out.is_open()
            blank = False
            attached = False

            if _DELIMITER.match(line):
                end = _closing(lines, i)
                self._delimited(line, lines[i + 1 : end], attributes, title)
                i, attributes, title, blank = end + 1, '', '', True
                continue
            match = _BLOCK_ATTRIBUTES.match(line)
            if match:
                self.out.close()
                attributes = match.group(1)
                blank = True
                i += 1
                continue
            if line == '+':
                attached = True
                i += 1
                continue

            if start:
                if _ATTRIBUTE_ENTRY.match(line):
                    i = self._define(lines, i)
                    blank = True
                    i += 1
                    continue
                match = _BLOCK_TITLE.match(line)
                if match:
                    title = match.group(1)
                    blank = True
                    i += 1
                    continue
                match = _HEADING.match(line)
                if match:
                    level = len(match.group(1))
                    if document and level == 1 and match.group(1) == '=' and self.out.is_empty():
                        i = self._header(lines, i)
                    self.out.heading(self._inline(match.group(2)), level)
                    attributes, title, blank = '', '', True
                    i += 1
                    continue
                if _BLOCK_MACRO.match(line) or _PASS_OR_BREAK.match(line):
                    self.out.close()
                    blank = True
                    i += 1
                    continue

                if title != '':
                    self.out.paragraph(self._inline(title))
                title = ''
                style = _style(attributes)
                attributes = ''
                if not is_item and (style in ('source', 'listing', 'literal') or line[0] in ' \t'):
                    end = i
                    while end < n and lines[end] != '':
                        end += 1
                    self.out.code(dedent(lines[i:end]))
                    i = end
                    blank = True
                    continue

            if is_item and (start or self.out.in_list()):
                list_kind = list_kind if self.out.in_list() else kind
                self.out.item(self._inline(_description(line) if kind == 'description' else _item_text(line)))
            elif self.out.in_list():
                self.out.extend(self._inline(line))
            else:
                self.out.text(self._inline(line))
            i += 1

        self.out.close()

    def _setext(self, line: str) -> tuple[str, int] | None:
        """
        A two-line section title: an open paragraph of one line starting with a letter, digit or
        `_`, underlined by one repeated character whose length is within one of the title's.
        """
        title = None if self.out.in_list() else self.out.last()
        if title is None or self.out.count() != 1 or not _SETEXT.match(line) or not _starts_with_word(title):
            return None
        if abs(len(line) - len(title)) > 1:
            return None
        self.out.replace_last('')

        return title, SETEXT_LEVELS[line[0]]

    def _header(self, lines: list[str], i: int) -> int:
        """Skips the document header after the document title: the lines up to the first blank one."""
        end = i
        while end + 1 < len(lines) and lines[end + 1] != '':
            end += 1
            if _ATTRIBUTE_ENTRY.match(lines[end]):
                end = self._define(lines, end)
        self.out.close()

        return end

    def _define(self, lines: list[str], i: int) -> int:
        """
        Records the attribute entry at line `i` (a value continued by lines ending ` \\\\` is
        joined with spaces; `:name!:` or `:!name:` removes it) and returns its last line.
        """
        match = _ATTRIBUTE_DEFINITION.match(lines[i])
        value = (match.group(4) if match else None) or ''
        while value.endswith(' \\') and i + 1 < len(lines):
            i += 1
            value = value[:-2] + ' ' + text.trim(lines[i])
        name = (match.group(2) if match else '').lower()
        if match and (match.group(1) or match.group(3)):
            self.attributes.pop(name, None)
        else:
            self.attributes[name] = text.squash(self._inline(value))

        return i

    def _inline(self, source: str) -> str:
        """Inline substitutions: defined attribute references, then cross references and links reduced to their text."""
        source = _REFERENCE.sub(lambda m: self.attributes.get(m.group(1).lower(), m.group(0)), source)
        source = _XREF.sub(lambda m: text.trim(m.group(2)) if text.trim(m.group(2)) != '' else m.group(1), source)
        source = _ANCHOR.sub(lambda m: m.group(1), source)

        return _LINK.sub(_link, source)

    def _delimited(self, delimiter: str, content: list[str], attributes: str, title: str) -> None:
        self.out.close()
        char = delimiter[0]

        if _TABLE_DELIMITER.match(delimiter):
            self._table(char, content, attributes, title)
            return
        if char == '+':
            return

        self.out.paragraph(title)
        if (char == '-' and delimiter != '--') or char == '.':
            self.out.code(content)
            return
        self.run(content, False)

    def _table(self, char: str, content: list[str], attributes: str, title: str) -> None:
        found = _FORMAT.search(attributes)
        table_format = found.group(1) if found else {'|': 'psv', ',': 'csv', ':': 'dsv'}[char]
        implicit = (content[0] if content else '') != '' and (content[1] if len(content) > 1 else None) == ''
        header = bool(_HEADER.search(attributes)) or (implicit and not _NO_HEADER.search(attributes))

        if table_format == 'psv':
            rows = _psv_rows(content, attributes)
        else:
            separator = {'csv': ',', 'dsv': ':', 'tsv': '\t'}[table_format]
            rows = [
                delimited(line, ',') if table_format == 'csv' else [text.trim(cell) for cell in line.split(separator)]
                for line in content
                if line.strip(' \t') != ''
            ]

        rows = [[self._inline(cell) for cell in row] for row in rows]
        self.out.table(self._inline(title), rows[:1] if header else [], rows[1:] if header else rows)


def _link(match: re.Match[str]) -> str:
    url = match.group(1) if match.group(1) else match.group(2)
    label = text.trim(match.group(3))

    return f'{label} ({url})' if label != '' else url


def _closing(lines: list[str], i: int) -> int:
    """The index of the line closing the delimited block opened at `i`: the next line equal to it, or the line count."""
    for j in range(i + 1, len(lines)):
        if lines[j] == lines[i]:
            return j

    return len(lines)


def _psv_rows(content: list[str], attributes: str) -> list[list[str]]:
    first = next((line for line in content if line.strip(' \t') != ''), '')
    columns = _columns(attributes)
    size = max(1, columns if columns is not None else len(_cells(first)))
    cells = _cells('\n'.join(content))

    return [cells[k : k + size] for k in range(0, len(cells), size)]


def _cells(source: str) -> list[str]:
    """
    The cells of PSV text: each unescaped `|` opens a cell, and a cell spec just before it
    (after whitespace or at the start) applies to that cell; text before the first `|` is dropped.
    """
    pieces = _CELL_BAR.split(source)
    last = len(pieces) - 1
    spec = text.trim(pieces[0])
    spec = spec if _CELL_SPEC.match(spec) else ''
    cells: list[str] = []
    for k in range(1, last + 1):
        cell, following = _cell_spec(pieces[k]) if k < last else (pieces[k], '')
        cells.extend(_expand(cell.replace('\\|', '|'), spec))
        spec = following

    return cells


def _cell_spec(source: str) -> tuple[str, str]:
    """Splits a cell spec (`2+`, `3*`, `a`, `^.^`...) off the end of a cell's text."""
    match = _CELL_SPEC_END.search(source)
    if match and match.group(1) != '':
        return source[: -len(match.group(1))], match.group(1)

    return source, ''


def _expand(source: str, spec: str) -> list[str]:
    """A cell repeated by a `n*` spec, each copy followed by empty cells for the columns a `n+` spec spans."""
    match = _CELL_SPEC.match(spec)
    copies = max(1, int(match.group(1))) if match and match.group(1) else 1
    span = max(1, int(match.group(2))) if match and match.group(2) else 1
    cell = [text.squash(source), *([''] * (span - 1))]

    return cell * copies


def _columns(attributes: str) -> int | None:
    """
    The column count a `cols` attribute gives: a number, or a list of specs each counting one
    column or its `n*` multiplier.
    """
    match = _COLS.search(attributes)
    if match is None:
        return None
    value = text.trim(match.group(1) if match.group(1) else (match.group(2) or ''))
    if _DIGITS.match(value):
        return max(1, int(value))
    count = 0
    for entry in _SEPARATORS.split(value):
        multiplier = _MULTIPLIER.match(entry)
        count += int(multiplier.group(1)) if multiplier else 1

    return max(1, count)


def _style(attributes: str) -> str:
    """The block style: the first positional attribute, up to a `#`, `.`, `%` shorthand or comma."""
    match = _STYLE.match(attributes)

    return match.group(1).lower() if match else ''


def _kind(line: str) -> str | None:
    """The kind of list a line is an item of: unordered, ordered, callout or description; or None."""
    match = _LIST_ITEM.match(line)
    if match:
        marker = match.group(1)
        if marker[0] == '*' or marker == '-':
            return 'unordered'
        return 'callout' if marker[0] == '<' else 'ordered'

    return 'description' if _DESCRIPTION.match(line) else None


def _item_text(line: str) -> str:
    """An item's marker, one space and the rest of its line."""
    match = _LIST_ITEM.match(line)
    marker = match.group(0) if match else ''

    return text.trim(text.trim(marker) + ' ' + line[len(marker) :])


def _description(line: str) -> str:
    match = _DESCRIPTION.match(line)
    definition = text.trim((match.group(2) if match else None) or '')
    term = match.group(1) if match else line

    return text.trim(term) + ':' + (f' {definition}' if definition != '' else '')


def _starts_with_word(title: str) -> bool:
    return title != '' and (title[0] == '_' or unicodedata.category(title[0])[0] in 'LN')
