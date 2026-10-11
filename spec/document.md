# Documents, search and retrieval

Ingestion returns a Document; the caller stores it and hands it back, as a Source, at
retrieval. The library writes nothing anywhere. `spec/fixtures.json` cases of kind `ingest` and
`retrieve` are tested against this file.

## Document

```json
{
  "format": 2,
  "id": "the caller's id",
  "profile": "tabular",
  "detected": {"profile": "tabular", "score": null, "by": "file type"},
  "units": [{"id": "t1", "kind": "table", "text": "...", "path": ["Sheet1"], "meta": {"table": 0}}],
  "links": [{"type": "defined_by", "from": "p3", "to": "p1"}],
  "lexical": {"length": {"t1": 120}, "terms": {"supplier": {"t1": 2}}},
  "data": {}
}
```

- A unit's `path` and `meta` are left out when empty.
- `lexical` and `data` are JSON objects. PHP writes an empty map inside `data` as `[]`; readers
  treat `[]` and `{}` alike wherever a map is expected.
- Every number in `data` is a float. JavaScript writes `1960.0` as `1960`; readers turn every
  JSON number in table rows and column bounds into a float.

## Ingestion entry points

- `ingest(text, id, format, profile?, title?)`: text that is not valid UTF-8 is first decoded as
  Windows-1252. A data format (`yaml`, `json`, `jsonl`, `xml`, `toml`) goes to the markup
  profile (`spec/markup.md`), `csv` to the tabular profile with `title` defaulting to the id,
  `xlsx` and `docx` are errors (they are binary; `DOCX is read from a file: use ingestFile.`
  for docx), and any other format is read into blocks
  (`spec/formats/`, `spec/behaviour.md` for Markdown and plain text) and ingested as prose with
  `profile`. An unknown format is an error naming the document formats.
- `ingestFile(path, id, profile?, title?)`: the format comes from the file's extension,
  lowercased: md markdown → markdown, mdx, txt text → text, html htm xhtml → html, rst, adoc
  asciidoc → asciidoc, org, tex → latex, ipynb, vtt, srt, docx, yaml yml → yaml, json, jsonl
  ndjson → jsonl, xml, toml, csv, xlsx. A json or jsonl file that the chat reader
  (`spec/formats/chat.md`) reads without error into at least two blocks with a string `speaker`
  and no `attachment: true` is ingested as prose from those blocks, with `profile`; otherwise it
  is data. CSV and XLSX are read as spreadsheets from the path;
  DOCX is read from the path into blocks (`spec/formats/docx.md`) and ingested as prose with
  `profile`; other files are read and passed to `ingest`. An unknown extension is an error.

- `append(document, blocks, profile?)`: adds blocks to a prose document ingested earlier
  (`spec/prose.md`, Appending).

## Exact-term search

- **Terms** of a text: lowercase it, then take every run of two or more Unicode letters or
  digits (`[\p{L}\p{N}]{2,}`). No stemming.
- **At ingestion**, each unit's terms come from its text, its path joined with spaces, and any
  extra text the profile adds for search only (a table adds every value of its label, category,
  id and alias columns). `length` is the unit's term count; `terms` maps each term to the units
  holding it and how often, with terms in byte order.
- **At query time**, over every document of the source: `N` units, average length `L`. For each
  distinct query term with postings `P`, `idf = ln(1 + (N - |P| + 0.5) / (|P| + 0.5))`, and each
  posting adds `idf × count × (k1 + 1) / (count + k1 × (1 - b + b × length / max(L, 1)))`, with
  `k1 = 1.2` and `b = 0.75`. Units are ranked by total, highest first; ties keep the order their
  first posting was met (query terms in first-occurrence order, documents in source order, units
  in the order the document's term map lists them).

## Sources

A Source answers `postings(terms)` (per term, every `{document, unit, count, length}`),
`stats()` (`{units, length}` summed over documents) and `document(id)`. Collection holds
documents in memory in insertion order.

A file store keeps one JSON file per document, named by the lowercase hex SHA-256 of its id,
and an append-only term index in `index/`, so a query reads only what it needs:
- `index/stats.json`: `{document hash: [id, units, length, version]}`, `units` the count of its
  unit lengths and `length` their sum.
- `index/<hhh>.tsv` for each three hex digits `hhh` that start a term's SHA-256 (lowercase
  hex): one line per term and document version, `term TAB key TAB version TAB
  unit:count:length,...`, the key being the first 16 hex digits of the document hash, with the
  units in the order of the document's term map.
- `put` writes the document, appends its lines with version one more than its last (1 when
  new) and updates stats; `remove` deletes the file and its stats entry. A line counts only
  when its version is the document's current version. `compact()` rewrites the shards without
  the lines that do not count.
- Postings list documents in hash order (byte order of the hex strings), the order a store
  sorts its files in. A store with document files but no `index/stats.json` is indexed in one
  pass on first use. A writer holds the store by creating the directory
  `index/.lock.d`: when it exists, the writer retries every 20 ms for up to 30 seconds (then
  fails), and removes a lock directory last modified more than 120 seconds ago as left by a
  writer that died; the directory is removed when the write ends. Each file is written to a
  temporary name and renamed. Scores computed by different languages can differ in the last
  bit of a float; rankings are compared, not raw scores.

## Retrieval

`retrieve(query, source, nearest?, documents?)`:

1. **Candidates.** Exact-term search, fused with the caller's `nearest(query, limit, kinds,
   documents)` when given, by reciprocal rank: each list adds `1 / (60 + rank)` (rank from 1)
   to a unit; units are sorted by total, ties in first-seen order. The limit is 20; with
   `documents` it is 200: exact-term search scores only those documents' postings (term
   weights come from the whole source), `nearest` is asked for those documents (and its hits
   are filtered to them), and the top 20 are kept. A port whose language rejects an extra
   argument passes `documents` only to a `nearest` that declares four parameters.
2. **Tables.** With `documents`, every table unit of those documents in document and unit
   order, otherwise the table candidates; the first 12 go to the tabular planner
   (`spec/tabular.md`). Its context is returned unless it abstains and passage candidates exist.
3. **Passages.** Otherwise the passage candidates go to the prose retriever
   (`spec/prose.md`).
4. **Corrections.** For each passage kept, follow `corrects` links to it from later units:
   take the corrector latest in document order, then the one latest that corrects it, until
   none does (a unit is visited once). The last one is added when missing, and the kept unit's
   source gets `superseded_by` (its id) and its text, in the context, a line
   `(A later message corrects this one.)`. The units are put in document order again.
5. A failed Jev request returns none. No candidates, a planner abstention with no passages, or
   no passage kept, return an abstained context.

A context is `{text, sources: [{document, unit, rows?, superseded_by?}], abstained, trace}`;
`text` is the units' texts joined by blank lines. A table's trace
carries `table` as `document#table`, then `answerable`, `meaning`, and when planned `measure`,
`operation`, `group`, `filters`, `drop_blank`.
