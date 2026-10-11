from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any

import pytest
from support import FakeJev, json_equal

from context_atlas import (
    Collection,
    ContextAtlasError,
    Document,
    FileStore,
    Link,
    Processor,
    Unit,
    parse_markdown,
)
from context_atlas.index import search

SPEND = 'Supplier,Code,Amount\nNetwork Rail,A1,"£2,000"\nCafé Ltd,B2,£30\n'


def answered(answers: dict[str, Any]) -> dict[str, Any]:
    return {'model': 'jev-latest', 'answers': answers, 'usage': {'input_tokens': 1, 'output_tokens': 1}}


def test_units_and_links_round_trip_without_empty_fields() -> None:
    assert Unit('p1', 'passage', 'Text.').to_dict() == {'id': 'p1', 'kind': 'passage', 'text': 'Text.'}
    assert Unit.from_dict({'id': 't1', 'kind': 'table', 'text': 'x', 'path': ['S'], 'meta': []}).to_dict() == {
        'id': 't1',
        'kind': 'table',
        'text': 'x',
        'path': ['S'],
    }
    assert Link.from_dict({'type': 'defined_by', 'from': 'p3', 'to': 'p1'}).to_dict() == {
        'type': 'defined_by',
        'from': 'p3',
        'to': 'p1',
    }


def test_a_stored_document_reads_numbers_as_floats_and_empty_lists_as_maps() -> None:
    stored = Processor().ingest_csv(SPEND, 'spend', 'Spend').to_dict()
    table = stored['data']['tables'][0]
    table['rows'][0]['values']['Amount'] = 2000
    table['columns'][2]['min'] = 30
    table['row_notes'] = []

    read = Document.from_dict(json.loads(json.dumps(stored)))
    again = read.data['tables'][0]

    assert isinstance(again['rows'][0]['values']['Amount'], float)
    assert again['columns'][2]['name'] == 'Amount'
    assert isinstance(again['columns'][2]['min'], float)
    assert again['row_notes'] == {}
    with pytest.raises(ContextAtlasError, match=r'Not a format 2 document\.'):
        Document.from_dict({**stored, 'format': 1})


def test_search_ranks_exact_terms_across_documents() -> None:
    processor = Processor()
    guide = processor.ingest_blocks(parse_markdown('# Café\n\nOpening hours.'), 'guide')
    collection = Collection([processor.ingest_csv(SPEND, 'spend', 'Spend'), guide])

    hits = search(collection, 'café ltd', 5)

    assert [(hit['document'], hit['unit']) for hit in hits] == [('spend', 't1'), ('guide', 'p1')]
    assert search(collection, 'café', 5, kinds=['passage'])[0]['unit'] == 'p1'
    assert collection.stats()['units'] == 2


def test_file_store_keeps_one_file_per_document(tmp_path: Path) -> None:
    store = FileStore(tmp_path / 'store')
    document = Processor().ingest_csv(SPEND, 'spend/2026', 'Spend')

    store.put(document)

    path = tmp_path / 'store' / f'{hashlib.sha256(b"spend/2026").hexdigest()}.json'
    assert path.is_file()
    assert json_equal(store.document('spend/2026').to_dict(), document.to_dict())
    assert store.postings(['rail'])['rail'][0]['unit'] == 't1'
    store.remove('spend/2026')
    assert store.document('spend/2026') is None


def test_ingest_spreadsheet_names_sheets_and_refuses_other_files(tmp_path: Path) -> None:
    (tmp_path / 'spend.csv').write_text(SPEND, encoding='utf-8')
    (tmp_path / 'lookup.csv').write_text('Code,Kind\nA1,Rail\nB2,Rail\nC3,Food\n', encoding='utf-8')
    (tmp_path / 'notes.pdf').write_bytes(b'%PDF')
    processor = Processor()

    single = processor.ingest_spreadsheet(tmp_path / 'spend.csv', 'spend')
    joined = processor.ingest_spreadsheet({'Spend': tmp_path / 'spend.csv', 'Lookup': tmp_path / 'lookup.csv'}, 'both')

    assert single.units[0].path == ['spend']
    assert single.data['tables'][0]['title'] == 'spend'
    assert joined.data['tables'][0]['joined'] == ['Kind joined from "Spend: Lookup" on Code']
    with pytest.raises(ContextAtlasError, match=r'Cannot read \.pdf as a spreadsheet'):
        processor.ingest_spreadsheet(tmp_path / 'notes.pdf', 'notes')


def test_retrieve_needs_jev() -> None:
    with pytest.raises(ContextAtlasError, match='Retrieval needs Jev'):
        Processor().retrieve('q', Collection())


def test_retrieve_gathers_passages_and_fuses_nearest_hits() -> None:
    markdown = '# Returns\n\nReturns take 30 days.\n\n# Hours\n\nOpen at nine.'
    guide = Processor().ingest_blocks(parse_markdown(markdown), 'guide')
    scores = {
        'c_p0': {'type': 'noul', 'noul': 0.9},
        'c_p1': {'type': 'noul', 'noul': 0.1},
        'best': {'type': 'choice', 'choice': 'p0', 'confidence': 0.9, 'probabilities': {}},
    }
    fake = FakeJev([answered(scores)])
    seen: list[tuple[str, int, Any]] = []

    def nearest(query: str, limit: int, kinds: list[str] | None) -> list[dict[str, str]]:
        seen.append((query, limit, kinds))
        return [{'document': 'guide', 'unit': 'p2'}]

    context = Processor(fake.client).retrieve('How long do returns take?', Collection([guide]), nearest)

    assert seen == [('How long do returns take?', 20, None)]
    assert list(fake.requests[0]['state']['the passages the search found']) == ['p0', 'p1']
    assert context is not None
    assert context.to_dict() == {
        'text': '[Returns]\n\nReturns take 30 days.',
        'sources': [{'document': 'guide', 'unit': 'p1'}],
        'abstained': False,
        'trace': {'profile': 'guidance', 'scope': 'one passage', 'scores': {'p0': 0.9, 'p1': 0.1}},
    }


def test_retrieve_abstains_with_no_candidates() -> None:
    context = Processor(FakeJev([]).client).retrieve('nothing matches', Collection())

    assert context is not None
    assert context.to_dict() == {'text': '', 'sources': [], 'abstained': True, 'trace': {'reason': 'no candidates'}}
