from __future__ import annotations

from dataclasses import dataclass, field

from .. import text
from ..blocks import Block, BlockType
from ..chunk import Chunk
from ..config import Config
from .breadcrumbs import Crumb
from .topic_shifts import Shifts

# PHP's `trim()` characters: ASCII space, tab, newline, carriage return, NUL and vertical tab.
_PHP_TRIM = ' \t\n\r\x00\x0b'


@dataclass
class _Draft:
    blocks: list[int] = field(default_factory=list)
    text: str = ''
    tokens: int = 0
    topic_start: bool = False


@dataclass(frozen=True)
class _Part:
    text: str
    topic_start: bool


class Packer:
    """
    Packs blocks into chunks between `min_tokens` and `max_tokens`.

    A heading always starts a chunk, and so does a block or sentence that opens a new topic once
    the chunk so far holds `topic_min_tokens`. The heading's text is not part of the chunk's own
    text, since it ends the path the chunk is prefixed with, and a heading with nothing under it
    before the next heading makes no chunk. A paragraph longer than `split_long_paragraph_at` is
    packed sentence by sentence. Each chunk is then prefixed with its heading path, followed by
    the tail of the previous chunk's own text when both sit in the same section and it does not
    open a new topic.
    """

    def __init__(
        self,
        blocks: list[Block],
        tokens: list[int],
        crumbs: list[Crumb],
        shifts: Shifts,
        config: Config,
    ) -> None:
        self._blocks = blocks
        self._tokens = tokens
        self._crumbs = crumbs
        self._shifts = shifts
        self._config = config
        self._drafts: list[_Draft] = []
        self._current = _Draft()

    def pack(self) -> list[Chunk]:
        config = self._config

        for index, block in enumerate(self._blocks):
            opens_topic = index in self._shifts.blocks and self._current.tokens >= config.topic_min_tokens

            if block.is_heading():
                self._flush(False)
                self._current = _Draft(blocks=[index])
                continue
            if opens_topic and self._current.text != '':
                self._flush(True)

            if block.type is BlockType.PARAGRAPH and self._tokens[index] > config.split_long_paragraph_at:
                self._add_sentences(index, block.text)
            elif block.type in (BlockType.CODE, BlockType.TABLE) and self._tokens[index] > config.max_tokens:
                for part in self._line_parts(text.trim(block.text), block.type is BlockType.TABLE):
                    self._add_block(index, part, text.tokens(part, config.chars_per_token))
            else:
                self._add_block(index, text.trim(block.text), self._tokens[index])

        if self._current.text != '':
            self._flush(False)

        return self._finish()

    def _add_block(self, index: int, body: str, tokens: int) -> None:
        if self._current.tokens + tokens <= self._config.max_tokens:
            self._append(index, body, tokens)
        elif self._current.tokens >= self._config.min_tokens:
            self._flush(False)
            self._append(index, body, tokens)
        else:
            self._append(index, body, tokens)
            self._flush(False)

    def _add_sentences(self, index: int, body: str) -> None:
        config = self._config

        parts = [
            _Part(piece, part.topic_start and i == 0)
            for part in self._sentence_parts(body, self._shifts.sentences.get(index, set()))
            for i, piece in enumerate(self._cut(part.text, config.max_tokens))
        ]
        for part in parts:
            tokens = text.tokens(part.text, config.chars_per_token)
            full = self._current.tokens + tokens > config.max_tokens and self._current.tokens >= config.min_tokens
            if self._current.text != '' and (full or part.topic_start):
                self._flush(part.topic_start)
            self._append(index, part.text, tokens)

    def _sentence_parts(self, body: str, shifts: set[int]) -> list[_Part]:
        config = self._config
        parts: list[_Part] = []
        buffer = ''
        tokens = 0
        topic_start = False

        for i, sentence in enumerate(text.sentences(body)):
            sentence_tokens = text.tokens(sentence, config.chars_per_token)
            opens_topic = i in shifts and tokens >= config.topic_min_tokens
            full = tokens + sentence_tokens > config.max_tokens and tokens >= config.min_tokens
            if buffer != '' and (full or opens_topic):
                parts.append(_Part(text.trim(buffer), topic_start))
                buffer = sentence
                tokens = sentence_tokens
                topic_start = opens_topic
            else:
                buffer = sentence if buffer == '' else f'{buffer} {sentence}'
                tokens += sentence_tokens

        if buffer != '':
            parts.append(_Part(text.trim(buffer), topic_start))

        return parts

    def _append(self, index: int, body: str, tokens: int) -> None:
        current = self._current
        if index not in current.blocks:
            current.blocks.append(index)
        current.text = body if current.text == '' else f'{current.text}\n\n{body}'
        current.tokens += tokens

    def _line_parts(self, body: str, table: bool) -> list[str]:
        """
        A code or table block over `max_tokens` cut at line boundaries into parts of at most
        `max_tokens`; each part of a table starts with its head line (after its `Table:` caption
        line, when it has one). A line still over the limit is cut by `_cut`.
        """
        per_token = self._config.chars_per_token
        lines = body.split('\n')
        head: list[str] = []
        if table:
            head.append(lines.pop(0))
            if head[0].startswith('Table:') and lines:
                head.append(lines.pop(0))
        head_tokens = text.tokens('\n'.join(head) + '\n', per_token) if head else 0
        limit = max(1, self._config.max_tokens - head_tokens)
        parts: list[str] = []
        buffer: list[str] = []
        tokens = 0
        for line in lines:
            for piece in self._cut(line, limit):
                piece_tokens = text.tokens(piece + '\n', per_token)
                if buffer and tokens + piece_tokens > limit:
                    parts.append('\n'.join([*head, *buffer]))
                    buffer, tokens = [], 0
                buffer.append(piece)
                tokens += piece_tokens
        if buffer or not parts:
            parts.append('\n'.join([*head, *buffer]))

        return parts

    def _cut(self, body: str, limit: int) -> list[str]:
        """
        A text over `limit` tokens cut into pieces of at most `limit`, each ending before a space
        when there is one in its second half, else at the limit.
        """
        per_token = self._config.chars_per_token
        pieces = []
        while text.tokens(body, per_token) > limit:
            length = 1
            while length < len(body) and text.tokens(body[: length + 1], per_token) <= limit:
                length += 1
            space = body[:length].rfind(' ')
            stop = space if space != -1 and space >= length // 2 else length
            pieces.append(body[:stop].rstrip(_PHP_TRIM))
            body = body[stop:].lstrip(_PHP_TRIM)
        pieces.append(body)

        return pieces

    def _flush(self, next_opens_topic: bool) -> None:
        """Closes the current chunk; with no text there is nothing to close, and its mark stays."""
        if self._current.text == '':
            return
        self._drafts.append(self._current)
        self._current = _Draft(topic_start=next_opens_topic)

    def _finish(self) -> list[Chunk]:
        """
        Prefixes each chunk with its heading path, then the tail of the previous chunk's own text
        when both sit in the same section under the same path and this one opens no topic.
        """
        config = self._config
        chunks: list[Chunk] = []
        previous: tuple[str, Crumb] | None = None

        for draft in self._drafts:
            crumb = self._crumbs[draft.blocks[0]]
            overlap = ''

            if (
                previous is not None
                and config.overlap_tokens > 0
                and not draft.topic_start
                and previous[1].section == crumb.section
                and previous[1].path == crumb.path
            ):
                overlap = text.tail(previous[0], config.overlap_tokens, config.chars_per_token)

            prefix = f'[{" > ".join(crumb.path)}]\n\n' if crumb.path else ''
            carried = f'{overlap}\n\n' if overlap != '' else ''
            full = prefix + carried + draft.text

            chunks.append(
                Chunk(
                    full,
                    list(draft.blocks),
                    list(crumb.path),
                    crumb.level,
                    crumb.section,
                    text.tokens(full, config.chars_per_token),
                    text.tokens(overlap, config.chars_per_token) if overlap != '' else 0,
                    draft.topic_start,
                )
            )
            previous = (draft.text, crumb)

        return chunks
