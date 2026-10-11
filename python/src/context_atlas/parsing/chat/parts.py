"""
Message content in any vendor's shape read into message parts: typed content blocks (OpenAI,
Anthropic, Mistral, Cohere, LangChain), keyed blocks (Gemini, Bedrock), ChatGPT export content,
and tool results. The rules are stated in spec/formats/chat.md.

A part is a tuple: `('text', text)`, `('reasoning', text)`, `('media', placeholder)`,
`('call', name, arguments, id)` or `('result', text, call id, tool name, error)`.
"""
from __future__ import annotations

from typing import Any

from . import chat_json
from .chat_json import JsonMap, JsonNumber

Part = tuple[Any, ...]

_TEXT_TYPES = ('text', 'input_text', 'output_text')

_IMAGE_TYPES = ('image', 'image_url', 'input_image', 'image_file')

_CALL_TYPES = ('tool_use', 'server_tool_use', 'mcp_tool_use')


def of(content: Any) -> list[Part]:
    if isinstance(content, str):
        return [('text', content)]
    if isinstance(content, JsonMap):
        return _chatgpt(content) if content.string('content_type') is not None else _part(content)
    parts: list[Part] = []
    for item in content if isinstance(content, list) else []:
        if isinstance(item, str):
            parts.append(('text', item))
        elif isinstance(item, JsonMap):
            parts.extend(_part(item))

    return parts


def result_text(content: Any) -> str:
    """
    A tool result's content as text: a string as given; a list item by item (text, a media
    placeholder, a Cohere document's data, a Bedrock json member, else compact JSON) joined by a
    blank line; anything else as compact JSON.
    """
    if isinstance(content, str):
        return content
    if content is None:
        return ''
    if not isinstance(content, list):
        return chat_json.encode(content)

    return _join([_result_item(item) for item in content])


def arguments(value: Any) -> str:
    """
    Call arguments as text: a string that is one JSON value re-serialised compactly, any other
    string as given; a missing value empty; anything else compact JSON.
    """
    if value is None:
        return ''
    if not isinstance(value, str):
        return chat_json.encode(value)

    return chat_json.encode(chat_json.decode(value)) if chat_json.valid(value) else value


def file(name: str | None) -> str:
    """The placeholder for an attached file: `[file: <name>]`, or `[file]` with no name."""
    return '[file]' if name is None else f'[file: {name}]'


def first(found: JsonMap, keys: list[str]) -> str | None:
    """The first non-empty string among the members, or None."""
    for key in keys:
        value = found.string(key)
        if value is not None:
            return value

    return None


def call(name: Any, value: Any, call_id: Any) -> list[Part]:
    return [('call', name, arguments(value), id_of(call_id))] if isinstance(name, str) and name != '' else []


def result(content: Any, call_id: Any, name: Any, error: bool) -> list[Part]:
    tool = name if isinstance(name, str) and name != '' else None

    return [('result', result_text(content), id_of(call_id), tool, error)]


def id_of(value: Any) -> str | None:
    """An id: a non-empty string, or a number as written."""
    if isinstance(value, JsonNumber):
        return value.raw

    return value if isinstance(value, str) and value != '' else None


def _part(part: JsonMap) -> list[Part]:
    kind = part.string('type')
    if kind is not None:
        return _typed(part, kind)
    content_type = part.string('content_type')
    if content_type is not None:
        return _pointer(part, content_type)

    return _keyed(part)


def _typed(part: JsonMap, kind: str) -> list[Part]:
    if kind in _TEXT_TYPES:
        return _text(part.get('text'))
    if kind == 'refusal':
        return _text(part.get('refusal'))
    if kind == 'thinking':
        thinking = part.get('thinking')
        return _reasoning(thinking if isinstance(thinking, str) else _joined_text(thinking))
    if kind == 'reasoning':
        return _reasoning(first(part, ['reasoning', 'text']) or '')
    if kind in _IMAGE_TYPES:
        return [('media', '[image]')]
    if kind in ('input_audio', 'audio'):
        return [('media', '[audio]')]
    if kind == 'video':
        return [('media', '[video]')]
    if kind in ('file', 'input_file'):
        return [('media', file(_file_name(part)))]
    if kind == 'document':
        document = part.map('document')
        if document is not None:
            return _text(_document(document))
        return [('media', file(first(part, ['title', 'name', 'filename'])))]
    if kind in _CALL_TYPES:
        return call(part.get('name'), part.get('input'), part.get('id'))
    if kind == 'tool_result' or kind.endswith('_tool_result'):
        return result(part.get('content'), part.get('tool_use_id'), part.get('name'), part.get('is_error') is True)

    return []


def _pointer(part: JsonMap, content_type: str) -> list[Part]:
    """A ChatGPT export part named by `content_type` inside multimodal content."""
    if content_type == 'image_asset_pointer':
        return [('media', '[image]')]
    if content_type in ('audio_asset_pointer', 'real_time_user_audio_video_asset_pointer'):
        return [('media', '[audio]')]
    if content_type == 'audio_transcription':
        return _text(part.get('text'))

    return []


def _keyed(part: JsonMap) -> list[Part]:
    """A block known by its member names (Gemini parts, Bedrock content blocks)."""
    if isinstance(part.get('text'), str):
        return _reasoning(part.get('text')) if part.get('thought') is True else _text(part.get('text'))
    found = part.map('functionCall') or part.map('function_call')
    if found is not None:
        return call(found.get('name'), found.get('args'), found.get('id'))
    found = part.map('functionResponse') or part.map('function_response')
    if found is not None:
        return result(found.get('response'), found.get('id'), found.get('name'), False)
    found = part.map('toolUse')
    if found is not None:
        return call(found.get('name'), found.get('input'), found.get('toolUseId'))
    found = part.map('toolCall')
    if found is not None:
        name = found.get('toolName')
        return call(name if name is not None else found.get('toolType'), found.get('args'), found.get('id'))
    found = part.map('toolResponse')
    if found is not None:
        return result(found.get('response'), found.get('id'), None, False)
    found = part.map('toolResult')
    if found is not None:
        return result(found.get('content'), found.get('toolUseId'), None, found.get('status') == 'error')

    return _keyed_rest(part)


def _keyed_rest(part: JsonMap) -> list[Part]:
    content = part.map('reasoningContent')
    reasoning = content.map('reasoningText') if content is not None else None
    if reasoning is not None:
        return _reasoning(reasoning.string('text') or '')
    data = part.map('inlineData') or part.map('inline_data') or part.map('fileData') or part.map('file_data')
    if data is not None:
        return [('media', _mime(data))]
    code = part.map('executableCode') or part.map('executable_code')
    if code is not None:
        return call('code_execution', code.string('code') or '', None)
    outcome = part.map('codeExecutionResult') or part.map('code_execution_result')
    if outcome is not None:
        ok = outcome.get('outcome') is None or outcome.get('outcome') == 'OUTCOME_OK'
        return result(outcome.get('output'), None, 'code_execution', not ok)
    if part.map('image') is not None:
        return [('media', '[image]')]
    if part.map('video') is not None:
        return [('media', '[video]')]
    document = part.map('document')
    if document is not None:
        return [('media', file(document.string('name')))]

    return []


def _chatgpt(content: JsonMap) -> list[Part]:
    """ChatGPT export content (an object with `content_type`)."""
    kind = content.string('content_type') or ''
    if kind == 'thoughts':
        texts = [
            (thought.string('content') or '') if isinstance(thought, JsonMap) else ''
            for thought in content.list('thoughts') or []
        ]
        return _reasoning(_join(texts))
    if kind in ('reasoning_recap', 'user_editable_context', 'model_editable_context'):
        return []
    if kind == 'tether_browsing_display':
        return _text(content.get('result'))
    found = content.list('parts')
    if found is not None:
        return of(found)

    return _text(content.get('text'))


def _result_item(item: Any) -> str:
    if isinstance(item, str):
        return item
    if not isinstance(item, JsonMap):
        return chat_json.encode(item)
    parts = _part(item)
    if len(parts) == 1 and parts[0][0] in ('text', 'media', 'result'):
        return str(parts[0][1])
    if item.has('json') and len(item.members) == 1:
        return chat_json.encode(item.get('json'))

    return chat_json.encode(item)


def _document(document: JsonMap) -> str:
    data = document.get('data')

    return data if isinstance(data, str) else chat_json.encode(data)


def _file_name(part: JsonMap) -> str | None:
    found = first(part, ['filename', 'file_name', 'name'])
    if found is not None:
        return found
    inner = part.map('file')

    return first(inner, ['filename', 'file_name', 'name']) if inner is not None else None


def _mime(data: JsonMap) -> str:
    mime = (first(data, ['mimeType', 'mime_type']) or '').lower()
    if mime.startswith('image/'):
        return '[image]'
    if mime.startswith('audio/'):
        return '[audio]'
    if mime.startswith('video/'):
        return '[video]'

    return file(first(data, ['displayName', 'display_name']))


def _joined_text(chunks: Any) -> str:
    """The texts of a list of text chunks (Mistral thinking), joined by a blank line."""
    return _join([part[1] if part[0] == 'text' else '' for part in of(chunks)])


def _join(texts: list[str]) -> str:
    return '\n\n'.join(found for found in texts if found != '')


def _text(value: Any) -> list[Part]:
    return [('text', value)] if isinstance(value, str) else []


def _reasoning(value: str) -> list[Part]:
    return [('reasoning', value)]
