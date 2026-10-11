"""
Reads the block structure of Org: headings with TODO keywords, priorities, tags and cookies
removed, paragraphs, lists, source and example blocks, fixed-width lines and tables. Keywords,
comments, drawers and planning lines are dropped. Inline markup is left in the text. The rules
are stated in spec/formats/org.md.
"""
from __future__ import annotations

import re

from .. import text
from ..blocks import Block
from .block_builder import BlockBuilder, dedent

# The PHP patterns have no `u` flag except TAGS: `\d` and case folding are ASCII.
_HEADING = re.compile(r'^(\*+)[ \t]+(.*)$')
_BLOCK = re.compile(r'^[ \t]*#\+begin_([^ \t]+)(?:[ \t].*)?$', re.IGNORECASE | re.ASCII)
_KEYWORD = re.compile(r'^[ \t]*#\+([^ \t]+?):(?:[ \t]+(.*))?$')
_COMMENT = re.compile(r'^[ \t]*#(?:[ \t]|$)')
_DRAWER = re.compile(r'^[ \t]*:[A-Za-z0-9_-]+:$')
_DRAWER_END = re.compile(r'^[ \t]*:end:$', re.IGNORECASE | re.ASCII)
_PLANNING = re.compile(r'^[ \t]*(?:(?:SCHEDULED|DEADLINE|CLOSED):[ \t]*[<\[][^>\]]*[>\]][ \t]*)+$')
_FIXED_WIDTH = re.compile(r'^[ \t]*:(?: |$)')
_TABLE = re.compile(r'^[ \t]*\|')
_RULE = re.compile(r'^[ \t]*-{5,}$')
_ITEM = re.compile(r'^([ \t]*)(?:([-+])|(\d+[.)])|(?<=[ \t])(\*))(?:[ \t]+|$)', re.ASCII)
_FOOTNOTE = re.compile(r'^\[fn:[^\]]+\]')
# PCRE's `[\p{L}\p{N}_]` is Python's `\w`.
_TAGS = re.compile(r'[ \t]+:(?:[\w@#%]+:)+$')
_COOKIE = re.compile(r'[ \t]*\[\d*(?:/\d*|%)\]', re.ASCII)
_TODO = re.compile(r'^#\+(?:seq_|typ_)?todo:[ \t]*(.*)$', re.IGNORECASE | re.ASCII)
_TITLE = re.compile(r'^#\+title:[ \t]*[^ \t]', re.IGNORECASE | re.ASCII)
_SPACES = re.compile(r'[ \t]+')
_FAST_ACCESS = re.compile(r'\(.*\)$')
_PRIORITY = re.compile(r'^\[#[A-Za-z0-9]\](?:[ \t]+|$)')
_COMMENT_HEADING = re.compile(r'^COMMENT(?:[ \t]|$)')
_ESCAPED = re.compile(r'^([ \t]*),(\*|#\+)')
_FIXED_PREFIX = re.compile(r'^[ \t]*: ?')
_WIDTH_COOKIE = re.compile(r'^<[lrc]?\d*>$', re.ASCII)


def parse(source: str) -> list[Block]:
    lines = [line.rstrip(' \t') for line in source.replace('\r\n', '\n').replace('\r', '\n').split('\n')]
    reader = _Reader()
    for line in lines:
        todo = _TODO.match(line)
        if todo:
            for word in _SPACES.split(todo.group(1)):
                word = _FAST_ACCESS.sub('', word, count=1)
                if word not in ('', '|'):
                    reader.keywords.append(word)
        if reader.shift == 0 and _TITLE.match(line):
            reader.shift = 1
    reader.run(lines)

    return reader.out.blocks()


class _Reader:
    def __init__(self) -> None:
        self.out = BlockBuilder()
        self.keywords = ['TODO', 'DONE']
        self.shift = 0
        self.titled = False
        self.caption = ''

    def run(self, lines: list[str]) -> None:
        n = len(lines)
        list_base = 0
        list_kind = ''
        self.caption = ''
        i = 0

        while i < n:
            line = lines[i]

            if line == '':
                if not self.out.in_list():
                    self.out.close()
                i += 1
                continue

            indent = len(line) - len(line.lstrip(' \t'))
            item = _item(line)
            continues = item is not None and (indent > list_base or (indent == list_base and item == list_kind))
            if self.out.in_list() and ((not continues) if item is not None else indent <= list_base):
                self.out.close()

            following = self._element(lines, i)
            if following is not None:
                i = following + 1
                continue
            self.caption = ''

            if item is not None:
                if not self.out.in_list():
                    list_base = indent
                    list_kind = item
                self.out.item(_item_text(line))
            elif self.out.in_list():
                self.out.extend(line)
            else:
                if _FOOTNOTE.match(line):
                    self.out.close()
                self.out.text(line)
            i += 1

        self.out.close()

    def _element(self, lines: list[str], i: int) -> int | None:
        """
        Reads an element other than paragraph text or a list item: a heading, block, keyword,
        comment, drawer, planning line, fixed-width area, table or rule. Returns the last line it
        used, or None.
        """
        line = lines[i]

        match = _HEADING.match(line)
        if match:
            self.caption = ''
            return self._heading(lines, i, len(match.group(1)), match.group(2))
        match = _BLOCK.match(line)
        if match:
            end = _block_end(lines, i, match.group(1))
            self.out.close()
            if end is not None:
                self._block(match.group(1).lower(), lines[i + 1 : end])
            return end if end is not None else i
        match = _KEYWORD.match(line)
        if match:
            self._keyword(match.group(1).lower(), text.trim(match.group(2) or ''))
            return i
        if _COMMENT.match(line):
            return i
        if _DRAWER.match(line):
            j = i + 1
            while j < len(lines) and not _HEADING.match(lines[j]):
                if _DRAWER_END.match(lines[j]):
                    return j
                j += 1
        if _PLANNING.match(line):
            return i
        if _FIXED_WIDTH.match(line):
            return self._fixed_width(lines, i)
        if _TABLE.match(line):
            end = self._table(lines, i)
            self.caption = ''
            return end
        if _RULE.match(line):
            self.out.close()
            return i

        return None

    def _heading(self, lines: list[str], i: int, level: int, title: str) -> int:
        words = '|'.join(re.escape(word) for word in self.keywords)
        title = re.sub(f'^(?:{words})(?:[ \\t]+|$)', '', title, count=1)
        title = _PRIORITY.sub('', title, count=1)

        if _COMMENT_HEADING.match(title):
            self.out.close()
            for j in range(i + 1, len(lines)):
                match = _HEADING.match(lines[j])
                if match and len(match.group(1)) <= level:
                    return j - 1
            return len(lines) - 1

        title = _TAGS.sub('', title, count=1)
        self.out.heading(text.squash(_COOKIE.sub('', title)), level + self.shift)

        return i

    def _keyword(self, name: str, value: str) -> None:
        if name == 'title' and not self.titled and value != '':
            self.titled = True
            self.out.heading(value, 1)
        elif name == 'caption':
            self.caption = value
        if name != 'caption' and not name.startswith('attr_') and name != 'name':
            self.out.close()

    def _block(self, name: str, content: list[str]) -> None:
        self.out.close()
        if name in ('src', 'example'):
            self.out.code([_ESCAPED.sub(r'\1\2', line, count=1) for line in dedent(content)])
        elif name not in ('export', 'comment'):
            caption = self.caption
            self.run(dedent(content))
            self.caption = caption

    def _fixed_width(self, lines: list[str], i: int) -> int:
        code = []
        j = i
        while j < len(lines) and _FIXED_WIDTH.match(lines[j]):
            code.append(_FIXED_PREFIX.sub('', lines[j], count=1))
            j += 1
        self.out.code(code)

        return j - 1

    def _table(self, lines: list[str], i: int) -> int:
        rows: list[list[str]] = []
        header_rows: int | None = None
        j = i
        while j < len(lines) and _TABLE.match(lines[j]):
            line = text.trim(lines[j])
            j += 1
            if line.startswith('|-'):
                if header_rows is None:
                    header_rows = len(rows) if rows else None
                continue
            inner = line[1:-1] if line.endswith('|') and len(line.encode()) > 1 else line[1:]
            cells = [text.trim(cell) for cell in inner.split('|')]
            if not _cookies(cells):
                rows.append(cells)
        if header_rows is None or header_rows == len(rows):
            header_rows = 0

        self.out.table(self.caption, rows[:header_rows], rows[header_rows:])

        return j - 1


def _block_end(lines: list[str], i: int, name: str) -> int | None:
    end = re.compile(r'^[ \t]*#\+end_' + re.escape(name) + '$', re.IGNORECASE | re.ASCII)
    for j in range(i + 1, len(lines)):
        if end.match(lines[j]):
            return j

    return None


def _cookies(cells: list[str]) -> bool:
    """Whether a row holds only column-width cookies (`<10>`, `<l>`, `<r5>`) and empty cells."""
    if any(cell != '' and not _WIDTH_COOKIE.match(cell) for cell in cells):
        return False

    return ''.join(cells) != ''


def _item_text(line: str) -> str:
    """An item's marker, one space and the rest of its line."""
    match = _ITEM.match(line)
    marker = match.group(0) if match else ''

    return text.trim(text.trim(marker) + ' ' + line[len(marker) :])


def _item(line: str) -> str | None:
    """The kind of list item a line opens, "unordered" or "ordered", or None."""
    match = _ITEM.match(line)
    if match is None or _RULE.match(line):
        return None

    return 'ordered' if match.group(3) else 'unordered'
