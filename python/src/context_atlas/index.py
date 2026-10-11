"""
Exact-term search over ingested documents, and the sources retrieval reads them from.

Embeddings miss identifiers, names and codes a reader types exactly; BM25 over per-unit term
counts finds them. Terms are runs of two or more Unicode letters or digits, lowercased, with no
stemming, so the PHP, JavaScript and Python packages index identically.
"""
from __future__ import annotations

import contextlib
import hashlib
import json
import math
import time
import unicodedata
from collections.abc import Iterable, Iterator
from contextlib import contextmanager
from pathlib import Path
from typing import Any, Protocol, TypedDict

from . import compat
from .document import Document, Unit
from .errors import ContextAtlasError

K1 = 1.2

# Seconds a writer waits for the lock, and after which an untouched lock is taken to be stale.
LOCK_WAIT = 30

LOCK_STALE = 120

B = 0.75


class Posting(TypedDict):
    document: str
    unit: str
    count: int
    length: int


class Stats(TypedDict):
    units: int
    length: int


class Hit(TypedDict):
    document: str
    unit: str
    score: float


class Source(Protocol):
    """
    Where retrieval reads ingested documents from. Collection holds them in memory; a caller with
    a large collection implements this over its own store, reading only the terms and documents a
    query touches.
    """

    def postings(self, terms: list[str]) -> dict[str, list[Posting]]:
        """Per term, every unit containing it, with its term count and length."""
        ...

    def stats(self) -> Stats:
        """How many units the source holds, and their total length in terms."""
        ...

    def document(self, document_id: str) -> Document | None: ...


def terms(text: str) -> list[str]:
    """Runs of two or more letters or digits (categories L and N) in the lowercased text."""
    found: list[str] = []
    run: list[str] = []

    for char in text.lower() + ' ':
        if unicodedata.category(char)[0] in 'LN':
            run.append(char)
            continue
        if len(run) >= 2:
            found.append(''.join(run))
        run = []

    return found


def build(units: list[Unit], extra: dict[str, str] | None = None) -> dict[str, dict[str, Any]]:
    """
    Term counts per unit from its text, its path and any `extra` text indexed but not embedded,
    such as every value of a table's label columns; terms in byte order.
    """
    extra = extra or {}
    length: dict[str, int] = {}
    postings: dict[str, dict[str, int]] = {}

    for unit in units:
        counts: dict[str, int] = {}
        for term in terms(f'{unit.text} {" ".join(unit.path)} {extra.get(unit.id, "")}'):
            counts[term] = counts.get(term, 0) + 1
        length[unit.id] = sum(counts.values())
        for term, count in counts.items():
            postings.setdefault(term, {})[unit.id] = count

    return {'length': length, 'terms': dict(sorted(postings.items(), key=lambda item: item[0].encode()))}


def search(
    source: Source, query: str, limit: int, kinds: list[str] | None = None, documents: list[str] | None = None
) -> list[Hit]:
    """
    Units ranked by BM25 against the query, best first; ties keep the order their first posting
    was met. With `documents`, only those documents' postings are scored; term weights still come
    from the whole source.
    """
    wanted = list(dict.fromkeys(terms(query)))
    stats = source.stats()

    if not wanted or stats['units'] == 0:
        return []

    average = stats['length'] / stats['units']
    scores: dict[tuple[str, str], float] = {}
    only = None if documents is None else set(documents)

    for postings in source.postings(wanted).values():
        idf = math.log(1 + (stats['units'] - len(postings) + 0.5) / (len(postings) + 0.5))
        for posting in postings:
            if only is not None and posting['document'] not in only:
                continue
            found = (posting['document'], posting['unit'])
            norm = posting['count'] + K1 * (1 - B + B * posting['length'] / max(average, 1.0))
            scores[found] = scores.get(found, 0.0) + idf * posting['count'] * (K1 + 1) / norm

    hits: list[Hit] = []
    for (document_id, unit_id), score in sorted(scores.items(), key=lambda item: -item[1]):
        if kinds is not None and _kind(source, document_id, unit_id) not in kinds:
            continue
        hits.append({'document': document_id, 'unit': unit_id, 'score': score})
        if len(hits) == limit:
            break

    return hits


class Collection:
    """Documents held in memory, in the order they were first put."""

    def __init__(self, documents: Iterable[Document] = ()) -> None:
        self._documents: dict[str, Document] = {}
        for document in documents:
            self.put(document)

    @staticmethod
    def from_list(documents: list[dict[str, Any]]) -> Collection:
        """A collection of stored documents, as `Document.to_dict` wrote them."""
        return Collection(Document.from_dict(document) for document in documents)

    def put(self, document: Document) -> None:
        self._documents[document.id] = document

    def remove(self, document_id: str) -> None:
        self._documents.pop(document_id, None)

    def document(self, document_id: str) -> Document | None:
        return self._documents.get(document_id)

    def documents(self) -> list[Document]:
        return list(self._documents.values())

    def postings(self, terms: list[str]) -> dict[str, list[Posting]]:
        found: dict[str, list[Posting]] = {}

        for term in terms:
            found[term] = []
            for document in self._documents.values():
                for unit_id, count in (document.lexical['terms'].get(term) or {}).items():
                    length = int(document.lexical['length'].get(unit_id, 0))
                    found[term].append(
                        {'document': document.id, 'unit': str(unit_id), 'count': int(count), 'length': length}
                    )

        return found

    def stats(self) -> Stats:
        units = 0
        length = 0

        for document in self._documents.values():
            units += len(document.lexical['length'])
            length += sum(int(value) for value in document.lexical['length'].values())

        return {'units': units, 'length': length}


class FileStore:
    """
    One JSON file per document in a directory the caller chooses, with an append-only term index
    beside them, so a query reads only the index shards of its terms and the documents it
    returns, and a write appends to the shards its document's terms fall in.

    Layout, shared with the PHP and JavaScript packages: `<sha256(id)>.json` per document;
    `index/stats.json`, `{document hash: [id, units, length, version]}`; `index/<hhh>.tsv`, one
    line per term and document version for every term whose SHA-256 starts with the three hex
    digits `hhh`: `term TAB first 16 hex digits of the document hash TAB version TAB
    unit:count:length,...`. A line counts only while its version is the document's version in
    `stats.json`, so a rewritten or removed document leaves dead lines behind; `compact()` drops
    them. Postings are read in document-hash order. A store written without an index is indexed
    on first use. Writes hold the store by creating `index/.lock.d`.
    """

    def __init__(self, directory: str | Path) -> None:
        self._directory = Path(directory)
        self._read: dict[str, Document] = {}
        self._stats: dict[str, list[Any]] = {}
        self._indexed = False

    def put(self, document: Document) -> None:
        """:raises ContextAtlasError: when the index directory cannot be created or locked"""
        with self._locked():
            self._load_or_build()
            self._path(document.id).write_text(_json(document.to_dict()), encoding='utf-8')
            digest = _sha256(document.id)
            version = (self._stats[digest][3] if digest in self._stats else 0) + 1
            for shard, lines in _lines([document], {digest: version}).items():
                with self._shard_path(shard).open('a', encoding='utf-8', newline='') as handle:
                    handle.write(lines)
            self._stats[digest] = [document.id, *_size(document), version]
            self._write_stats()
            self._read.pop(document.id, None)

    def remove(self, document_id: str) -> None:
        """:raises ContextAtlasError: when the index directory cannot be created or locked"""
        with self._locked():
            self._load_or_build()
            self._stats.pop(_sha256(document_id), None)
            self._read.pop(document_id, None)
            self._write_stats()
            self._path(document_id).unlink(missing_ok=True)

    def compact(self) -> None:
        """
        Rewrites the index without the lines of rewritten or removed documents.

        :raises ContextAtlasError: when the index directory cannot be created or locked
        """
        with self._locked():
            self._load_or_build()
            for path in sorted(self._index_dir().glob('*.tsv')):
                by_key = self._by_key()
                kept = ''
                for line in _shard_lines(path):
                    fields = line.split('\t', 3)
                    digest = by_key.get(fields[1]) if len(fields) > 1 else None
                    if len(fields) == 4 and digest is not None and self._stats[digest][3] == _php_int(fields[2]):
                        kept += line + '\n'
                _write_file(path, kept)

    def document(self, document_id: str) -> Document | None:
        if document_id in self._read:
            return self._read[document_id]
        path = self._path(document_id)
        if not path.is_file():
            return None
        self._read[document_id] = _read(path)

        return self._read[document_id]

    def postings(self, terms: list[str]) -> dict[str, list[Posting]]:
        self._ensure_index()
        wanted: dict[str, dict[str, str]] = {term: {} for term in terms}
        by_key = self._by_key()
        by_shard: dict[str, set[str]] = {}
        for term in terms:
            by_shard.setdefault(_sha256(term)[:SHARD], set()).add(term)
        for shard, shard_terms in by_shard.items():
            for line in _shard_lines(self._shard_path(shard)):
                fields = line.split('\t', 3)
                digest = by_key.get(fields[1]) if len(fields) > 1 else None
                if len(fields) != 4 or fields[0] not in shard_terms or digest is None:
                    continue
                if self._stats[digest][3] != _php_int(fields[2]):
                    continue
                wanted[fields[0]][digest] = fields[3]

        found: dict[str, list[Posting]] = {}
        for term, by_document in wanted.items():
            found[term] = []
            for digest in sorted(by_document):
                for posting in by_document[digest].split(','):
                    unit_id, count, length = posting.split(':')[:3]
                    found[term].append({
                        'document': self._stats[digest][0],
                        'unit': unit_id,
                        'count': _php_int(count),
                        'length': _php_int(length),
                    })

        return found

    def stats(self) -> Stats:
        self._ensure_index()

        return {
            'units': sum(entry[1] for entry in self._stats.values()),
            'length': sum(entry[2] for entry in self._stats.values()),
        }

    def _ensure_index(self) -> None:
        if self._indexed:
            return
        if self._stats_path().is_file():
            self._load_stats()
        else:
            with self._locked():
                self._load_or_build()
        self._indexed = True

    def _load_or_build(self) -> None:
        """Loads the index, or builds it in one pass from the stored documents."""
        if self._stats_path().is_file():
            self._load_stats()
            return
        documents = [_read(path) for path in sorted(self._directory.glob('*.json'))]
        self._stats = {}
        versions = {}
        for document in documents:
            digest = _sha256(document.id)
            versions[digest] = 1
            self._stats[digest] = [document.id, *_size(document), 1]
        for shard, lines in _lines(documents, versions).items():
            _write_file(self._shard_path(shard), lines)
        self._write_stats()
        self._indexed = True

    def _load_stats(self) -> None:
        loaded = json.loads(self._stats_path().read_text(encoding='utf-8'))
        entries = loaded.values() if isinstance(loaded, dict) else loaded
        keys = list(loaded) if isinstance(loaded, dict) else [str(i) for i in range(len(loaded))]
        self._stats = {
            key: [compat.to_str(entry[0]), int(entry[1]), int(entry[2]), int(entry[3])]
            for key, entry in zip(keys, entries, strict=True)
        }

    def _write_stats(self) -> None:
        _write_file(self._stats_path(), _json(self._stats))

    def _by_key(self) -> dict[str, str]:
        return {digest[:KEY]: digest for digest in self._stats}

    @contextmanager
    def _locked(self) -> Iterator[None]:
        """
        Holds the store by creating `index/.lock.d`: an atomic mkdir that PHP, JavaScript and
        Python processes all honour. Retries every 20 ms for 30 s; a lock directory untouched for
        120 s is taken to be left by a dead writer and removed.
        """
        directory = self._index_dir()
        try:
            directory.mkdir(parents=True, exist_ok=True)
        except OSError as error:
            raise ContextAtlasError(f'Cannot create {directory}.') from error
        lock = directory / '.lock.d'
        deadline = time.monotonic() + LOCK_WAIT
        while True:
            try:
                lock.mkdir()
                break
            except FileExistsError:
                pass
            try:
                stale = lock.stat().st_mtime < time.time() - LOCK_STALE
            except OSError:
                stale = False
            if stale:
                with contextlib.suppress(OSError):
                    lock.rmdir()
                continue
            if time.monotonic() > deadline:
                raise ContextAtlasError(f'Cannot lock {directory}: another writer holds {lock}.')
            time.sleep(0.02)
        try:
            yield
        finally:
            with contextlib.suppress(OSError):
                lock.rmdir()

    def _shard_path(self, shard: str) -> Path:
        return self._index_dir() / f'{shard}.tsv'

    def _stats_path(self) -> Path:
        return self._index_dir() / 'stats.json'

    def _index_dir(self) -> Path:
        return self._directory / 'index'

    def _path(self, document_id: str) -> Path:
        """File names are the document id hashed, so any id is safe on any file system."""
        return self._directory / f'{_sha256(document_id)}.json'


SHARD = 3

KEY = 16


def _lines(documents: list[Document], versions: dict[str, int]) -> dict[str, str]:
    """Index lines by shard for these documents, shards in the order their first term appears."""
    found: dict[str, list[str]] = {}
    for document in documents:
        digest = _sha256(document.id)
        lengths = document.lexical['length']
        for term, units in document.lexical['terms'].items():
            postings = ','.join(
                f'{unit}:{_php_int(count)}:{_php_int(lengths.get(unit, 0))}' for unit, count in units.items()
            )
            line = f'{term}\t{digest[:KEY]}\t{versions[digest]}\t{postings}\n'
            found.setdefault(_sha256(str(term))[:SHARD], []).append(line)

    return {shard: ''.join(lines) for shard, lines in found.items()}


def _size(document: Document) -> list[int]:
    lengths = document.lexical['length']

    return [len(lengths), sum(_php_int(length) for length in lengths.values())]


def _shard_lines(path: Path) -> list[str]:
    if not path.is_file():
        return []

    return [line for line in path.read_text(encoding='utf-8').split('\n') if line != '']


def _write_file(path: Path, contents: str) -> None:
    """Written to a temporary file and renamed, so a reader never sees half a file."""
    temporary = path.with_name(path.name + '.tmp')
    temporary.write_text(contents, encoding='utf-8', newline='')
    temporary.replace(path)


def _json(value: Any) -> str:
    """JSON as PHP's json_encode writes it here, so files are byte for byte the same."""
    from .console import encode

    return encode(value)


def _sha256(value: str) -> str:
    return hashlib.sha256(value.encode()).hexdigest()


def _php_int(value: Any) -> int:
    """PHP's `(int)` of a stored count: a number as it is, a string by its leading integer."""
    if isinstance(value, bool):
        return int(value)
    if isinstance(value, (int, float)):
        return int(value)

    return int(compat.to_float(str(value)))


def _read(path: Path) -> Document:
    try:
        return Document.from_dict(json.loads(path.read_text(encoding='utf-8')))
    except ValueError as error:
        raise ContextAtlasError(f'{path} is not a stored document.') from error


def _kind(source: Source, document_id: str, unit_id: str) -> str | None:
    document = source.document(document_id)
    unit = document.unit(unit_id) if document is not None else None

    return unit.kind if unit is not None else None
