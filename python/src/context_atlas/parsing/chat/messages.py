"""
Reads one item of a transcript (a message, a Responses API item, a session-log event or a
LangChain dict) into a Message, recognising it by its structure. The rules are stated in
spec/formats/chat.md.
"""
from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Any

from ... import text
from . import parts as content
from .chat_json import JsonMap, JsonNumber
from .parts import Part

SKIP = 'skip'

_ROLES = {
    'user': 'user',
    'human': 'user',
    'assistant': 'assistant',
    'ai': 'assistant',
    'model': 'assistant',
    'bot': 'assistant',
    'chatbot': 'assistant',
    'gemini': 'assistant',
    'system': 'system',
    'developer': 'system',
    'tool': 'tool',
    'function': 'tool',
    'ipython': 'tool',
}

_TYPE_ROLES = ('human', 'ai', 'user', 'assistant', 'system', 'developer', 'tool', 'function', 'gemini', 'model')

_TIME_KEYS = ('timestamp', 'created_at', 'create_time', 'createdAt', 'time')

_PARENT_KEYS = ('parentUuid', 'parent_message_uuid', 'parent', 'parentId', 'parent_id')

_CLASS = re.compile(r'^(Human|AI|System|Tool|Function)Message(?:Chunk)?$')

_LOWER = str.maketrans('ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')

_EPOCH = re.compile(r'^([0-9]{1,15})(?:\.[0-9]+)?$')


@dataclass(frozen=True)
class Message:
    """
    One message, event or item of a transcript in vendor-neutral form: who sent it, every id it
    can be named by (first the turn id), and its parts in order. `result` marks a tool message
    whose whole text is one result.
    """

    role: str
    speaker_full: str | None
    ids: list[str]
    parent: str | None
    time: str | None
    parts: list[Part] = field(default_factory=list)
    result: bool = False
    call_id: str | None = None
    tool: str | None = None
    error: bool = False


def read(item: Any) -> Message | str | None:
    """
    The message an item holds, SKIP for a recognised item with nothing to read, or None for an
    item of no known shape.
    """
    if not isinstance(item, JsonMap):
        return None
    queued = _queued(item)
    if queued is not None:
        return queued
    inner, envelope = _unwrap(item)
    if inner is None:
        return None if envelope is None else SKIP
    if (envelope is not None and envelope.get('isMeta') is True) or inner.get('isMeta') is True:
        origin = envelope.map('origin') if envelope is not None else None
        kind = origin.string('kind') if origin is not None else None
        if kind is None:
            return SKIP
        return Message(
            'user',
            kind,
            _ids(inner, envelope),
            _parent(inner, envelope),
            _time(inner, envelope),
            content.of(inner.get('content')),
        )

    return _item(inner, envelope)


def envelope_of(item: Any) -> tuple[str | None, bool | None]:
    """The envelope fields that decide whether an item is kept: its `uuid` and `isSidechain`."""
    if not isinstance(item, JsonMap):
        return None, None
    sidechain = item.get('isSidechain')

    return item.string('uuid'), sidechain if isinstance(sidechain, bool) else None


def links(item: Any) -> tuple[list[str], str | None]:
    """The ids (`uuid`, `id`) and parent of an item that gives no message, so `reply_to` can be followed through it."""
    if not isinstance(item, JsonMap):
        return [], None

    return _ids(item, None), _parent(item, None)


def role(name: str) -> str:
    """The speaker for a role: the table above for a known role (ASCII case ignored), else the role as written."""
    return _ROLES.get(_ascii_lower(name), name)


def _queued(item: JsonMap) -> Message | None:
    """A Claude Code `attachment` event of type `queued_command`: a user message sent while the agent was working."""
    attachment = item.map('attachment') if item.string('type') == 'attachment' else None
    if attachment is None or attachment.string('type') != 'queued_command' or not attachment.has('prompt'):
        return None

    return Message(
        'user', 'queued', _ids(item, None), _parent(item, None), _time(item, None), content.of(attachment.get('prompt'))
    )


def _unwrap(item: JsonMap) -> tuple[JsonMap | None, JsonMap | None]:
    """The message object an item holds and the envelope around it; `(None, envelope)` marks a bookkeeping event."""
    kind = item.string('type')
    payload = item.map('payload')
    if kind is not None and payload is not None:
        return (payload if kind == 'response_item' else None), item
    message = item.map('message')
    if message is not None:
        return message, item
    if item.has('message') and item.get('message') is None and (item.has('parent') or item.has('children')):
        return None, item
    data = item.map('data')
    if kind is not None and data is not None:
        return data, item
    kwargs = item.map('kwargs')
    if kind == 'constructor' and kwargs is not None:
        return kwargs, item
    if kind == 'system' and item.string('subtype') is not None:
        return None, item

    return item, None


def _item(inner: JsonMap, envelope: JsonMap | None) -> Message | None:
    kind = inner.string('type') or ''
    summary = inner.string('summary')
    if kind == 'summary' and summary is not None:
        return _message(inner, envelope, 'system', [('text', summary)])
    if inner.get('isCompactSummary') is True or (envelope is not None and envelope.get('isCompactSummary') is True):
        return _message(inner, envelope, 'system', content.of(inner.get('content')))
    if kind == 'reasoning':
        return _message(inner, envelope, 'assistant', _reasoning_item(inner), False)
    if kind == 'function_call_output' or kind.endswith('_call_output'):
        found = content.result(inner.get('output'), inner.get('call_id'), None, False)
        return _message(inner, envelope, 'tool', found, False)
    if kind == 'function_call' or kind.endswith('_call'):
        return _message(inner, envelope, 'assistant', _call_item(inner, kind), False)
    raw = _role_of(inner, envelope)

    return None if raw is None else _conversational(inner, envelope, role(raw))


def _role_of(inner: JsonMap, envelope: JsonMap | None) -> str | None:
    """
    The raw role: `role`, `author.role`, `sender`, a `type` naming a role, the envelope's `type`
    naming a role, or `user` for a Gemini Content without one.
    """
    author = inner.map('author')
    found = inner.string('role') or (author.string('role') if author is not None else None) or inner.string('sender')
    if found is not None:
        return found
    names = envelope.list('id') if envelope is not None else None
    name = names[-1] if names and isinstance(names[-1], str) else ''
    match = _CLASS.match(name)
    if match is not None:
        return match.group(1)
    for source in (inner, envelope):
        kind = source.string('type') if source is not None else None
        if kind is not None and _ascii_lower(kind) in _TYPE_ROLES:
            return kind

    return 'user' if inner.list('parts') is not None else None


def _conversational(inner: JsonMap, envelope: JsonMap | None, speaker: str) -> Message:
    found = [
        *_reasoning_fields(inner),
        *_content_parts(inner),
        *_extra_text(inner),
        *_media(inner),
        *_tool_calls(inner),
    ]
    recipient = inner.string('recipient')
    if speaker == 'assistant' and recipient is not None and recipient != 'all':
        found = _recipient_call(found, recipient)
    if speaker != 'tool':
        return _message(inner, envelope, speaker, found)
    error = inner.get('is_error') is True or inner.get('status') == 'error'
    author = inner.map('author')
    tool = content.first(inner, ['name', 'tool_name']) or (author.string('name') if author is not None else None)

    return Message(
        'tool',
        None,
        _ids(inner, envelope),
        _parent(inner, envelope),
        _time(inner, envelope),
        found,
        True,
        content.id_of(inner.get('tool_call_id')),
        tool,
        error,
    )


def _message(inner: JsonMap, envelope: JsonMap | None, speaker: str, found: list[Part], named: bool = True) -> Message:
    return Message(
        speaker,
        _speaker_full(inner) if named else None,
        _ids(inner, envelope),
        _parent(inner, envelope),
        _time(inner, envelope),
        found,
    )


def _speaker_full(inner: JsonMap) -> str | None:
    """`name`, `author.name`, `model`, then `metadata.model_slug`."""
    author = inner.map('author')
    metadata = inner.map('metadata')

    return (
        content.first(inner, ['name'])
        or (author.string('name') if author is not None else None)
        or content.first(inner, ['model'])
        or (metadata.string('model_slug') if metadata is not None else None)
    )


def _ids(inner: JsonMap, envelope: JsonMap | None) -> list[str]:
    """The message's `id` and `uuid`, then the envelope's `uuid` and `id`."""
    ids: list[str] = []
    for source, key in ((inner, 'id'), (inner, 'uuid'), (envelope, 'uuid'), (envelope, 'id')):
        found = content.id_of(source.get(key)) if source is not None else None
        if found is not None and found not in ids:
            ids.append(found)

    return ids


def _parent(inner: JsonMap, envelope: JsonMap | None) -> str | None:
    for source in (envelope, inner):
        for key in _PARENT_KEYS:
            found = content.id_of(source.get(key)) if source is not None else None
            if found is not None:
                return found

    return None


def _time(inner: JsonMap, envelope: JsonMap | None) -> str | None:
    for source in (inner, envelope):
        for key in _TIME_KEYS:
            found = _time_value(source.get(key)) if source is not None else None
            if found is not None:
                return found

    return None


def _time_value(value: Any) -> str | None:
    """
    A string as given (when not blank), or a number of seconds since 1970 (milliseconds when its
    integer part has 12 or more digits) as `YYYY-MM-DDTHH:MM:SSZ`.
    """
    if isinstance(value, str):
        return None if text.trim(value) == '' else value
    match = _EPOCH.match(value.raw) if isinstance(value, JsonNumber) else None
    if match is None:
        return None
    seconds = int(match.group(1)) // 1000 if len(match.group(1)) >= 12 else int(match.group(1))

    return _utc(seconds)


def _utc(seconds: int) -> str:
    """PHP's `gmdate('Y-m-d\\TH:i:s\\Z')`, which writes years past 9999 in full."""
    days, rest = divmod(seconds, 86400)
    shifted = days + 719468
    era = shifted // 146097
    day_of_era = shifted - era * 146097
    year_of_era = (day_of_era - day_of_era // 1460 + day_of_era // 36524 - day_of_era // 146096) // 365
    day_of_year = day_of_era - (365 * year_of_era + year_of_era // 4 - year_of_era // 100)
    month_index = (5 * day_of_year + 2) // 153
    day = day_of_year - (153 * month_index + 2) // 5 + 1
    month = month_index + 3 if month_index < 10 else month_index - 9
    year = year_of_era + era * 400 + (1 if month <= 2 else 0)
    hours, rest = divmod(rest, 3600)
    minutes, secs = divmod(rest, 60)

    return f'{year:04d}-{month:02d}-{day:02d}T{hours:02d}:{minutes:02d}:{secs:02d}Z'


def _reasoning_fields(inner: JsonMap) -> list[Part]:
    found: list[Part] = []
    for key in ('reasoning_content', 'reasoning'):
        value = inner.string(key)
        if value is not None:
            found.append(('reasoning', value))
    if inner.string('reasoning') is None:
        texts = [
            (content.first(detail, ['text', 'summary']) or '') if isinstance(detail, JsonMap) else ''
            for detail in inner.list('reasoning_details') or []
        ]
        found.append(('reasoning', _join(texts)))
    for key in ('thinking', 'tool_plan'):
        value = inner.string(key)
        if value is not None:
            found.append(('reasoning', value))
    for thought in inner.list('thoughts') or []:
        if isinstance(thought, JsonMap):
            found.append(('reasoning', _join([thought.string('subject') or '', thought.string('description') or ''])))

    return found


def _content_parts(inner: JsonMap) -> list[Part]:
    found = content.of(inner.get('content')) if inner.get('content') is not None else content.of(inner.list('parts'))
    value = inner.get('text')
    if not found and isinstance(value, str):
        return [('text', value)]

    return found


def _extra_text(inner: JsonMap) -> list[Part]:
    found: list[Part] = []
    refusal = inner.string('refusal')
    if refusal is not None:
        found.append(('text', refusal))
    audio = inner.map('audio')
    transcript = audio.string('transcript') if audio is not None else None
    if transcript is not None:
        found.append(('text', transcript))

    return found


def _media(inner: JsonMap) -> list[Part]:
    found: list[Part] = [('media', '[image]') for _ in inner.list('images') or []]
    for key in ('attachments', 'files'):
        for attached in inner.list(key) or []:
            if isinstance(attached, JsonMap):
                found.append(('media', content.file(content.first(attached, ['file_name', 'filename', 'name']))))

    return found


def _tool_calls(inner: JsonMap) -> list[Part]:
    found: list[Part] = []
    for call in inner.list('tool_calls') or []:
        if not isinstance(call, JsonMap):
            continue
        function = call.map('function') or call.map('custom')
        if function is not None:
            arguments = _either(function.get('arguments'), function.get('input'))
        else:
            arguments = _either(call.get('args'), _either(call.get('arguments'), call.get('input')))
        name = _either(function.get('name') if function is not None else None, call.get('name'))
        found.extend(content.call(name, arguments, _either(call.get('id'), call.get('call_id'))))
    for call in inner.list('toolCalls') or []:
        if not isinstance(call, JsonMap):
            continue
        found.extend(content.call(call.get('name'), call.get('args'), call.get('id')))
        if call.get('result') is not None:
            error = call.get('status') == 'error'
            found.extend(content.result(call.get('result'), call.get('id'), call.get('name'), error))
    legacy = inner.map('function_call')
    if legacy is not None:
        found.extend(content.call(legacy.get('name'), legacy.get('arguments'), None))

    return found


def _recipient_call(found: list[Part], recipient: str) -> list[Part]:
    """A ChatGPT assistant message addressed to a tool: its text becomes the call's arguments."""
    texts = [part[1] for part in found if part[0] == 'text']
    rest = [part for part in found if part[0] != 'text']

    return [*rest, *content.call(recipient, '\n\n'.join(texts), None)]


def _reasoning_item(item: JsonMap) -> list[Part]:
    for key in ('summary', 'content'):
        texts = [(part.string('text') or '') if isinstance(part, JsonMap) else '' for part in item.list(key) or []]
        joined = _join(texts)
        if joined != '':
            return [('reasoning', joined)]

    return []


def _call_item(item: JsonMap, kind: str) -> list[Part]:
    """
    A Responses API tool call item: `function_call`, `custom_tool_call` and the built-in `*_call`
    items (named by `name`, else the type without `_call`), plus its `output` when it carries one.
    """
    name = item.string('name') or kind[:-5]
    arguments = next((item.get(key) for key in ('arguments', 'input', 'action', 'code') if item.has(key)), None)
    call_id = _either(item.get('call_id'), item.get('id'))
    found = content.call(name, arguments, call_id)
    if item.get('output') is not None:
        error = item.string('error') is not None or item.map('error') is not None
        found.extend(content.result(item.get('output'), call_id, name, error))

    return found


def _ascii_lower(value: str) -> str:
    return value.translate(_LOWER)


def _either(value: Any, fallback: Any) -> Any:
    """PHP's `??`: the value unless it is null."""
    return fallback if value is None else value


def _join(texts: list[str]) -> str:
    return '\n\n'.join(found for found in texts if found != '')
