"""
Reads LaTeX source: the body of the document environment, sectioning commands as headings,
verbatim and display maths as code, tabulars as tables and lists as list paragraphs. Inline
commands are unwrapped by `latex_inline`. The rules are stated in spec/formats/latex.md.
"""
from __future__ import annotations

import re
from typing import Any

from .. import text
from ..blocks import Block, BlockType
from . import latex_inline, latex_table

_VERBATIM_BEGIN = re.compile(r'\\begin\{(verbatim|Verbatim|lstlisting|minted)(\*?)\}')
_VERB = re.compile(r'\\[vV]erb\*?([^A-Za-z \t\n*])')
_TOKEN = re.compile(
    r'(?<!\\)\\(?:[vV]erb\*?(?P<delimiter>[^A-Za-z \t\n*]).*?(?P=delimiter)|(begin|end)\{([^{}]*)\}'
    r'|(part|chapter|section|subsection|subsubsection|paragraph|subparagraph)(?![A-Za-z])'
    r'|(item|bibitem|caption|title|maketitle|par)(?![A-Za-z])|(\[))|(\n[ \t]*\n)|\x00([0-9]+)\x00'
)
_TITLE = re.compile(r'(?<!\\)\\title(?![A-Za-z])')
_LABEL = re.compile(r'\\label\{[^{}]*\}')

SECTIONS = {
    'part': 0, 'chapter': 1, 'section': 2, 'subsection': 3, 'subsubsection': 4, 'paragraph': 5, 'subparagraph': 6,
}

MATH = frozenset({
    'equation', 'equation*', 'align', 'align*', 'gather', 'gather*', 'multline', 'multline*', 'flalign', 'flalign*',
    'alignat', 'alignat*', 'eqnarray', 'eqnarray*', 'displaymath', 'math',
})

LISTS = frozenset({
    'itemize', 'itemize*', 'enumerate', 'enumerate*', 'description', 'description*', 'thebibliography',
})

# Each tabular environment and its number of required arguments.
TABULARS = {'tabular': 1, 'tabular*': 2, 'tabularx': 2, 'tabulary': 2, 'longtable': 1}

FLOATS = {'table': 'table', 'table*': 'table', 'figure': 'figure', 'figure*': 'figure'}

DROPPED = frozenset({'comment', 'tikzpicture', 'pgfpicture', 'picture', 'filecontents', 'filecontents*'})

BEGIN_DOCUMENT = '\\begin{document}'

END_DOCUMENT = '\\end{document}'


def parse(source: str) -> list[Block]:
    return _Reader().read(source)


class _Reader:
    def __init__(self) -> None:
        self.events: list[dict[str, Any]] = []
        self.codes: list[str] = []
        self.paragraph = ''
        self.frames: list[dict[str, Any]] = []
        self.list_lines: list[str] = []
        self.item: str | None = None
        self.marker = ''
        self.label: str | None = None
        self.description = False
        self.indent = ''
        self.maketitle = False

    def read(self, source: str) -> list[Block]:
        source = source.replace('\r\n', '\n').replace('\r', '\n').replace('\x00', '')
        clean = self._clean(source.removeprefix('\ufeff'))
        body = clean
        begin = clean.find(BEGIN_DOCUMENT)
        if begin != -1:
            body = clean[begin + len(BEGIN_DOCUMENT) :]
            end = body.find(END_DOCUMENT)
            body = body if end == -1 else body[:end]

        self._walk(body)

        return self._blocks(_title(clean))

    def _clean(self, source: str) -> str:
        """Removes comments and lifts verbatim environments out as numbered placeholder lines."""
        out: list[str] = []
        code: list[str] = []
        verbatim: str | None = None

        for line in source.split('\n'):
            rest = line
            fresh = True

            while True:
                if verbatim is not None:
                    close = '\\end{' + verbatim + '}'
                    end = rest.find(close)
                    if end == -1:
                        code.append(rest)
                        break
                    if text.trim(rest[:end]) != '':
                        code.append(rest[:end])
                    out.append(f'\x00{len(self.codes)}\x00')
                    self.codes.append('\n'.join(code))
                    code = []
                    verbatim = None
                    rest = rest[end + len(close) :]
                    fresh = False
                    continue

                stripped, commented = _uncomment(rest)
                match = _VERBATIM_BEGIN.search(stripped)
                if match is not None:
                    before = stripped[: match.start()]
                    if text.trim(before) != '':
                        out.append(before)
                    verbatim = match.group(1) + match.group(2)
                    rest = _skip_verbatim_arguments(rest[match.end() :], match.group(1))
                    fresh = False
                    if text.trim(rest) == '':
                        break
                    continue

                if text.trim(stripped) != '' or (not commented and fresh):
                    out.append(stripped)
                break

        if verbatim is not None:
            out.append(f'\x00{len(self.codes)}\x00')
            self.codes.append('\n'.join(code))

        return '\n'.join(out)

    def _walk(self, body: str) -> None:
        at = 0
        n = len(body)

        while at < n:
            match = _TOKEN.search(body, at)
            if match is None:
                break
            self._append(body[at : match.start()])
            at = match.end()

            if match.group(1) is not None:
                self._append(match.group(0))
            elif match.group(2) is not None:
                name = match.group(3)
                at = self._begin(body, name, at) if match.group(2) == 'begin' else self._end(name, at)
            elif match.group(4) is not None:
                at = self._section(body, SECTIONS[match.group(4)], at)
            elif match.group(5) is not None:
                at = self._command(body, match.group(5), at)
            elif match.group(6) is not None:
                end = body.find('\\]', at)
                self._math(body[at:] if end == -1 else body[at:end])
                at = n if end == -1 else end + 2
            elif match.group(7) is not None:
                self._break_paragraph()
            else:
                self._code(self.codes[int(match.group(8))])

        if at < n:
            self._append(body[at:])
        self._close_lists()
        self._flush()

    def _begin(self, body: str, name: str, at: int) -> int:
        if name in MATH or name in DROPPED:
            if name.startswith('alignat') and body[at : at + 1] == '{':
                at = latex_inline.group(body, at)[1]
            content, at = _capture(body, name, at)
            if name in MATH:
                self._math(content)
            return at
        if name in TABULARS:
            return self._tabular(body, name, at)

        kind = 'list' if name in LISTS else FLOATS.get(name, 'other')
        if kind == 'list':
            self._list_opening()
            if name == 'thebibliography':
                at = latex_inline.argument(body, at)[1]
        else:
            if self._list_depth() == 0:
                self._flush()
            at = latex_inline.direct_arguments(body, at)[1]
        self.frames.append({'name': name, 'kind': kind, 'count': 0, 'caption': None, 'tables': []})

        return at

    def _end(self, name: str, at: int) -> int:
        found = [i for i, frame in enumerate(self.frames) if frame['name'] == name]
        if not found:
            return at

        while len(self.frames) > found[-1]:
            frame = self.frames.pop()
            if frame['kind'] == 'list':
                self._finish_item()
                if self._list_depth() == 0:
                    self._emit_list()
            elif frame['kind'] == 'table':
                self._flush()
                self._emit_float(frame['caption'], frame['tables'])
            elif self._list_depth() == 0:
                self._flush()

        return at

    def _section(self, body: str, rank: int, at: int) -> int:
        at = at + 1 if body[at : at + 1] == '*' else at
        found, at = latex_inline.argument(body, at)
        self._close_lists()
        self._flush()
        heading = '' if found is None else text.squash(latex_inline.convert(found))
        if heading != '':
            self.events.append({'kind': 'heading', 'rank': rank, 'text': heading})

        return at

    def _command(self, body: str, name: str, at: int) -> int:
        if name == 'par':
            self._break_paragraph()
        elif name == 'maketitle':
            self.maketitle = True
        elif name == 'title':
            at = latex_inline.argument(body, at)[1]
        elif name == 'caption':
            at = at + 1 if body[at : at + 1] == '*' else at
            found, at = latex_inline.argument(body, at)
            self._caption('' if found is None else text.squash(latex_inline.convert(found)))
        else:
            at = self._item(body, name, at)

        return at

    def _item(self, body: str, name: str, at: int) -> int:
        label = None
        i = latex_inline.space(body, at)
        if body[i : i + 1] == '[':
            raw, at = latex_inline.optional(body, i)
            label = text.squash(latex_inline.convert(raw))
        if name == 'bibitem':
            at = latex_inline.argument(body, at)[1]

        index = self._list_index()
        if index < 0:
            return at

        self._finish_item()
        frame = self.frames[index]
        numbered = frame['name'] in ('enumerate', 'enumerate*')
        if label is None and numbered:
            frame['count'] += 1
        self.indent = '  ' * (self._list_depth() - 1)
        self.description = frame['name'] in ('description', 'description*')
        self.label = label
        self.marker = f'{frame["count"]}.' if numbered else '-'
        self.item = ''

        return at

    def _caption(self, caption: str) -> None:
        for frame in reversed(self.frames):
            if frame['kind'] == 'table':
                frame['caption'] = caption
                return
            if frame['kind'] == 'figure':
                self._interrupt()
                self._emit_paragraph('' if caption == '' else f'Figure: {caption}')
                return

        self._interrupt()
        self._emit_paragraph(caption)

    def _tabular(self, body: str, name: str, at: int) -> int:
        i = at
        required = TABULARS[name]
        while required > 0:
            i = latex_inline.space(body, i, False)
            if body[i : i + 1] == '[':
                i = latex_inline.optional(body, i)[1]
            elif body[i : i + 1] == '{':
                i = latex_inline.group(body, i)[1]
                required -= 1
            else:
                break

        content, at = _capture(body, name, i)
        caption, rows = latex_table.read(content, name == 'longtable')

        for frame in reversed(self.frames):
            if frame['kind'] == 'table':
                frame['tables'].append(rows)
                if caption is not None:
                    frame['caption'] = caption
                return at

        self._interrupt()
        self._emit_table(caption if caption is not None else '', rows)

        return at

    def _math(self, content: str) -> None:
        lines = [line for line in (text.trim(line) for line in _LABEL.sub('', content).split('\n')) if line != '']
        if lines:
            self._code('\n'.join(lines))

    def _code(self, code: str) -> None:
        self._interrupt()
        if text.trim(code) != '':
            self.events.append({'kind': 'block', 'block': Block(BlockType.CODE, code)})

    def _append(self, raw: str) -> None:
        if self._list_depth() == 0:
            self.paragraph += raw
        elif self.item is not None:
            self.item += raw

    def _break_paragraph(self) -> None:
        if self._list_depth() == 0:
            self._flush()
        else:
            self._append(' ')

    def _flush(self) -> None:
        self._emit_paragraph(text.squash(latex_inline.paragraph(self.paragraph)))
        self.paragraph = ''

    def _emit_paragraph(self, paragraph: str) -> None:
        if paragraph != '':
            self.events.append({'kind': 'block', 'block': Block.paragraph(paragraph)})

    def _interrupt(self) -> None:
        """
        Closes the open paragraph, and the list lines so far, before a block that interrupts them;
        an open item goes on after the block as an unmarked line.
        """
        self._flush()
        if self._list_depth() > 0:
            was_open = self.item is not None
            self._finish_item()
            self._emit_list()
            if was_open:
                self.item = ''
                self.marker = ''
                self.label = None
                self.description = False

    def _list_opening(self) -> None:
        if self._list_depth() == 0:
            self._flush()
        else:
            self._finish_item()

    def _finish_item(self) -> None:
        if self.item is None:
            return

        content = text.squash(latex_inline.convert(self.item))
        self.item = None

        if self.description:
            parts = [part for part in (self.label or '', content) if part != '']
            line = '' if not parts else '- ' + ': '.join(parts)
        elif self.label is None:
            line = '' if content == '' else text.trim(f'{self.marker} {content}')
        else:
            line = ' '.join(part for part in (self.label, content) if part != '')

        if line != '':
            self.list_lines.append(self.indent + line)

    def _emit_list(self) -> None:
        if self.list_lines:
            self.events.append({'kind': 'block', 'block': Block.paragraph('\n'.join(self.list_lines))})
        self.list_lines = []

    def _close_lists(self) -> None:
        if self._list_depth() > 0:
            self._finish_item()
            self._emit_list()
            self.frames = [frame for frame in self.frames if frame['kind'] != 'list']

    def _emit_float(self, caption: str | None, tables: list[list[list[str]]]) -> None:
        if not tables:
            self._emit_paragraph('' if not caption else f'Table: {caption}')
            return
        for rows in tables:
            self._emit_table(caption if caption is not None else '', rows)

    def _emit_table(self, caption: str, rows: list[list[str]]) -> None:
        if not rows:
            return
        lines = [' | '.join(row) for row in rows]
        if caption != '':
            lines.insert(0, f'Table: {caption}')
        meta = {'caption': caption, 'header': rows[0], 'rows': rows[1:]}
        self.events.append({'kind': 'block', 'block': Block(BlockType.TABLE, '\n'.join(lines), None, meta)})

    def _list_depth(self) -> int:
        return sum(1 for frame in self.frames if frame['kind'] == 'list')

    def _list_index(self) -> int:
        for i in range(len(self.frames) - 1, -1, -1):
            if self.frames[i]['kind'] == 'list':
                return i

        return -1

    def _blocks(self, title: str) -> list[Block]:
        ranks = [event['rank'] for event in self.events if 'rank' in event]
        with_title = title != '' and (self.maketitle or not ranks)
        top = min(ranks) if ranks else 0
        blocks = [Block.heading(title, 1)] if with_title else []

        for event in self.events:
            if 'block' in event:
                blocks.append(event['block'])
            else:
                blocks.append(Block.heading(event['text'], event['rank'] - top + (2 if with_title else 1)))

        return blocks


def _uncomment(line: str) -> tuple[str, bool]:
    """The line before its first unescaped `%`, and whether there was one."""
    i = 0
    n = len(line)
    while i < n:
        if line[i] == '\\':
            verb = _VERB.match(line, i)
            if verb is not None:
                close = line.find(verb.group(1), i + len(verb.group(0)))
                i = n if close == -1 else close
            else:
                i += 1
        elif line[i] == '%':
            return line[:i], True
        i += 1

    return line, False


def _skip_verbatim_arguments(rest: str, name: str) -> str:
    i = latex_inline.space(rest, 0)
    if rest[i : i + 1] == '[':
        i = latex_inline.space(rest, latex_inline.optional(rest, i)[1])
    if name == 'minted' and rest[i : i + 1] == '{':
        i = latex_inline.group(rest, i)[1]

    return rest[i:]


def _title(clean: str) -> str:
    match = _TITLE.search(clean)
    if match is None:
        return ''
    found = latex_inline.argument(clean, match.end())[0]

    return '' if found is None else text.squash(latex_inline.convert(found))


def _capture(body: str, name: str, at: int) -> tuple[str, int]:
    """The environment's content and the offset after its `\\end`."""
    opening = '\\begin{' + name + '}'
    closing = '\\end{' + name + '}'
    depth = 1
    start = at

    while True:
        next_open = body.find(opening, start)
        next_close = body.find(closing, start)
        if next_close == -1:
            return body[at:], len(body)
        if next_open != -1 and next_open < next_close:
            depth += 1
            start = next_open + len(opening)
            continue
        depth -= 1
        if depth == 0:
            return body[at:next_close], next_close + len(closing)
        start = next_close + len(closing)
