from __future__ import annotations

import json

import pytest

from context_atlas import ContextAtlasError
from context_atlas.parsing import chat, formats
from context_atlas.parsing.chat import chat_json
from context_atlas.parsing.chat.chat_json import JsonMap, JsonNumber


def test_chat_is_a_document_format_named_only_by_the_caller() -> None:
    assert 'chat' in formats.DOCUMENT
    assert 'chat' not in formats.EXTENSIONS.values()
    assert formats.of('session.json') == 'json'
    assert formats.of('session.jsonl') == 'jsonl'
    assert formats.blocks('[{"role":"user","content":"Hi"}]', 'chat')[0].text == 'Hi'


def test_a_long_string_is_read_whole() -> None:
    long = 'word "quoted" \\ ' * 400000
    blocks = chat.parse(json.dumps([{'role': 'tool', 'tool_call_id': 'c', 'content': long}]))

    assert blocks[0].text == long.strip()


def test_nesting_deeper_than_512_is_invalid() -> None:
    assert chat_json.valid('[' * 512 + ']' * 512)

    with pytest.raises(ContextAtlasError, match=r'^Invalid JSON at byte 512\.$'):
        chat_json.decode('[' * 513 + ']' * 513)


@pytest.mark.parametrize(
    ('source', 'byte'),
    [('["a\\x"]', 1), ('["a\nb"]', 1), ('{"k": "\\u12"}', 6), ('{1: 2}', 1), ('[1,]', 3), ('', 0), ('[] x', 3)],
)
def test_invalid_json_fails_at_the_byte_reading_stopped(source: str, byte: int) -> None:
    with pytest.raises(ContextAtlasError) as error:
        chat_json.decode(source)

    assert str(error.value) == f'Invalid JSON at byte {byte}.'


def test_error_offsets_count_utf8_bytes() -> None:
    with pytest.raises(ContextAtlasError, match=r'^Invalid JSON at byte 6\.$'):
        chat_json.decode('["é" x]')


def test_compact_json_keeps_order_and_numbers_and_escapes_only_what_it_must() -> None:
    value = chat_json.decode('{"b": -0.10e5, "a": ["é/\u2028", "\\u0001\\t", true, null], "signature": "x", "b": 2}')

    assert isinstance(value, JsonMap)
    assert value.get('b') == JsonNumber('2')
    assert chat_json.encode(value) == '{"b":2,"a":["é/\u2028","\\u0001\\t",true,null]}'


def test_a_byte_order_mark_and_crlf_lines_are_accepted() -> None:
    blocks = chat.parse('\ufeff{"role":"user","content":"a"}\r\n{"role":"assistant","content":"b"}\r\n')

    assert [block.text for block in blocks] == ['a', 'b']


def test_a_scalar_is_not_a_transcript() -> None:
    with pytest.raises(ContextAtlasError) as error:
        chat.parse('"hello"')

    assert str(error.value) == chat.UNRECOGNISED
