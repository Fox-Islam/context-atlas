"""
Collects the blocks of a line-based markup reader (reST, AsciiDoc, Org): an open paragraph or
list gathers lines until it is closed, and headings, code and tables close it first. The shared
rules are stated in spec/formats/rst.md under "Output".
"""
from __future__ import annotations

from .. import text
from ..blocks import Block, BlockType


class BlockBuilder:
    def __init__(self) -> None:
        self._blocks: list[Block] = []
        self._lines: list[str] = []
        self._list = False

    def text(self, line: str) -> None:
        """Adds a paragraph line, closing an open list first."""
        if self._list:
            self.close()
        self._lines.append(text.trim(line))

    def item(self, line: str) -> None:
        """Adds a list item, closing an open paragraph first."""
        if not self._list:
            self.close()
        self._list = True
        self._lines.append(text.trim(line))

    def extend(self, line: str) -> None:
        """Continues the last line or item, joined with a space."""
        line = text.trim(line)
        if not self._lines:
            self._lines.append(line)
            return
        last = self._lines.pop()
        self._lines.append(line if last == '' else (last if line == '' else f'{last} {line}'))

    def is_open(self) -> bool:
        return bool(self._lines)

    def is_empty(self) -> bool:
        """Whether nothing has been emitted and no line is open."""
        return not self._blocks and not self._lines

    def in_list(self) -> bool:
        return self._list and bool(self._lines)

    def count(self) -> int:
        return len(self._lines)

    def last(self) -> str | None:
        return self._lines[-1] if self._lines else None

    def replace_last(self, line: str) -> None:
        """Replaces the last line or item; an empty replacement removes it."""
        if self._lines:
            self._lines.pop()
        if line != '':
            self._lines.append(line)

    def paragraph(self, source: str) -> None:
        self.close()
        source = text.trim(source)
        if source != '':
            self._blocks.append(Block.paragraph(source))

    def heading(self, source: str, level: int) -> None:
        self.close()
        source = text.trim(source)
        if source != '':
            self._blocks.append(Block.heading(source, level))

    def code(self, lines: list[str]) -> None:
        self.close()
        lines = list(lines)
        while lines and text.trim(lines[0]) == '':
            lines.pop(0)
        while lines and text.trim(lines[-1]) == '':
            lines.pop()
        if lines:
            self._blocks.append(Block(BlockType.CODE, '\n'.join(lines)))

    def table(self, caption: str, header_rows: list[list[str]], rows: list[list[str]]) -> None:
        """Header rows are merged column by column into one header."""
        self.close()
        caption = text.squash(caption)
        header = _merge(header_rows)
        squashed = [[text.squash(cell) for cell in row] for row in rows]
        kept = [row for row in squashed if ''.join(row) != '']
        if not header and not kept:
            return

        lines = [f'Table: {caption}'] if caption != '' else []
        if header:
            lines.append(' | '.join(header))
        lines.extend(' | '.join(row) for row in kept)
        meta = {'caption': caption, 'header': header, 'rows': kept}
        self._blocks.append(Block(BlockType.TABLE, '\n'.join(line.rstrip(' ') for line in lines), None, meta))

    def close(self) -> None:
        lines = [line for line in self._lines if line != '']
        if lines:
            self._blocks.append(Block.paragraph(('\n' if self._list else ' ').join(lines)))
        self._lines = []
        self._list = False

    def blocks(self) -> list[Block]:
        self.close()

        return self._blocks


def dedent(lines: list[str]) -> list[str]:
    """Lines with the indentation they all share removed; blank lines do not count."""
    widths = [_leading(line) for line in lines if line.strip(' \t') != '']
    least = min(widths) if widths else 0

    return [line[min(least, _leading(line)) :] for line in lines]


def delimited(line: str, separator: str) -> list[str]:
    """
    Splits one line of delimited values: a field opening with `"` (after spaces) runs to the
    next `"` not doubled, `""` inside it standing for `"`; other fields are trimmed.
    """
    fields = []
    length = len(line)
    i = 0
    while True:
        while i < length and line[i] in ' \t' and line[i] != separator:
            i += 1
        if i < length and line[i] == '"':
            field = ''
            i += 1
            while i < length:
                if line[i] == '"' and line[i + 1 : i + 2] == '"':
                    field += '"'
                    i += 2
                    continue
                if line[i] == '"':
                    i += 1
                    break
                field += line[i]
                i += 1
            end = line.find(separator, i)
            i = length if end == -1 else end
        else:
            end = line.find(separator, i)
            field = text.trim(line[i : length if end == -1 else end])
            i = length if end == -1 else end
        fields.append(field)
        if i >= length:
            return fields
        i += len(separator)


def _merge(rows: list[list[str]]) -> list[str]:
    header: dict[int, str] = {}
    for row in rows:
        for c, cell in enumerate(row):
            cell = text.squash(cell)
            before = header.get(c, '')
            header[c] = cell if before == '' else (before if cell == '' else f'{before} {cell}')

    return list(header.values())


def _leading(line: str) -> int:
    return len(line) - len(line.lstrip(' \t'))
