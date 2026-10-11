from __future__ import annotations

import hashlib
import json
from pathlib import Path

from context_atlas import Collection, FileStore, Processor, parse_markdown
from context_atlas.index import search


def guide(document_id: str, body: str):
    return Processor().ingest_blocks(parse_markdown(body), document_id)


def shard(term: str) -> str:
    return hashlib.sha256(term.encode()).hexdigest()[:3]


def test_put_writes_a_versioned_term_index(tmp_path: Path) -> None:
    store = FileStore(tmp_path)
    store.put(guide('café', '# Fees\n\nInvoices are monthly.'))
    store.put(guide('café', '# Fees\n\nInvoices are weekly.'))

    digest = hashlib.sha256('café'.encode()).hexdigest()
    stats = json.loads((tmp_path / 'index' / 'stats.json').read_text(encoding='utf-8'))
    assert stats == {digest: ['café', 1, 5, 2]}
    lines = (tmp_path / 'index' / f'{shard("invoices")}.tsv').read_text(encoding='utf-8').splitlines()
    assert [line for line in lines if line.startswith('invoices\t')] == [
        f'invoices\t{digest[:16]}\t1\tp1:1:5',
        f'invoices\t{digest[:16]}\t2\tp1:1:5',
    ]
    assert [p['document'] for p in store.postings(['weekly'])['weekly']] == ['café']
    assert store.postings(['monthly']) == {'monthly': []}


def test_postings_match_a_collection_and_survive_remove_and_compact(tmp_path: Path) -> None:
    documents = [guide(f'doc-{i}', f'# Part {i}\n\nShared words and word{i}.') for i in range(5)]
    store = FileStore(tmp_path)
    for document in documents:
        store.put(document)
    store.remove('doc-2')
    kept = [d for d in documents if d.id != 'doc-2']

    reopened = FileStore(tmp_path)
    reopened.compact()
    expected = Collection(kept).postings(['shared', 'word3'])
    by_hash = {d.id: hashlib.sha256(d.id.encode()).hexdigest() for d in kept}
    for postings in expected.values():
        postings.sort(key=lambda p: by_hash[p['document']])

    assert reopened.postings(['shared', 'word3']) == expected
    assert reopened.stats() == Collection(kept).stats()
    assert search(reopened, 'shared word3', 3, documents=['doc-4'])[0]['document'] == 'doc-4'


def test_a_store_without_an_index_is_indexed_on_first_use(tmp_path: Path) -> None:
    store = FileStore(tmp_path)
    store.put(guide('a', 'Alpha text.'))
    for path in (tmp_path / 'index').iterdir():
        path.unlink()
    (tmp_path / 'index').rmdir()

    assert FileStore(tmp_path).stats() == {'units': 1, 'length': 2}
    assert (tmp_path / 'index' / 'stats.json').is_file()
