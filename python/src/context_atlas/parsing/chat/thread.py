"""
Turns one conversation's items into blocks: a paragraph per message, a code block per tool call
and per tool result, attachments marked, and `turn`, `reply_to` and `call` links worked out
across the conversation. The rules are stated in spec/formats/chat.md.
"""
from __future__ import annotations

from typing import Any

from ... import text
from ...blocks import Block, BlockType
from . import messages
from .messages import Message
from .parts import Part

_META_ORDER = ('speaker', 'speaker_full', 'turn', 'time', 'reply_to', 'call', 'tool', 'kind', 'attachment', 'error')


def blocks(items: list[Any], system: list[Part]) -> list[Block]:
    """`system` holds the conversation's system prompt parts."""
    thread = _Thread()
    prompt = _joined(system, 'text')
    if prompt != '':
        thread.add(BlockType.PARAGRAPH, prompt, {'speaker': 'system', 'turn': 'system', 'attachment': True})
    for position, item in _kept(items):
        message = messages.read(item)
        if isinstance(message, Message):
            thread.message(message, str(position))
        else:
            ids, parent = messages.links(item)
            for found in ids:
                thread.by_id.setdefault(found, (parent, None))

    return thread.blocks


def recognises(items: list[Any]) -> bool:
    """Whether any item is a recognised one."""
    return any(messages.read(item) is not None for item in items)


def _kept(items: list[Any]) -> list[tuple[int, Any]]:
    """
    The items, with their positions, left after dropping a repeated `uuid` (the first is kept)
    and, when some item has `isSidechain: false`, every item with `isSidechain: true`.
    """
    main = any(messages.envelope_of(item)[1] is False for item in items)
    seen: set[str] = set()
    kept = []
    for position, item in enumerate(items):
        uuid, sidechain = messages.envelope_of(item)
        if (uuid is not None and uuid in seen) or (main and sidechain is True):
            continue
        if uuid is not None:
            seen.add(uuid)
        kept.append((position, item))

    return kept


class _Thread:
    def __init__(self) -> None:
        self.blocks: list[Block] = []
        self.by_id: dict[str, tuple[str | None, str | None]] = {}
        self.turns: set[str] = set()
        self.calls: list[dict[str, Any]] = []

    def message(self, message: Message, position: str) -> None:
        turn = message.ids[0] if message.ids else position
        reply_to = self._reply_to(message.parent, turn)
        base = {'speaker': message.role, 'speaker_full': message.speaker_full, 'turn': turn, 'time': message.time}
        before = len(self.blocks)
        has_results = any(part[0] == 'result' for part in message.parts)

        if message.result and not has_results:
            whole = ('result', _joined(message.parts, 'text', 'media'), message.call_id, message.tool, message.error)
            self._result(base, whole, 0)
        else:
            self._parts(message, base, reply_to)

        last = self.blocks[-1].meta.get('turn') if len(self.blocks) > before else None
        for found in message.ids:
            self.by_id.setdefault(found, (message.parent, last if isinstance(last, str) else None))

    def _parts(self, message: Message, base: dict[str, Any], reply_to: str | None) -> None:
        """
        Blocks in the order of the parts, except that all text parts make one paragraph where the
        first stood, and all reasoning parts one where the first stood.
        """
        attachment = message.role == 'system'
        done = {'text': False, 'reasoning': False}
        k = 0
        for part in message.parts:
            kind = part[0]
            if kind in ('text', 'reasoning'):
                if done[kind]:
                    continue
                done[kind] = True
                joined = _joined(message.parts, kind)
                if kind == 'reasoning':
                    extra: dict[str, Any] = {'kind': 'reasoning', 'attachment': True}
                else:
                    extra = {'attachment': True} if attachment else {}
                self.add(BlockType.PARAGRAPH, joined, {**base, 'reply_to': reply_to, **extra})
            elif kind == 'media':
                self.add(BlockType.PARAGRAPH, part[1], {**base, 'reply_to': reply_to, 'attachment': True})
            elif kind == 'call':
                self._call(base, part, k, reply_to)
                k += 1
            else:
                self._result(base, part, k)
                k += 1

    def _call(self, base: dict[str, Any], part: Part, k: int, reply_to: str | None) -> None:
        call_id = part[3]
        turn = f'{base["turn"]}#{call_id if call_id is not None else k}'
        answers = base['turn'] if base['turn'] in self.turns else reply_to
        self.calls.append({'id': call_id, 'tool': part[1], 'turn': turn, 'answered': False})
        meta = {**base, 'turn': turn, 'reply_to': answers, 'call': call_id, 'tool': part[1]}
        self.add(BlockType.CODE, f'{part[1]}({part[2]})', meta)

    def _result(self, base: dict[str, Any], part: Part, k: int) -> None:
        call_id, tool = part[2], part[3]
        call = self._answer(call_id, tool)
        if tool is None and call is not None:
            tool = self.calls[call]['tool']
        if text.trim(_lines(part[1])) == '':
            return
        turn = f'{base["turn"]}#{call_id if call_id is not None else k}'
        answers = None if call is None else self.calls[call]['turn']
        if turn == answers:
            turn += '/result'
        meta = {
            'speaker': 'tool',
            'turn': turn,
            'time': base['time'],
            'reply_to': answers,
            'tool': tool,
            'attachment': True,
        }
        if part[4]:
            meta['error'] = True
        self.add(BlockType.CODE, part[1], meta)

    def _answer(self, call_id: str | None, tool: str | None) -> int | None:
        """
        The index of the call a result answers: the latest earlier call with its id, else the
        earliest unanswered earlier call with its tool name; None when none.
        """
        found = None
        for i, call in enumerate(self.calls):
            if call_id is not None:
                if call['id'] == call_id:
                    found = i
            elif tool is not None and call['tool'] == tool and not call['answered'] and found is None:
                found = i
        if found is not None:
            self.calls[found]['answered'] = True

        return found

    def _reply_to(self, parent: str | None, turn: str) -> str | None:
        """
        The turn of the last block of the nearest ancestor (followed by parent ids through items
        that gave no block or share this turn) whose last block has another turn.
        """
        seen: set[str] = set()
        while parent is not None and parent in self.by_id and parent not in seen:
            seen.add(parent)
            following, last = self.by_id[parent]
            if last is not None and last != turn:
                return last
            parent = following

        return None

    def add(self, kind: BlockType, body: str, meta: dict[str, Any]) -> None:
        body = text.trim(_lines(body))
        if body == '':
            return
        if meta.get('reply_to') == meta.get('turn'):
            meta['reply_to'] = None
        ordered = {key: meta[key] for key in _META_ORDER if meta.get(key) is not None}
        self.blocks.append(Block(kind, body, None, ordered))
        turn = ordered.get('turn')
        if isinstance(turn, str):
            self.turns.add(turn)


def _joined(found: list[Part], *kinds: str) -> str:
    """
    The texts of the parts of the given kinds, each with line endings made `\\n` and trimmed, the
    non-empty ones joined by a blank line.
    """
    texts = [text.trim(_lines(part[1])) for part in found if part[0] in kinds]

    return '\n\n'.join(item for item in texts if item != '')


def _lines(value: str) -> str:
    return value.replace('\r\n', '\n').replace('\r', '\n')
