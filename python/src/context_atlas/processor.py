from __future__ import annotations

import inspect
from collections.abc import Callable, Mapping, Sequence
from pathlib import Path
from typing import Any

from . import index
from .blocks import Block
from .config import Config
from .context import Context
from .document import Document, Unit
from .errors import ContextAtlasError
from .index import Source
from .jev import Jev
from .markup import profile as markup
from .parsing import chat, docx, formats
from .prose import appender, profiles, retriever
from .prose import profile as prose
from .tabular import executor, planner
from .tabular import profile as tabular
from .tabular.grid import Grid

CANDIDATES = 20

MAX_TABLES = 12

FUSION_K = 60

# Reserved vector hits are seated in the smallest first-request pool a prose profile ranks.
RESERVED_POOL = min(profiles.POOL.values())

SUPERSEDED = '(A later message corrects this one.)'

Nearest = Callable[..., Sequence[Mapping[str, str]]]


class Processor:
    """
    Ingests documents into storable artifacts, and retrieves context for a question from them.

        processor = Processor(TypeSafeClient())
        store.put(processor.ingest_spreadsheet('spend.csv', 'dft-spend-2026-03'))
        context = processor.retrieve('How much went to Network Rail?', store)

    The caller stores what ingestion returns and passes a Source back at retrieval; the library
    writes nothing anywhere.
    """

    def __init__(self, jev: Jev | None = None, config: Config | None = None) -> None:
        self._jev = jev
        self._config = config or Config()

    def ingest(
        self,
        source: str | bytes,
        document_id: str,
        source_format: str,
        profile: str | None = None,
        title: str | None = None,
    ) -> Document:
        """
        Reads text in any format `parsing.formats` lists: a document format becomes blocks for the
        prose profiles (`profile` as in `ingest_blocks`), a data format a key tree for the markup
        profile, and CSV a table. XLSX is binary: use `ingest_file` or `ingest_spreadsheet`. DOCX
        is read from its bytes; given as text it is an error. Other bytes that are not valid UTF-8
        are read as Windows-1252.

        :raises ContextAtlasError: for an unknown format or input the format's reader refuses
        """
        if isinstance(source, bytes) and source_format not in ('csv', 'docx'):
            source = _text(source)
        if source_format in formats.DATA:
            return self.ingest_markup(source, document_id, source_format, title)
        if source_format == 'csv':
            return self.ingest_csv(source, document_id, document_id if title is None else title)
        if source_format == 'xlsx':
            raise ContextAtlasError('XLSX is read from a file: use ingest_file or ingest_spreadsheet.')

        return self.ingest_blocks(formats.blocks(source, source_format), document_id, profile)

    def ingest_file(
        self, path: str | Path, document_id: str, profile: str | None = None, title: str | None = None
    ) -> Document:
        """
        Reads a file in the format its extension names (`parsing.formats.EXTENSIONS`). Text that is
        not valid UTF-8 is read as Windows-1252. A JSON or JSON Lines file the chat reader reads
        into at least two messages is ingested as a conversation, not data.

        :raises ContextAtlasError: when the extension names no format, or the file cannot be read
        """
        found = formats.of(path)
        if found is None:
            raise ContextAtlasError(
                f'Cannot tell the format of {Path(path).name} from its extension; call ingest() with a format.'
            )
        if found in formats.SPREADSHEET:
            return self.ingest_spreadsheet(path, document_id, title)
        if found == 'docx':
            return self.ingest_blocks(docx.parse(path), document_id, profile)
        data = _read(path)
        chat_blocks = _chat(data) if found in ('json', 'jsonl') else None
        if chat_blocks is not None:
            return self.ingest_blocks(chat_blocks, document_id, profile)

        return self.ingest(data, document_id, found, profile, title)

    def ingest_spreadsheet(
        self, paths: str | Path | Mapping[str, str | Path], document_id: str, title: str | None = None
    ) -> Document:
        """
        Reads CSV and XLSX files, chosen by extension, as one document, so lookup tables in one
        file join onto data in another. Pass one path, or several keyed by the sheet name each
        CSV takes; an XLSX file contributes its own sheets. The title defaults to the first name.

        :raises ContextAtlasError: for a file that cannot be read or is neither CSV nor XLSX
        """
        named = {Path(paths).stem: paths} if isinstance(paths, (str, Path)) else dict(paths)
        title = title if title is not None else next(iter(named), '')
        sheets = []

        for name, path in named.items():
            extension = Path(path).suffix.lstrip('.').lower()
            if extension == 'xlsx':
                grid = Grid.xlsx(path)
            elif extension in ('csv', 'txt'):
                grid = Grid.csv(_read(path), name)
            else:
                raise ContextAtlasError(
                    f'Cannot read .{extension} as a spreadsheet; give a .csv or .xlsx file.'
                )
            sheets.extend(grid.sheets)

        return tabular.ingest(Grid(sheets), document_id, title)

    def ingest_csv(self, csv: str | bytes, document_id: str, title: str) -> Document:
        """CSV text, or bytes in UTF-8 or Windows-1252, as one sheet named after the title."""
        return tabular.ingest(Grid.csv(csv, title), document_id, title)

    def ingest_markup(self, source: str, document_id: str, markup_format: str, title: str | None = None) -> Document:
        """
        A data file as path subtrees, linked by `$ref`, YAML aliases and XML references.
        `markup_format` is `yaml`, `json`, `jsonl`, `xml` or `toml`; the title defaults to the id.
        No Jev request is made.

        :raises ContextAtlasError: for another format, or a file that does not parse
        """
        named = document_id if title is None else title

        return markup.ingest(source, document_id, markup_format, named, self._config.chars_per_token)

    def ingest_blocks(self, blocks: Sequence[Block], document_id: str, profile: str | None = None) -> Document:
        """
        Prose blocks as passages, chunked by Chunker, each with its section's profile and the
        links that profile uses. `profile` applies one profile to every section; without it Jev
        detects one per section.

        :raises ContextAtlasError: for a profile that does not exist
        """
        return prose.ingest(self._jev, self._config, blocks, document_id, profile)

    def append(self, document: Document, blocks: Sequence[Block], profile: str | None = None) -> Document:
        """
        Adds blocks to the end of a prose document ingested earlier, asking Jev only about the new
        blocks: turns that continue the conversation the document ends in link back to its
        messages, and other blocks are ingested as prose and numbered after the document's units.

        :raises ContextAtlasError: for a markup or tabular document, or a profile that does not exist
        """
        return appender.append(self._jev, self._config, document, blocks, profile)

    def retrieve(
        self,
        query: str,
        source: Source,
        nearest: Nearest | None = None,
        documents: list[str] | None = None,
    ) -> Context | None:
        """
        The context that answers a question, or None when Jev did not answer.

        Candidates come from exact-term search over the source, fused with the caller's vector
        search when `nearest` is given: `nearest(query, limit, kinds, documents)` returns
        `{document, unit}` hits, best first, `documents` (when not None) asking for hits from those
        documents only; a `nearest` with three parameters is called without it. With `documents`,
        exact-term search scores only those documents, hits elsewhere are dropped, and every table
        in them is a table candidate. Tables go to the tabular planner; passages to the prose
        retriever.

        :raises ContextAtlasError: when the processor has no Jev
        """
        if self._jev is None:
            raise ContextAtlasError('Retrieval needs Jev: pass a TypeSafeClient or a hook to the Processor.')

        candidates = self._candidates(query, source, nearest, documents)
        in_scope = candidates if documents is None else _every_unit(source, documents)
        tables = [found for found in in_scope if found[1].kind == 'table'][:MAX_TABLES]
        passages = [found for found in candidates if found[1].kind != 'table']

        if tables:
            context = self._from_tables(query, tables, self._jev)
            if context is None or not context.abstained or not passages:
                return context

        if not passages:
            return Context.abstain({'reason': 'no candidates'})

        return self._from_passages(query, passages, self._jev)

    def _from_tables(self, query: str, tables: list[tuple[Document, Unit]], jev: Jev) -> Context | None:
        by_id = {}
        data = []
        for document, unit in tables:
            table = tabular.by_name(document.data['tables'][unit.meta['table']])
            key = f'{document.id}\0{table["id"]}'
            by_id[key] = (document, unit)
            data.append({**table, 'id': key})

        planned = planner.plan(jev, query, data)
        if planned is None:
            return None
        if planned['table'] is None:
            return Context.abstain(planned['trace'])

        document, unit = by_id[planned['table']['id']]
        table = tabular.by_name(document.data['tables'][unit.meta['table']])
        result = executor.meaning(table) if planned['plan'] is None else executor.run(table, planned['plan'])
        trace = {'table': f'{document.id}#{table["id"]}', **{k: v for k, v in planned['trace'].items() if k != 'table'}}

        sources = [{'document': document.id, 'unit': unit.id, 'rows': result['sources']}]

        return Context(result['text'], sources, False, trace)

    def _from_passages(self, query: str, passages: list[tuple[Document, Unit]], jev: Jev) -> Context | None:
        result = retriever.retrieve(jev, query, passages, self._config)

        if result is None:
            return None
        if not result['units']:
            return Context.abstain({'reason': 'no passage answers the question', **result['trace']})
        units, superseded_by = _with_corrections(result['units'])

        return Context(
            '\n\n'.join(
                unit.text + ('\n' + SUPERSEDED if retriever._key((document, unit)) in superseded_by else '')
                for document, unit in units
            ),
            [_source(document, unit, superseded_by) for document, unit in units],
            False,
            result['trace'],
        )

    def _candidates(
        self, query: str, source: Source, nearest: Nearest | None, documents: list[str] | None
    ) -> list[tuple[Document, Unit]]:
        """Exact-term hits fused with vector hits by reciprocal rank, kept to `documents` when given."""
        limit = CANDIDATES if documents is None else CANDIDATES * 10
        lists: list[Sequence[Mapping[str, Any]]] = [index.search(source, query, limit, None, documents)]
        if nearest is not None:
            hits = nearest(query, limit, None, documents) if _takes_four(nearest) else nearest(query, limit, None)
            lists.append(list(hits))
        if documents is not None:
            lists = [[hit for hit in hits if hit['document'] in documents] for hits in lists]

        scores: dict[tuple[str, str], float] = {}
        for hits in lists:
            for rank, hit in enumerate(hits):
                found = (str(hit['document']), str(hit['unit']))
                scores[found] = scores.get(found, 0.0) + 1 / (FUSION_K + rank + 1)

        keys = sorted(scores, key=lambda found: -scores[found])
        if self._config.nearest_reserved > 0 and len(lists) > 1:
            reserved = min(self._config.nearest_reserved, RESERVED_POOL)
            top = [(str(hit['document']), str(hit['unit'])) for hit in lists[1][:reserved]]
            keys = _reserve(keys, top)

        candidates = []
        for document_id, unit_id in keys[:CANDIDATES]:
            document = source.document(document_id)
            unit = document.unit(unit_id) if document is not None else None
            if document is not None and unit is not None:
                candidates.append((document, unit))

        return candidates


def _reserve(keys: list[tuple[str, str]], top: list[tuple[str, str]]) -> list[tuple[str, str]]:
    """
    The fused keys with each of `top` missing from the reserved pool seated in that pool's
    last places, in `top`'s order; the keys they displace move down, in order.
    """
    pool = keys[:RESERVED_POOL]
    missing = list(dict.fromkeys(key for key in top if key not in pool))
    if not missing:
        return keys

    rest = [key for key in keys if key not in missing]
    kept = RESERVED_POOL - len(missing)

    return rest[:kept] + missing + rest[kept:]


def _with_corrections(units: list[tuple[Document, Unit]]) -> tuple[list[tuple[Document, Unit]], dict[str, str]]:
    """
    Each kept unit that a later unit `corrects` brings the last unit of that chain, the one no
    other unit corrects, and is marked superseded by it: the units in document order, and the
    superseded units' keys to the latest unit's id.
    """
    added = []
    superseded_by: dict[str, str] = {}
    for document, unit in units:
        latest = _latest_correction(document, unit.id)
        if latest is not None:
            superseded_by[retriever._key((document, unit))] = latest.id
            added.append((document, latest))

    return retriever._in_document_order(retriever._unique([*units, *added])), superseded_by


def _latest_correction(document: Document, unit_id: str) -> Unit | None:
    """
    Follows `corrects` links from a unit to the units that correct it, taking the latest
    corrector in document order at each step, until no unit corrects the last one.
    """
    order = {unit.id: i for i, unit in enumerate(document.units)}
    latest = None
    seen = {unit_id}
    while True:
        found = None
        for link in document.links:
            if (
                link.type == 'corrects'
                and link.to == unit_id
                and link.from_ in order
                and link.from_ not in seen
                and (found is None or order[link.from_] > order[found])
            ):
                found = link.from_
        if found is None:
            return latest
        seen.add(found)
        unit_id = found
        latest = document.unit(found)


def _source(document: Document, unit: Unit, superseded_by: dict[str, str]) -> dict[str, str]:
    source = {'document': document.id, 'unit': unit.id}
    key = retriever._key((document, unit))
    if key in superseded_by:
        source['superseded_by'] = superseded_by[key]

    return source


def _every_unit(source: Source, documents: list[str]) -> list[tuple[Document, Unit]]:
    candidates = []

    for document_id in documents:
        document = source.document(document_id)
        if document is not None:
            candidates.extend((document, unit) for unit in document.units)

    return candidates


def _read(path: str | Path) -> bytes:
    try:
        return Path(path).read_bytes()
    except OSError as error:
        raise ContextAtlasError(f'Cannot read {path}.') from error


def _text(data: bytes) -> str:
    try:
        return data.decode('utf-8')
    except UnicodeDecodeError:
        return data.decode('cp1252', errors='replace')


def _chat(data: bytes) -> list[Block] | None:
    """
    The blocks of a JSON or JSON Lines file the chat reader reads into at least two messages
    (blocks with a string `speaker` that are not attachments).
    """
    try:
        blocks = chat.parse(_text(data))
    except ContextAtlasError:
        return None
    messages = [block for block in blocks if _is_message(block)]

    return blocks if len(messages) >= 2 else None


def _is_message(block: Block) -> bool:
    meta = block.meta if isinstance(block.meta, dict) else {}

    return isinstance(meta.get('speaker'), str) and meta.get('attachment', False) is not True


def _takes_four(nearest: Callable[..., Any]) -> bool:
    """
    Whether `nearest` accepts a fourth argument, the documents to search. A three-parameter
    callable is called with three.
    """
    try:
        parameters = inspect.signature(nearest).parameters.values()
    except (TypeError, ValueError):
        return False
    positional = [p for p in parameters if p.kind in (p.POSITIONAL_ONLY, p.POSITIONAL_OR_KEYWORD)]

    return len(positional) >= 4 or any(p.kind is p.VAR_POSITIONAL for p in parameters)
