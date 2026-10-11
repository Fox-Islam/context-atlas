# Context Atlas for Python

A utility library to help process large context inputs in limited context window scenarios:
it reads documents in the formats people give an LLM, structures each section by what kind of
text it is, and, given a question, gathers the context that answers it instead of the whole
document, using [Jev](https://docs.typesafe.ai) for the judgements. It reads documents the same
way and sends Jev the same requests as the PHP and JavaScript packages; the `spec/` directory in the
repository states the rules.

## Install

```sh
pip install context-atlas      # or: uv add context-atlas
```

Python 3.10 or later. Jev is reached through the official
[`typesafe-sdk`](https://pypi.org/project/typesafe-sdk/), which reads
`TYPESAFE_API_KEY`, or `OPENROUTER_API_KEY` with `TYPESAFE_PROVIDER=openrouter`.

Bad input (an unknown format or config key, a file that cannot be read, a malformed block
list, retrieval without Jev) raises `ContextAtlasError`. A Jev request that fails never
raises: it gives `None` or the structural result, as each section below says.

## Chunking

```python
from typesafe_sdk import TypeSafeClient
from context_atlas import Chunker, Config, parse_markdown

client = TypeSafeClient()
chunked = Chunker(client, Config(max_tokens=600)).chunk(parse_markdown(markdown))

for chunk in chunked.chunks:
    index.add(chunk.text, heading_path=chunk.heading_path)
```

`Chunker()` with no Jev, or a Jev that does not answer, cuts at headings and
sizes only, and `chunked.used_jev` is false. `parse_text` reads plain text and
`parse_blocks` a JSON list of `{"type", "text", "level"?, "meta"?}`.
`chunked.to_dict()` is the JSON the command line prints. `Config.from_dict` takes
the snake_case keys of `spec/config.schema.json` and refuses any other key.

## Ranking

```python
from context_atlas import Passage, Reranker

hits = index.search(query, limit=15)
ranked = Reranker(client).rank(
    [Passage(hit.id, hit.text, hit.heading_path) for hit in hits], query, limit=5
)

if ranked is None:
    ranked_ids = [hit.id for hit in hits[:5]]  # Jev did not answer: keep the search order
else:
    ranked_ids = [item.passage.id for item in ranked]
```

## Sending the request yourself

`Chunker`, `Reranker` and `Processor` also take a hook in place of the client. It is
handed the request, `{"state": ..., "questions": {...}}` with SDK `Noul` and `Choice`
objects, and returns the SDK's `SystemOneResponse`, or `None` for no answer. Caching and
logging go here:

```python
from typesafe_sdk import SystemOneResponse
from context_atlas import Chunker, JevRequest

def hook(request: JevRequest) -> SystemOneResponse | None:
    return client.system_one(request["state"], request["questions"])

chunked = Chunker(hook).chunk(blocks)
```

An exception raised by the hook or the client counts as no answer.

## Documents and retrieval

`Processor` turns input into a `Document`, plain data you store and hand back as a `Source` at
retrieval. `spec/document.md`, `spec/prose.md`, `spec/markup.md` and `spec/tabular.md` give
the rules.

### Ingesting

```python
from context_atlas import FileStore, Processor

processor = Processor(client)
store = FileStore("documents")

store.put(processor.ingest_file("terms.html", "terms"))
store.put(processor.ingest_file("spend.csv", "spend-2026-03", title="Spend over £25,000"))
```

| Method | Reads |
| --- | --- |
| `ingest_file(path, id, profile=None, title=None)` | a file, in the format its extension names, `.docx` and `.xlsx` included |
| `ingest(text_or_bytes, id, format, profile=None, title=None)` | text or bytes in any format |
| `ingest_blocks(blocks, id, profile=None)` | blocks you built, such as the headings and paragraphs of a PDF |
| `ingest_markup(text, id, format, title=None)` | a data file, cut into path subtrees linked by `$ref`, YAML aliases and XML references, with no Jev request |
| `ingest_csv(text_or_bytes, id, title)` | CSV already in memory |
| `ingest_spreadsheet(paths, id, title=None)` | one CSV or XLSX file, or several (`{"Data": "population.csv", "Metadata": "regions.csv"}`) joined into one document |
| `append(document, blocks, profile=None)` | blocks added to the end of a prose document |

Document formats (`markdown`, `mdx`, `text`, `html`, `rst`, `asciidoc`, `org`, `latex`,
`ipynb`, `vtt`, `srt`, `chat`, `docx`, `blocks`) become prose; data formats (`yaml`, `json`,
`jsonl`, `xml`, `toml`) go to `ingest_markup`; `csv` and `xlsx` to the spreadsheet readers.

- **Prose** gets a profile per section (`guidance`, `normative`, `narrative`, `procedural`,
  `reference` or `conversational`), detected by Jev unless `profile` names one. A section whose
  blocks carry `meta.speaker` is conversational without asking Jev.
- **Chat logs**: `ingest_file` reads a `.json` or `.jsonl` file as a chat log when the chat
  reader finds at least two messages in it, and as data otherwise. For a chat log in memory,
  pass `"chat"` to `ingest`. For `append`,
  `context_atlas.parsing.formats.blocks(new_lines, "chat")` reads new lines. The README's "Chat
  and agent transcripts" covers attachments, `output` units and the `conversation_*` config
  keys.
- **DOCX** is binary: `ingest` takes its bytes, not text.
- **Spreadsheets** are profiled without Jev; at retrieval Jev picks the table, measure, filters
  and grouping, and the package computes the answer.
- `context_atlas.parsing.formats.blocks(text, format)` gives any document format's blocks
  without ingesting them, and `formats.of(path)` the format an extension names.

### Storing

- `FileStore(directory)` keeps one JSON file per document plus an append-only term index under
  `index/`, in the layout the PHP and JavaScript packages share. `compact()` rewrites the index
  without the lines of rewritten or removed documents.
- `Collection()` holds documents in memory.
- `put(document)` replaces a stored document with the same id; `remove(document_id)` deletes
  one. Drop its vectors from your own store too.
- For your own database, `document.to_dict()` is the JSON to keep and `Document.from_dict()`
  reads it back. Any object with `postings`, `stats` and `document` methods can stand in for a
  store (see `Source`).

### Retrieving

```python
def nearest(query, limit, kinds, documents):
    hits = vectors.search(embed(query), limit, document=documents)
    return [{"document": hit.payload["document"], "unit": hit.payload["unit"]} for hit in hits]

context = processor.retrieve(question, store, nearest, documents=allowed_file_ids)
if context is not None and not context.abstained:
    prompt = context.text
```

`retrieve()` searches by exact terms. `nearest` fuses in your vector search: embed each unit of
`document.units` under its document and unit ids at ingestion, and return those ids (`embed`
and `vectors` stand for your model and store). A `nearest` with three parameters is not given
the documents. `documents` limits both searches to those documents.

It returns None when Jev did not answer. A `Context` has `text`, `sources` (a
`{"document", "unit"}` dict per part, with `rows` for a table and `superseded_by` for a
corrected message), `abstained` and `trace`.

## Command line

```sh
context-atlas chunk [FILE|-] [--format=markdown|text|blocks] [--no-jev] [--config=FILE] [--pretty]
context-atlas rank QUERY [FILE|-] [--limit=N] [--config=FILE] [--pretty]
context-atlas ingest FILE|- [--id=ID] [--format=FORMAT] [--profile=PROFILE] [--title=TITLE] [--store=DIR] [--no-jev] [--config=FILE] [--pretty]
context-atlas retrieve QUERY --store=DIR [--documents=ID,...] [--config=FILE] [--pretty]
python -m context_atlas ...
```

`chunk` reads Markdown, a `.txt` or `.text` file as plain text, and a `.json`
file as blocks. `rank` reads a JSON list of passages or the output of `chunk`.
`ingest` reads any supported format (by extension, or `--format`, which a chat
log needs unless it is a `.json` or `.jsonl` file; stdin needs `--format` and `--id`) and prints the document, or with
`--store=DIR` saves it there and prints a summary. `retrieve` answers QUERY from that store by exact
terms, optionally limited to `--documents`.
`--config` names a JSON file of the keys in `spec/config.schema.json`. Exit
codes: 0 done, 1 bad input, 2 Jev did not answer. `TYPESAFE_PROVIDER=openrouter`
with `OPENROUTER_API_KEY` reaches Jev through OpenRouter.
