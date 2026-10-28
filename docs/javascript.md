# Context Atlas for JavaScript

A utility library to help process large context inputs in limited context window scenarios:
it reads documents in the formats people give an LLM, structures each section by what kind of
text it is, and, given a question, gathers the context that answers it instead of the whole
document, using [Jev](https://docs.typesafe.ai) for the judgements. It reads documents the same
way and sends Jev the same requests as the PHP and Python packages; the `spec/` directory in the
repository states the rules.

## Install

```sh
npm install @phox-js/context-atlas
```

Node 20.19 or later, ESM only. Jev is reached through the official
[`@typesafe-ai/sdk`](https://www.npmjs.com/package/@typesafe-ai/sdk), which
reads `TYPESAFE_API_KEY`. `clientFromEnv()` builds the client the command line uses, which also
reads `OPENROUTER_API_KEY` with `TYPESAFE_PROVIDER=openrouter`; it throws the SDK's
`TypeSafeError` when the provider's key is missing.

Bad input (an unknown format or config key, a file that cannot be read, a malformed block
list, retrieval without Jev) throws `ContextAtlasError`. A Jev request that fails never
throws: it gives `null` or the structural result, as each section below says.

## Chunking

```ts
import { TypeSafeClient } from '@typesafe-ai/sdk';
import { Chunker, Config, parseMarkdown } from '@phox-js/context-atlas';

const client = new TypeSafeClient();
const chunked = await new Chunker(client, new Config({ maxTokens: 600 })).chunk(parseMarkdown(markdown));

for (const chunk of chunked.chunks) {
    index.add(chunk.text, { headingPath: chunk.headingPath });
}
```

`new Chunker()` with no Jev, or a Jev that does not answer, cuts at headings
and sizes only, and `chunked.usedJev` is false. `parseText` reads plain text
and `parseBlocks` a JSON list of `{"type", "text", "level"?, "meta"?}`.
`chunked.toObject()`, which `JSON.stringify` also uses, is the JSON the command
line prints. `Config.fromObject` takes the snake_case keys of
`spec/config.schema.json` and refuses any other key.

## Ranking

```ts
import { Passage, Reranker } from '@phox-js/context-atlas';

const hits = await index.search(query, { limit: 15 });
const ranked = await new Reranker(client).rank(
    hits.map((hit) => new Passage(hit.id, hit.text, hit.headingPath)),
    query,
    5,
);

// each item has .passage and .score, best first
const rankedIds = ranked === null
    ? hits.slice(0, 5).map((hit) => hit.id) // Jev did not answer: keep the search order
    : ranked.map((item) => item.passage.id);
```

## Documents and retrieval

`Processor` turns input into a `Document`, plain data you store and hand back as a `Source` at
retrieval. `spec/document.md`, `spec/prose.md`, `spec/markup.md` and `spec/tabular.md` give
the rules.

### Ingesting

```ts
import { FileStore, Processor } from '@phox-js/context-atlas';

const processor = new Processor(client);
const store = new FileStore('documents');

store.put(await processor.ingestFile('terms.html', 'terms'));
store.put(processor.ingestSpreadsheet('spend.csv', 'spend-2026-03', 'Spend over £25,000'));
```

| Method | Reads |
| --- | --- |
| `ingestFile(path, id, profile?, title?)` | a file, in the format its extension names |
| `ingest(textOrBytes, id, format, profile?, title?)` | text or bytes in any format; bytes that are not UTF-8 are read as Windows-1252 |
| `ingestBlocks(blocks, id, profile?)` | blocks you built, such as the headings and paragraphs of a PDF |
| `ingestMarkup(text, id, format, title?)` | a data file, cut into path subtrees linked by `$ref`, YAML aliases and XML references, with no Jev request |
| `ingestCsv(textOrBytes, id, title)` | CSV already in memory |
| `ingestSpreadsheet(paths, id, title?)` | one CSV or XLSX file, or several (`{ Data: 'population.csv', Metadata: 'regions.csv' }`) joined into one document |
| `append(document, blocks, profile?)` | blocks added to the end of a prose document |

`ingestFile`, `ingest`, `ingestBlocks` and `append` return promises, since they may ask Jev;
the others return the document.

Document formats (`markdown`, `mdx`, `text`, `html`, `rst`, `asciidoc`, `org`, `latex`,
`ipynb`, `vtt`, `srt`, `chat`, `docx`, `blocks`) become prose; data formats (`yaml`, `json`,
`jsonl`, `xml`, `toml`) go to `ingestMarkup`; `csv` and `xlsx` to the spreadsheet readers.

- **Prose** gets a profile per section (`guidance`, `normative`, `narrative`, `procedural`,
  `reference` or `conversational`), detected by Jev unless `profile` names one. A section whose
  blocks carry `meta.speaker` is conversational without asking Jev.
- **Chat logs**: `ingestFile` reads a `.json` or `.jsonl` file as a chat log when the chat
  reader finds at least two messages in it, and as data otherwise. For a chat log in memory,
  pass `'chat'` to `ingest`. For `append`, `readBlocks(newLines, 'chat')` reads new lines. The README's "Chat and agent transcripts"
  covers attachments, `output` units and the `conversation_*` config keys.
- **DOCX** is binary: `ingest` takes its bytes, not a string. `parseDocx(path)` and
  `parseDocxBytes(bytes)` give its blocks.
- `readBlocks(text, format)` gives any document format's blocks without ingesting them,
  `formatOf(path)` the format an extension names, and `DOCUMENT_FORMATS`, `DATA_FORMATS` and
  `SPREADSHEET_FORMATS` list the names.

### Storing

- `FileStore(directory)` keeps one JSON file per document plus an append-only term index under
  `index/`, in the layout the PHP and Python packages share. `compact()` rewrites the index
  without the lines of rewritten or removed documents.
- `Collection` holds documents in memory.
- `put(document)` replaces a stored document with the same id; `remove(id)` deletes one. Drop
  its vectors from your own store too.
- For your own database, `document.toObject()` is the JSON to keep and `Document.fromObject()`
  reads it back. Implement `Source` over it: `postings(terms)`, `stats()` and `document(id)`,
  each returning a value or a promise.

Stored tables keep `columns` as a list in column order, so a column named like an integer, such
as `2019`, keeps its place after `JSON.parse`.

### Retrieving

```ts
const context = await processor.retrieve(question, store, {
    nearest: async (query, limit, kinds, documents) =>
        (await vectors.search(await embed(query), limit, { document: documents }))
            .map((hit) => ({ document: hit.payload.document, unit: hit.payload.unit })),
    documents: allowedFileIds,
});
```

`retrieve()` searches by exact terms. `nearest` fuses in your vector search: embed each unit of
`document.units` under its document and unit ids at ingestion, and return those ids
(`embed` and `vectors` stand for your model and store). `documents` limits both searches to
those documents. It returns a `Context` (`text`, `sources`, `abstained`, `trace`), or `null`
when Jev did not answer.

## Sending the request yourself

`Chunker`, `Reranker` and `Processor` also take a hook in place of the client. It is handed
the request, `{ state, questions }` as the SDK's `systemOne` takes it, and returns the SDK's
result, or `null` for no answer. Caching and logging go here:

```ts
import type { JevHook } from '@phox-js/context-atlas';

const hook: JevHook = async (request) => cache.remember(request, () => client.systemOne(request));

const chunked = await new Chunker(hook).chunk(blocks);
```

An error thrown by the hook or the client counts as no answer.

## Command line

```sh
context-atlas chunk [FILE|-] [--format=markdown|text|blocks] [--no-jev] [--config=FILE] [--pretty]
context-atlas rank QUERY [FILE|-] [--limit=N] [--config=FILE] [--pretty]
context-atlas ingest FILE|- [--id=ID] [--format=FORMAT] [--profile=PROFILE] [--title=TITLE] [--store=DIR] [--no-jev] [--config=FILE] [--pretty]
context-atlas append FILE|- --id=ID --store=DIR [--format=FORMAT] [--profile=PROFILE] [--no-jev] [--config=FILE] [--pretty]
context-atlas retrieve QUERY --store=DIR [--documents=ID,...] [--config=FILE] [--pretty]
npx @phox-js/context-atlas ...
```

`chunk` reads Markdown, a `.txt` or `.text` file as plain text, and a `.json`
file as blocks. `rank` reads a JSON list of passages or the output of `chunk`.
`ingest` reads any supported format (by extension, or `--format`, which a chat log needs
unless it is a `.json` or `.jsonl` file: `--format=chat`) and prints the stored document, or saves it in a file store with
`--store`; `append` adds a file's new text to the end of the stored prose document `--id`,
asking Jev only about the new blocks; `retrieve` answers from that store, which the PHP and
Python CLIs share.
`--config` names a JSON file of the keys in `spec/config.schema.json`. Exit
codes: 0 done, 1 bad input, 2 Jev did not answer. `TYPESAFE_PROVIDER=openrouter`
with `OPENROUTER_API_KEY` reaches Jev through OpenRouter.
