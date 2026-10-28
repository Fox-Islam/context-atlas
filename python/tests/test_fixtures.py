from __future__ import annotations

import json
from typing import Any

import pytest
from support import ROOT, FakeJev, fixture_cases, json_equal, spreadsheet

from context_atlas import (
    Block,
    Chunker,
    Collection,
    Config,
    ContextAtlasError,
    Document,
    Passage,
    Processor,
    Reranker,
    parse_blocks,
    parse_markdown,
    parse_text,
)
from context_atlas.parsing import docx, formats

CASES = fixture_cases()


def run(case: dict[str, Any], fake: FakeJev) -> Any:
    if case['kind'] in ('config', 'blocks'):
        return parsed(case)
    if case['kind'] == 'ingest':
        return spreadsheet(case['input']).to_dict()
    if case['kind'] == 'prose-ingest':
        return prose(case['input'], fake if case.get('jev', True) else None).to_dict()
    if case['kind'] == 'prose-append':
        return prose_append(case['input'], fake).to_dict()
    if case['kind'] == 'read':
        return read(case['input'])
    if case['kind'] == 'markup-ingest':
        return markup(case['input']).to_dict()
    if case['kind'] == 'prose-retrieve':
        collection = Collection(
            markup(document) if 'markup' in document else prose(document, fake) for document in case['documents']
        )
        # A vector search that returns the case's hits, best first, whatever the query.
        hits = case.get('nearest')
        nearest = None if hits is None else (lambda query, limit, kinds, documents: hits)
        processor = Processor(fake.client, Config.from_dict(case.get('config') or {}))
        context = processor.retrieve(case['query'], collection, nearest=nearest, documents=case.get('scope'))
        return None if context is None else context.to_dict()
    if case['kind'] == 'retrieve':
        collection = Collection(spreadsheet(document) for document in case['documents'])
        context = Processor(fake.client).retrieve(case['query'], collection, documents=case.get('scope'))
        return None if context is None else context.to_dict()

    config = Config.from_dict(case.get('config') or {})

    if case['kind'] == 'rank':
        ranked = Reranker(fake.client, config).rank(
            [Passage.from_dict(passage) for passage in case['passages']],
            case['query'],
            case.get('limit'),
        )
        return None if ranked is None else [item.to_dict() for item in ranked]

    if case['format'] == 'markdown':
        blocks = parse_markdown(case['input'])
    elif case['format'] == 'text':
        blocks = parse_text(case['input'])
    else:
        blocks = [Block.from_dict(block) for block in case['input']]

    return Chunker(fake.client if case.get('jev', True) else None, config).chunk(blocks).to_dict()


@pytest.mark.parametrize('case', CASES, ids=[case['name'] for case in CASES])
def test_fixture_case(case: dict[str, Any]) -> None:
    responses = [None if case.get('fail') else call['response'] for call in case.get('calls', [])]
    fake = FakeJev(responses)

    output = run(case, fake)

    sent = [{'state': body['state'], 'questions': body['questions']} for body in fake.requests]
    recorded = [call['request'] for call in case.get('calls', [])]
    assert json_equal(sent, recorded)
    assert [_order(body) for body in sent] == [_order(body) for body in recorded]
    assert json_equal(output, case['expected'])
    if case['kind'] == 'config':
        assert json.dumps(output) == json.dumps(case['expected'])



def parsed(case: dict[str, Any]) -> dict[str, Any]:
    try:
        if case['kind'] == 'config':
            return {'values': Config.from_dict(case['input']).to_dict()}
        return {'blocks': [block.to_dict() for block in parse_blocks(case['input'])]}
    except ContextAtlasError as error:
        return {'error': str(error)}


def test_every_fixture_kind_is_run() -> None:
    assert {case['kind'] for case in CASES} == {
        'chunk', 'rank', 'config', 'blocks', 'ingest', 'retrieve',
        'prose-ingest', 'prose-retrieve', 'prose-append', 'markup-ingest', 'read',
    }


def prose(source: dict[str, Any], fake: FakeJev | None) -> Document:
    """A fixture's prose input, ingested with its own config through the fake when there is one."""
    processor = Processor(fake.client if fake is not None else None, Config.from_dict(source.get('config') or {}))

    blocks = [Block.from_dict(block) for block in source['blocks']]

    return processor.ingest_blocks(blocks, source['id'], source.get('profile'))


def prose_append(source: dict[str, Any], fake: FakeJev) -> Document:
    """A fixture's prose input ingested through the fake, then each of its `append` block lists added in turn."""
    processor = Processor(fake.client, Config.from_dict(source.get('config') or {}))
    document = prose(source, fake)
    for blocks in source['append']:
        document = processor.append(document, [Block.from_dict(block) for block in blocks], source.get('profile'))

    return document


def _order(value: Any) -> Any:
    """The key order of every map in a request, so requests match in the recorded order and not only as JSON."""
    if isinstance(value, dict):
        return [(key, _order(item)) for key, item in value.items()]
    if isinstance(value, list):
        return [_order(item) for item in value]

    return None


def markup(source: dict[str, Any]) -> Document:
    """A fixture's markup input, ingested without Jev."""
    return Processor().ingest_markup(source['markup'], source['id'], source['format'], source.get('title'))


def read(source: dict[str, Any]) -> dict[str, Any]:
    """A document format read into blocks, or the error every package gives."""
    try:
        if 'file' in source:
            found = docx.parse(ROOT / source['file'])
        else:
            found = formats.blocks(source.get('text', ''), source['format'])
        return {'blocks': [block.to_dict() for block in found]}
    except ContextAtlasError as error:
        return {'error': str(error)}
