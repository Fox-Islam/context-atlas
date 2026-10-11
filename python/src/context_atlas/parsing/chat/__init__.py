"""
Reads a chat or agent transcript from any model vendor (JSON or JSON Lines) into blocks: one
paragraph per message with `speaker`, `turn`, `time` and `reply_to` in meta, a code block per
tool call and per tool result, reasoning, system prompts and media as attachments. Shapes are
recognised by structure, not by a vendor flag. The rules are stated in spec/formats/chat.md.
"""
from __future__ import annotations

from typing import Any

from ... import text
from ...blocks import Block
from ...errors import ContextAtlasError
from . import chat_json, messages, parts, thread
from .chat_json import JsonMap
from .parts import Part

UNRECOGNISED = (
    'A chat transcript must be a JSON array or JSON Lines of messages, an object holding them (messages, contents, '
    'history, input, items, chat_messages, mapping, ...), or a list of conversations.'
)

_ITEM_KEYS = ('chat_messages', 'messages', 'contents', 'history', 'items', 'data')


def parse(source: str) -> list[Block]:
    """:raises ContextAtlasError: for text that is not JSON or JSON Lines, or holds no transcript"""
    root = _decode(source[1:] if source.startswith('﻿') else source)
    if isinstance(root, JsonMap):
        return _object(root)
    if not isinstance(root, list):
        raise ContextAtlasError(UNRECOGNISED)
    if not root or thread.recognises(root):
        return thread.blocks(root, [])
    conversations = [item for item in root if isinstance(item, JsonMap) and _items(item) is not None]
    if not conversations:
        raise ContextAtlasError(UNRECOGNISED)

    return _many(conversations)


def _decode(source: str) -> Any:
    """
    One JSON value, or the records of JSON Lines as a list: the text is JSON Lines when it is
    not one JSON value, its first non-blank line is, and another non-blank line follows.
    """
    try:
        return chat_json.decode(source)
    except ContextAtlasError as error:
        lines = source.split('\n')
        filled = [n for n, line in enumerate(lines) if line.strip(' \t\r') != '']
        if len(filled) < 2 or not chat_json.valid(lines[filled[0]]):
            raise
        records = []
        for n in filled:
            try:
                records.append(chat_json.decode(lines[n]))
            except ContextAtlasError as invalid:
                raise ContextAtlasError(f'Invalid JSONL at line {n + 1}: {invalid}') from error

        return records


def _object(root: JsonMap) -> list[Block]:
    found = root.list('conversations')
    if found is not None:
        return _many([item for item in found if isinstance(item, JsonMap) and _items(item) is not None])
    conversation = root if _items(root) is not None else root.map('conversation')
    if conversation is not None:
        items = _items(conversation)
        if items is not None:
            return thread.blocks(items, _system(conversation))
    if messages.read(root) is not None:
        return thread.blocks([root], [])
    raise ContextAtlasError(UNRECOGNISED)


def _many(conversations: list[JsonMap]) -> list[Block]:
    """Each conversation under a level-1 heading of its title."""
    found: list[Block] = []
    for n, conversation in enumerate(conversations):
        named = [text.squash(conversation.string(key) or '') for key in ('title', 'name')]
        title = next((value for value in named if value != ''), None)
        if title is None:
            title = (
                parts.id_of(conversation.get('id'))
                or parts.first(conversation, ['uuid', 'conversation_id'])
                or f'Conversation {n + 1}'
            )
        found.append(Block.heading(title, 1))
        found.extend(thread.blocks(_items(conversation) or [], _system(conversation)))

    return found


def _items(conversation: JsonMap) -> list[Any] | None:
    """
    A conversation's items: the current branch of a ChatGPT `mapping`, else the first list among
    the item keys, else `input` followed by `output`; None when it holds none.
    """
    mapping = conversation.map('mapping')
    if mapping is not None:
        return _branch(mapping, conversation.string('current_node'))
    for key in _ITEM_KEYS:
        found = conversation.list(key)
        if found is not None:
            return found
    given = conversation.list('input')
    output = conversation.list('output')

    return None if given is None and output is None else [*(given or []), *(output or [])]


def _branch(mapping: JsonMap, current: str | None) -> list[Any]:
    """
    The nodes from the root to `current_node`, following `parent`; without a usable
    `current_node`, from the first node with no parent in the mapping down its last children. A
    member whose value is null counts as missing.
    """
    nodes = mapping.members
    if current is None or nodes.get(current) is None:
        return _downward(nodes)
    path = []
    seen: set[str] = set()
    node_id: str | None = current
    while node_id is not None and nodes.get(node_id) is not None and node_id not in seen:
        seen.add(node_id)
        node = nodes[node_id]
        path.append(node)
        node_id = parts.id_of(node.get('parent')) if isinstance(node, JsonMap) else None

    return path[::-1]


def _downward(nodes: dict[str, Any]) -> list[Any]:
    node_id = None
    for key, node in nodes.items():
        parent = parts.id_of(node.get('parent')) if isinstance(node, JsonMap) else None
        if parent is None or nodes.get(parent) is None:
            node_id = key
            break
    path = []
    seen: set[str] = set()
    while node_id is not None and nodes.get(node_id) is not None and node_id not in seen:
        seen.add(node_id)
        node = nodes[node_id]
        path.append(node)
        children = [
            child
            for child in (node.list('children') or [] if isinstance(node, JsonMap) else [])
            if (child_id := parts.id_of(child)) is not None and nodes.get(child_id) is not None
        ]
        node_id = parts.id_of(children[-1]) if children else None

    return path


def _system(conversation: JsonMap) -> list[Part]:
    """
    The system prompt parts of a conversation: `system`, `systemInstruction` or
    `system_instruction`, and `instructions`, in that order.
    """
    found: list[Part] = []
    for key in ('system', 'systemInstruction', 'system_instruction', 'instructions'):
        value = conversation.get(key)
        if isinstance(value, JsonMap) and value.list('parts') is not None:
            value = value.list('parts')
        if isinstance(value, (str, list, JsonMap)):
            found.extend(parts.of(value))

    return found
