"""
Reads the block structure of reStructuredText: section titles, paragraphs, lists, field and
definition lists, literal blocks, directives, and simple, grid, list and CSV tables. Inline
markup is left in the text. The rules are stated in spec/formats/rst.md.
"""
from __future__ import annotations

import math
import re

from .. import compat, text
from ..blocks import Block
from ..text import WHITESPACE
from .block_builder import BlockBuilder, dedent, delimited

# The PHP patterns have no `u` flag except BULLET: `\d` and classes are ASCII.
_ADORNMENT = re.compile(r'^([!-/:-@\[-`{-~])\1*$')
_EXPLICIT = re.compile(r'^ *\.\.(?: |$)')
_DIRECTIVE = re.compile(r'^ *\.\. +([A-Za-z0-9]+(?:[-_.:+][A-Za-z0-9]+)*)::(?: +(.*))?$')
_FOOTNOTE = re.compile(r'^ *\.\. +\[([^\]]+)\](?: +(.*))?$')
_BULLET = re.compile('^ *[-*+\u2022\u2023\u2043](?: +|$)')
_ENUMERATOR = re.compile(
    r'^ *(?:\(?(?:\d+|#|[A-Za-z]|[ivxlcdmIVXLCDM]+)[.)]|\((?:\d+|#|[A-Za-z]|[ivxlcdmIVXLCDM]+)\))(?: +|$)', re.ASCII
)
_FIELD = re.compile(r'^ *:(?:[^: ]|[^: ][^:]*[^: ]):(?: +|$)')
_LINE_BLOCK = re.compile(r'^( *)\|(?: +|$)')
_OPTION = re.compile(r'^ +:([^: ]|[^: ][^:]*[^: ]):(?: +(.*))?$')
_GRID_BORDER = re.compile(r'^\+(?:-+\+)+$')
_GRID_SEPARATOR = re.compile(r'^\+(?:[-=]+\+)+$')
_SIMPLE_BORDER = re.compile(r'^=+(?: +=+)+$')
_ANONYMOUS = re.compile(r'^__(?: |$)')
_DOCTEST = re.compile(r'^>>>(?: |$)')
_SPACED_MARKER = re.compile(f'[{WHITESPACE}]::$')
_RUNS = re.compile(r'=+')
_DASHES = re.compile(r'^[- ]*$')
_LINE_BLOCK_START = re.compile(r'^\| +')
_PARTIAL_SEPARATOR = re.compile(r'^[-=+]+$')
_ROW_BULLET = re.compile(r'^[-*+](?: +|$)')
_CELL_BULLET = re.compile(r'^ *[-*+](?: +|$)(.*)$')

CODE = ('code', 'code-block', 'sourcecode', 'parsed-literal', 'math', 'doctest', 'testcode', 'testoutput')

ADMONITIONS = ('attention', 'caution', 'danger', 'error', 'hint', 'important', 'note', 'tip', 'warning', 'seealso')

TITLED = ('admonition', 'rubric', 'topic', 'sidebar')

DROPPED = (
    'toctree', 'index', 'image', 'include', 'literalinclude', 'raw', 'contents', 'highlight', 'sectnum', 'header',
    'footer', 'meta', 'title', 'default-role', 'role', 'class', 'target-notes', 'module', 'currentmodule',
    'testsetup', 'testcleanup',
)

SIGNATURES = (
    'function', 'class', 'method', 'staticmethod', 'classmethod', 'attribute', 'property', 'data', 'exception',
    'decorator', 'describe', 'object', 'envvar', 'option', 'confval',
)


def parse(source: str) -> list[Block]:
    lines = [_expand_tabs(line).rstrip(' \t') for line in source.replace('\r\n', '\n').replace('\r', '\n').split('\n')]
    reader = _Reader()
    reader.run(lines)

    return reader.out.blocks()


class _Reader:
    def __init__(self) -> None:
        self.out = BlockBuilder()
        # Adornment styles (the character, then "o" with an overline or "u") in order of first appearance.
        self.styles: list[str] = []
        self.caption = ''

    def run(self, lines: list[str]) -> None:
        n = len(lines)
        blank = True
        literal: int | None = None
        list_base = 0
        list_kind = ''
        para_indent = 0
        i = 0

        while i < n:
            line = lines[i]

            if line == '':
                if self._ends_with_literal_marker():
                    literal = para_indent
                if not self.out.in_list():
                    self.out.close()
                blank = True
                i += 1
                continue

            indent = _spaces(line)

            if literal is not None and indent > literal:
                i = self._literal(lines, i, literal)
                literal = None
                continue
            literal = None

            term_allowed = (
                blank or not self.out.is_open() or (self.out.in_list() and list_kind == 'term' and indent == list_base)
            )
            item = self._item(line)
            if item is None and term_allowed and self._is_term(lines, i):
                item = (line, 0, 'term')
            continues = item is not None and (indent > list_base or (indent == list_base and item[2] == list_kind))
            if self.out.in_list() and (
                (item is not None and not continues) or (item is None and blank and indent <= list_base)
            ):
                self.out.close()

            start = blank or not self.out.is_open()
            following = self._block(lines, i, indent) if start else None
            if following is not None:
                i = following
                blank = True
                continue

            if item is not None and (start or self.out.in_list()):
                if not self.out.in_list():
                    list_base = indent
                    list_kind = item[2]
                if item[2] == 'term':
                    self.out.item(line if line.endswith(':') else f'{line}:')
                    i += 1
                    self.out.extend(lines[i])
                    para_indent = _spaces(lines[i])
                else:
                    self.out.item(item[0])
                    para_indent = item[1]
            elif self.out.in_list():
                if blank:
                    para_indent = indent
                self.out.extend(line)
            else:
                if not self.out.is_open():
                    para_indent = indent
                self.out.text(line)

            blank = False
            i += 1

        self._ends_with_literal_marker()
        self.out.close()

    def _block(self, lines: list[str], i: int, indent: int) -> int | None:
        """
        Reads a construct that only opens a block: a section title, transition, explicit markup,
        anonymous target, table or doctest block. Returns the next line to read, or None.
        """
        line = lines[i]

        if indent == 0:
            title = self._title(lines, i)
            if title is not None:
                return title
            if _ADORNMENT.match(line) and len(line) >= 4:
                self.out.close()
                return i + 1

        body = line[indent:]
        if _EXPLICIT.match(line):
            return self._explicit(lines, i, indent)
        if _ANONYMOUS.match(body):
            return _extent_end(lines, i, indent)
        if _GRID_BORDER.match(body):
            return self._grid(lines, i, indent)
        if _SIMPLE_BORDER.match(body):
            return self._simple(lines, i, indent)
        if _DOCTEST.match(body):
            return self._doctest(lines, i)

        return None

    def _title(self, lines: list[str], i: int) -> int | None:
        line = lines[i]
        over = _ADORNMENT.match(line) is not None
        title = (lines[i + 1] if i + 1 < len(lines) else '').strip(' ')

        closed = (lines[i + 2] if i + 2 < len(lines) else None) == line
        if over and title != '' and closed and len(title) <= len(line):
            self.out.heading(title, self._level(line[0] + 'o'))
            return i + 3

        under = lines[i + 1] if i + 1 < len(lines) else ''
        if not over and _ADORNMENT.match(under) and (len(under) >= len(line) or len(under) >= 4):
            self.out.heading(line, self._level(under[0] + 'u'))
            return i + 2

        return None

    def _level(self, style: str) -> int:
        if style not in self.styles:
            self.styles.append(style)
            return len(self.styles)

        return self.styles.index(style) + 1

    @staticmethod
    def _item(line: str) -> tuple[str, int, str] | None:
        """
        A list item's text (its marker, one space and the rest of the line), the column its
        content starts at and its kind, or None.
        """
        for kind, pattern in (('bullet', _BULLET), ('enumerated', _ENUMERATOR), ('field', _FIELD)):
            match = pattern.match(line)
            if match:
                marker = match.group(0)
                named = f'bullet {text.trim(marker)}' if kind == 'bullet' else kind
                column = len(marker) + (0 if marker.endswith(' ') else 1)
                return text.trim(text.trim(marker) + ' ' + line[len(marker) :]), column, named
        match = _LINE_BLOCK.match(line)
        if match:
            return line[len(match.group(0)) :], len(match.group(1)), 'line'

        return None

    def _is_term(self, lines: list[str], i: int) -> bool:
        """
        A line opening a definition: not a list item or explicit markup, and followed directly by
        a more indented line.
        """
        following = lines[i + 1] if i + 1 < len(lines) else ''

        return (
            following != ''
            and _spaces(following) > _spaces(lines[i])
            and self._item(lines[i]) is None
            and not _EXPLICIT.match(lines[i])
        )

    def _ends_with_literal_marker(self) -> bool:
        """Rewrites a trailing `::` on the open text and returns whether there was one."""
        last = self.out.last()
        if last is None or not last.endswith('::'):
            return False

        if last == '::':
            self.out.replace_last('')
        elif _SPACED_MARKER.search(last):
            self.out.replace_last(last[:-2].rstrip(' \t\n\r\x00\x0b'))
        else:
            self.out.replace_last(last[:-1])

        return True

    def _literal(self, lines: list[str], i: int, indent: int) -> int:
        end = _extent_end(lines, i - 1, indent)
        self.out.code(dedent(lines[i:end]))

        return end

    def _doctest(self, lines: list[str], i: int) -> int:
        end = i
        while end < len(lines) and lines[end] != '':
            end += 1
        self.out.code(dedent(lines[i:end]))

        return end

    def _explicit(self, lines: list[str], i: int, indent: int) -> int:
        line = lines[i]

        if line.strip(' ') == '..' and (lines[i + 1] if i + 1 < len(lines) else '') == '':
            return i + 1

        end = _extent_end(lines, i, indent)
        body = lines[i + 1 : end]

        directive = _DIRECTIVE.match(line)
        if directive:
            self._directive(directive.group(1).lower(), (directive.group(2) or '').strip(' '), body)
        else:
            footnote = _FOOTNOTE.match(line)
            if footnote:
                self._nested([f'[{footnote.group(1)}] {footnote.group(2) or ""}', *dedent(body)])

        return end

    def _directive(self, name: str, argument: str, body: list[str]) -> None:
        options: dict[str, str] = {}
        option_indent: int | None = None
        k = 0
        while k < len(body):
            option = _OPTION.match(body[k])
            if option:
                option_indent = _spaces(body[k])
                options[option.group(1).lower()] = option.group(2) or ''
            elif option_indent is None or body[k] == '' or _spaces(body[k]) <= option_indent:
                break
            k += 1
        content = dedent(body[k:])
        kind = name[name.rindex(':') + 1 :] if ':' in name else name

        if name in CODE:
            self.out.code(content)
        elif name in ADMONITIONS:
            self._nested([argument, *content])
        elif name in TITLED or kind in SIGNATURES:
            self.out.paragraph(argument)
            self._nested(content)
        elif name == 'table':
            self.caption = argument
            self._nested(content)
            self.caption = ''
        elif name == 'list-table':
            self._table(_list_table(content), _php_int(options.get('header-rows', '0')), argument)
        elif name == 'csv-table':
            self._csv_table(argument, options, content)
        elif name not in DROPPED:
            self._nested(content)

    def _nested(self, lines: list[str]) -> None:
        self.out.close()
        self.run(lines)

    def _table(self, rows: list[list[str]], header_rows: int, caption: str = '') -> None:
        self.out.table(caption if caption != '' else self.caption, rows[:header_rows], rows[header_rows:])

    def _grid(self, lines: list[str], i: int, indent: int) -> int:
        border = lines[i][indent:]
        bounds = [c for c, char in enumerate(border) if char == '+']

        rows: list[list[str]] = []
        cells: list[str] = []
        header_rows = 0
        end = i + 1
        while end < len(lines):
            line = lines[end]
            if _spaces(line) != indent or line[indent : indent + 1] not in ('+', '|'):
                break
            line = line[indent:]
            if _GRID_SEPARATOR.match(line):
                if cells:
                    rows.append(cells)
                cells = []
                if '=' in line:
                    header_rows = len(rows)
                end += 1
                continue
            cells = _grid_line(line, bounds, cells)
            end += 1
        if cells:
            rows.append(cells)

        self._table(rows, header_rows)

        return end

    def _simple(self, lines: list[str], i: int, indent: int) -> int | None:
        end = i + 1
        while end < len(lines) and lines[end] != '':
            end += 1
        borders = [
            j for j in range(i + 1, end) if _spaces(lines[j]) == indent and _SIMPLE_BORDER.match(lines[j][indent:])
        ]
        if not borders or borders[-1] != end - 1:
            return None

        starts = [run.start() for run in _RUNS.finditer(lines[i][indent:])]
        rows: list[list[str]] = []
        header_rows: int | None = None if len(borders) > 1 else 0
        for j in range(i + 1, end - 1):
            line = lines[j][indent:]
            if j in borders:
                if header_rows is None:
                    header_rows = len(rows)
                continue
            if _DASHES.match(line):
                continue
            cells = [
                (line[start : starts[c + 1]] if c + 1 < len(starts) else line[start:]).strip(' ')
                for c, start in enumerate(starts)
            ]
            if cells[0] == '' and rows:
                previous = rows.pop()
                rows.append([f'{before} {cell}'.strip(' ') for before, cell in zip(previous, cells, strict=True)])
            else:
                rows.append(cells)

        self._table(rows, header_rows or 0)

        return end

    def _csv_table(self, caption: str, options: dict[str, str], content: list[str]) -> None:
        delim = options.get('delim', ',')
        if delim == 'tab':
            delim = '\t'
        elif delim == 'space':
            delim = ' '
        elif len(delim.encode()) != 1:
            delim = ','
        rows = [delimited(options['header'], ',')] if 'header' in options else []
        rows.extend(delimited(line, delim) for line in content if line.strip(' ') != '')

        extra = _php_int(options.get('header-rows', '0'))
        header_rows = 1 + extra if rows and 'header' in options else extra
        self._table(rows, header_rows, caption)


def _grid_line(line: str, bounds: list[int], cells: list[str]) -> list[str]:
    """
    Adds one line of a grid row to its cells. The line is cut at the border's `+` columns where
    it has a `|` or `+`, so text spanning columns lands in the first of them. A piece of only
    `-`, `=` and `+` is a partial row separator and is skipped; a line-block `| ` opening a
    piece is removed.
    """
    found = dict(enumerate(cells))
    last = len(bounds) - 1
    origin = 0
    for b in range(1, last + 1):
        char = line[bounds[b] : bounds[b] + 1]
        if b < last and char not in ('|', '+'):
            continue
        piece = line[bounds[origin] + 1 : bounds[b]].strip(' ')
        segment = _LINE_BLOCK_START.sub('', piece, count=1)
        found.setdefault(origin, '')
        if not _PARTIAL_SEPARATOR.match(segment):
            found[origin] = f'{found[origin]} {segment}'.strip(' ')
        for c in range(origin + 1, b):
            found.setdefault(c, '')
        origin = b

    return [found[c] for c in sorted(found)]


def _list_table(content: list[str]) -> list[list[str]]:
    """
    The rows of a list-table: each top-level bullet is a row, and each bullet at the column of
    the first inner bullet a cell; other lines continue the cell before them.
    """
    rows: list[list[str]] = []
    cell_indent: int | None = None
    for line in content:
        indent = _spaces(line)
        if indent == 0:
            row = _ROW_BULLET.match(line)
            if row:
                rows.append([])
                width = len(row.group(0))
                line = ' ' * width + line[width:]
                indent = width
        if not rows or line.strip(' ') == '':
            continue
        cell = _CELL_BULLET.match(line)
        if cell and (indent if cell_indent is None else cell_indent) == indent:
            cell_indent = indent
            rows[-1].append(cell.group(1))
        elif rows[-1]:
            rows[-1].append(f'{rows[-1].pop()} {line.strip(" ")}'.strip(' '))

    return rows


def _extent_end(lines: list[str], i: int, indent: int) -> int:
    """
    The line after the block that line `i` opens: the lines after it that are blank or indented
    more than `indent`, without trailing blank lines.
    """
    end = i + 1
    for j in range(i + 1, len(lines)):
        if lines[j] == '':
            continue
        if _spaces(lines[j]) <= indent:
            break
        end = j + 1

    return end


def _spaces(line: str) -> int:
    return len(line) - len(line.lstrip(' '))


def _php_int(value: str) -> int:
    """PHP's `(int)` of an option value: its leading number, or 0."""
    number = compat.to_float(value)

    return int(number) if math.isfinite(number) else 0


def _expand_tabs(line: str) -> str:
    if '\t' not in line:
        return line
    pieces = line.split('\t')
    out = pieces[0]
    for piece in pieces[1:]:
        out += ' ' * (8 - len(out) % 8) + piece

    return out
