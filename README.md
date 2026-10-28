# Context Atlas

A utility library to help process large context inputs in limited context window scenarios.
It takes in text from a document, structures each section by what kind of
text it is, and, given a question, gathers the few hundred tokens of context that answer it
instead of the whole document. "Isn't that what RAG is for?" Yep! When RAG works, that's 
what it should do. This library exists to improve RAG implementations by taking care of the 
processing required to make RAG work well. Powered by [Jev](https://docs.typesafe.ai/introduction)!

If you've got a vector storage implementation that takes in arbitrary unstructured content, 
you've probably run into the trouble of managing chunk sizes. One chunk contains two unrelated 
paragraphs, or it cuts partway into a sentence, or it contains a heading for the next section etc 
etc. You increase the chunk size and find that it results in the same problems in different 
places, and if you increase it too much you might as well be uploading the complete text as input 
to the LLM anyway.

By now there are a whole number of different ways to mitigate this issue. Maybe you can avoid 
arbitrary content and only take data in through a form so you can label and store the data in 
a consistent way; or if the input is a standard markup language you might implement some data 
extraction logic in code; or if it really *must* be arbitrary content, you might send it over 
to an LLM to chunk the data for you. In my experience, though, the most common technique today 
is to forego RAG altogether and just send files as inputs to your model of choice, we live in 
the age of 1M+ token context windows after all.

So, why does it matter and why did I even start working on this in the first place? Mostly 
because I'm aware that frontier models are, by and large, not used in enterprise solutions. 
They are prohibitively expensive at enterprise scale for most tasks. It's basically always 
more cost-effective in the long run to use a weaker model built into a specialised, 
well-engineered solution.

The other reasons are that I don't think we can depend on the labs expanding context windows 
forever; context rot is a thing that happens; locally deployed models are still context 
window-limited. Lots of reasons! If none of these apply to you, by all means keep sending the 
entire content directly into the context, the response quality will generally be better than 
any RAG implementation.

**Contents:** [Install](#install) · [Getting started](#getting-started) · [Chunker and reranker on their own](#chunker-and-reranker-on-their-own) · [Documents, storage and retrieval](#documents-storage-and-retrieval) ·
[Input formats](#input-formats) · [Profiles](#profiles) · [Chat and agent transcripts](#chat-and-agent-transcripts) · [Spreadsheets](#spreadsheets) · [How chunks are cut](#how-chunks-are-cut) ·
[How hits are ranked](#how-hits-are-ranked) · [Command line](#command-line) · [Config](#config) ·
[What it does not do](#what-it-does-not-do) · [Documentation](#documentation)

## Install

```sh
composer require phox/context-atlas        # PHP 8.3+
npm install @phox-js/context-atlas         # Node 20.19+
pip install context-atlas                  # Python 3.10+
```

Jev is reached with `TYPESAFE_API_KEY`, or `OPENROUTER_API_KEY` with
`TYPESAFE_PROVIDER=openrouter`.

## Getting started

There are two ways in:

- **`Processor`** ingests documents, stores them and answers questions from them. Use it for a
  new system, or to replace the chunking and search of an existing one. The steps below use it.
- **`Chunker` and `Reranker`** fit inside a RAG pipeline you keep: `Chunker` cuts the text
  you index, and `Reranker` orders what your search returns. See
  [Chunker and reranker on their own](#chunker-and-reranker-on-their-own).

**1. Ingest each document once,** when it is uploaded or changes. Store what comes back, and
embed its units in your vector store under their document and unit ids:

```php
use Phox\ContextAtlas\Index\FileStore;
use Phox\ContextAtlas\Processor;
use Phox\TypeSafe\Client;

$processor = new Processor(new Client());
$store = new FileStore('/var/data/documents');

$document = $processor->ingestFile($path, $fileId);
$store->put($document);

foreach ($document->units as $unit) {
    $vectors->upsert("{$document->id}#{$unit->id}", $embed($unit->text), ['document' => $document->id, 'unit' => $unit->id]);
}
```

`$embed` and `$vectors` stand for your embedding model and vector store. Ingestion asks Jev
about the document, from a few requests for a short page to about 1,300 for a 14,500-line
Claude Code session log, so run it in a queue, not in a web request. To update a document, ingest it again
under the same id and replace its vectors; to delete it, `$store->remove($fileId)` and drop
its vectors.

**2. Retrieve for each question.** Give `retrieve()` a closure over your vector search; it
fuses those hits with its own exact-term search. `documents` limits both to the documents the
asker may see:

```php
$nearest = fn (string $query, int $limit, ?array $kinds, ?array $documents): array => array_map(
    fn ($hit) => ['document' => $hit->payload['document'], 'unit' => $hit->payload['unit']],
    $vectors->search($embed($query), $limit, filter: ['document' => $documents]),
);

$context = $processor->retrieve($question, $store, $nearest, documents: $allowedFileIds);
```

Without `$nearest`, retrieval uses exact terms alone, which needs no embeddings.

**3. Answer from the context.** `$context->text` is a few hundred tokens of passages, each
headed with where it sits in its document, and more where the passages are long: over Claude
Code session logs, whose messages carry tool output, it averages about 1,000 tokens and reaches
2,000. A question about a spreadsheet gives a computed result instead:

```php
if ($context === null) {
    // Jev did not answer: retry, or answer without the documents
} elseif ($context->abstained) {
    // the documents do not hold the answer: say so instead of guessing
} else {
    $answer = $llm->complete("Answer from these sources.\n\n{$context->text}\n\nQuestion: {$question}");
}
```

`$context->sources` lists the document and unit behind each part, for citations; when two
documents can disagree, such as an old and a new version of a policy, put each part's file
name and date beside it.

JavaScript and Python follow the same steps: [docs/javascript.md](docs/javascript.md),
[docs/python.md](docs/python.md).

## Chunker and reranker on their own

```php
use Phox\ContextAtlas\Chunker;
use Phox\ContextAtlas\Parsing\MarkdownParser;
use Phox\ContextAtlas\Reranker;
use Phox\ContextAtlas\Retrieval\Passage;
use Phox\TypeSafe\Client;

$client = new Client();

$chunked = (new Chunker($client))->chunk(MarkdownParser::parse($markdown));

foreach ($chunked->chunks as $chunk) {
    $index->add($chunk->text, ['heading_path' => $chunk->headingPath]);
}

$hits = $index->search($query, limit: 15);
$ranked = (new Reranker($client))->rank(
    array_map(fn ($hit) => new Passage($hit->id, $hit->text, $hit->headingPath), $hits),
    $query,
    limit: 5,
);
```

`rank()` returns null when Jev does not answer, so keep the search's own order
in that case. `chunk()` does not fail when Jev does not answer: the chunks then follow
headings and sizes only, and `$chunked->usedJev` is false.

Both classes also take a closure in place of the client, for callers that put
caching, feature switches or logging around Jev. It is handed a function that
fills in a `SystemOne` request, and sends it itself:

```php
$jev = function (Closure $build) use ($client, $logger): ?SystemOneResponse {
    $response = $build($client->systemOne())->send();
    $logger->info('jev', ['request_id' => $response->requestId()]);

    return $response;
};

new Chunker($jev);
```

Input comes from the readers in `Parsing` (Markdown, HTML, reStructuredText and the others
under [Input formats](#input-formats)), or `BlockListParser` for blocks built elsewhere, such
as the paragraphs and headings of a PDF:
`[{"type": "heading", "text": "Fees", "level": 2}, {"type": "paragraph", "text": "..."}]`.
A block's `meta` is carried through untouched, and every chunk lists the input
indexes of its blocks, so a chunk maps back to pages or offsets.

## Documents, storage and retrieval

`Processor` turns input into a `Document`: plain data holding the units to embed and search,
their links, exact-term statistics and profile data. You store it and pass a `Source` that
holds it to `retrieve()`.

```php
$store->put($processor->ingestFile('spend.csv', 'spend-2026-03', title: 'Spend over £25,000'));
$store->put($processor->ingest($markdown, 'faq', 'markdown'));
$store->put($processor->ingestBlocks($pdfBlocks, 'handbook'));   // blocks from your own PDF extraction
```

- `FileStore` keeps one JSON file per document plus a term index, so a query reads only its
  terms' index shards and the documents it returns. On 1,000 documents (150 MB) a write takes
  about 25 ms, a query scoped to a document about 10 ms, and a question over the whole store
  0.03–1 s (common words like "the" make up most of that). `Collection` holds documents in
  memory. Past a few thousand documents, implement `Index\Source` over your own database: it is
  asked only for the postings of the query's terms and the documents it needs.
- `retrieve()` fuses exact-term and vector hits by reciprocal rank and asks for 20 candidates
  (200 within `documents`). `$kinds` is always null, so `$nearest` may ignore it.
- Jev judges only each profile's pool of leading candidates ([spec/prose.md](spec/prose.md)),
  and a unit that only the vector search finds can rank below units both searches find lower
  down. `nearest_reserved` keeps that many places in the smallest pool for the top vector hits.
  On 42 reworded questions about Claude Code sessions, 3 places answered 31 against 27 with
  none; it is measured on chat transcripts only, and off by default.
- The document format is fixed across PHP, JavaScript and Python
  ([spec/document.md](spec/document.md)), so a document ingested by one is read by the others.

## Input formats

`ingestFile($path, $id)` picks the reader from the extension; `ingest($text, $id, $format)`
takes text you already hold. Documents are read into headings, paragraphs, lists, code and
tables for the prose profiles; data files into a key tree for the markup profile.

| Kind | Formats | Spec |
| --- | --- | --- |
| documents | Markdown, MDX, plain text, HTML, Word (DOCX), reStructuredText, AsciiDoc, Org, LaTeX, Jupyter notebooks | [spec/formats](spec/formats) |
| transcripts | WebVTT, SRT (speakers from voice tags or `Name:`) | [spec/formats/transcripts.md](spec/formats/transcripts.md) |
| chat logs | format `chat`, or a `.json` or `.jsonl` file the chat reader recognises: message lists and exports from OpenAI, Anthropic, Gemini, Mistral, Cohere, Bedrock, Ollama, LangChain and others; Claude Code, Codex CLI and Gemini CLI session logs; ChatGPT and Claude.ai exports | [spec/formats/chat.md](spec/formats/chat.md) |
| data | YAML, JSON, JSONL, XML, TOML | [spec/markup.md](spec/markup.md) |
| spreadsheets | CSV, XLSX | [spec/tabular.md](spec/tabular.md) |

Every reader is written by hand, with no DOM, YAML or TOML library, so the PHP, JavaScript and
Python packages read a file the same way; only the zipped XML of XLSX and DOCX files is parsed
with each language's own XML reader. XLSX and DOCX are read from a file (`ingestFile`), since
they are binary.

## Profiles

Each top-level section of a prose document is given a profile, by one Jev request at ingestion
unless you name one (`ingestBlocks($blocks, 'terms-2026', profile: 'normative')`). The profile
sets which links are built and how retrieval follows them:

| Profile | For | What retrieval adds to the passages that answer |
| --- | --- | --- |
| guidance | help articles, guides, FAQs | the passage each one depends on, such as the rule before a worked example |
| normative | terms, licences, policies, contracts, regulations | the same, plus the sections they cite ("Section 3.1", "regulation 34(9)") and the definitions their answer turns on |
| narrative | articles, histories, reports | every passage that answers part of the question, for answers spread across the text |
| procedural | installation guides, manuals, runbooks | the whole procedure, or the step right before or after the one named, and the sections cited |
| reference | API references, glossaries, catalogues, specification tables | one entry per member, term or table row (a paper-size table gives "A4", "C5"); the entries a comparison names; every entry with a property such as a parameter, version or exception |
| conversational | transcripts, minutes, debates, chat logs, discussion threads, agent sessions | one message per turn, headed with who spoke and when; the message a short reply answers; the replies to a message; what a correction changed; the outcome and the proposal it settles; everything one person said on the question |
| markup | YAML, JSON, JSONL, XML, TOML: OpenAPI descriptions, configs, CI workflows, feeds | one unit per path subtree, headed with its path (`resend: paths > /emails > post`); the schema an endpoint names by `$ref`, the endpoint that uses a schema, what a YAML alias or XML `idref` points to ([spec/markup.md](spec/markup.md)) |
| tabular | CSV and XLSX | a computed result instead of passages ([Spreadsheets](#spreadsheets)) |

A policy with an FAQ appended gets normative for the policy and guidance for the FAQ, and a
question whose best matches span several profiles is answered by each of them. A section
is conversational, without a Jev request, when most of its text names who spoke: block
`meta.speaker` (with optional `speaker_full`, `turn`, `time`, `reply_to` and `attachment`), transcript
voice tags, speaker markup in HTML, or paragraphs written `Name: text`.
Rules: [spec/prose.md](spec/prose.md). Data files need no Jev request at ingestion.

## Chat and agent transcripts

```php
use Phox\ContextAtlas\Parsing\Formats;

$document = $processor->ingest(file_get_contents('session.jsonl'), 'session-42', 'chat');
$document = $processor->append($document, Formats::blocks($newLines, 'chat'));
```

- The `chat` reader gives each message a block naming its speaker and the message it answers.
  A tool call is a block of its own; tool results and reasoning are marked
  `meta.attachment: true`. Repeated Claude Code events are dropped.
- An attachment becomes `output` units, split at line breaks and linked `output_of` its message
  or tool call. No Jev question is asked about them, so a long tool result adds no Jev requests
  at ingestion. A retrieved output comes with the call that produced it.
- `append()` adds blocks to the end of a document and asks Jev only about them. Turns that
  continue the conversation the document ends in can reply to, settle or correct earlier
  messages; other blocks are ingested as prose. New lines parsed on their own lose a
  `reply_to` that points to an earlier line, and Jev chooses those replies instead.
- When a later message corrects a retrieved one, the context also holds the latest correction,
  the corrected message's entry in `sources` has `superseded_by`, and its text is followed by
  `(A later message corrects this one.)`.
- `conversation_replies`, `conversation_outcomes` and `conversation_corrections` turn off the
  Jev questions that build reply, outcome and correction links.

## Spreadsheets

CSV and XLSX files are not chunked. Ingestion cleans each table (currency, thousands
separators, placeholders such as `#` and `[x]`, day- or month-first dates), turns year columns
into rows, profiles each column and joins lookup sheets, with no Jev request. At retrieval Jev
picks the table, the number column, the operation, the grouping and the filter values from
options built from the table's columns and values, and code does the arithmetic. The context is the result,
the records behind it with their sheet and row, and any notes they cite:

```
Query: sum of Amount (£); Supplier is "Network Rail"
Matched 2 records.
Result: sum of Amount (£) = 2,030,250.75 £ (over 2 records)
Records (2 of 2):
source | Entity | Date | Supplier | Transaction No | Amount (£)
spend.csv!5 | DVSA | 2026-03-20 | Network Rail | 2000034790 | 2000000
```

Several files can form one document, so a lookup in one joins onto data in another:
`ingestSpreadsheet(['Data' => 'population.csv', 'Metadata' => 'regions.csv'], 'population')`.
Reading XLSX needs the zip and SimpleXML extensions. The rules are in
[spec/tabular.md](spec/tabular.md).

## How chunks are cut

1. A heading always starts a chunk, and each chunk is prefixed with its heading
   path, `[Guide > Fees]`; a heading with nothing under it makes no chunk.
2. Inside a section longer than `min_tokens`, Jev is asked about each seam
   between paragraphs: is the second about the same subject as the first, and
   does it depend on the first to be understood? A worked example after its
   rule, or a numbered step after the line introducing it, depends on what
   came before even when its subject changes. A seam that depends on the first
   side at least `refers_back_protect` is never a topic cut.
3. The cuts are chosen together: the cheapest set by those two scores that
   leaves no piece under `topic_min_tokens`. Cutting seam by seam would strand
   a sentence-sized fragment wherever two topic changes sit close together.
4. A paragraph longer than `split_long_paragraph_at` is split between
   sentences, at the sentence seams Jev marks as topic changes and otherwise by
   size.
5. Chunks are closed at `max_tokens`. Inside one section a chunk repeats the
   tail of the one before, `overlap_tokens` long and starting at a sentence or
   word boundary; a chunk that opens a new topic repeats nothing.

Only the end of one passage and the start of the next are sent for each seam,
`snippet_chars` of each, in batches of `batch_size` seams per request.
[spec/behaviour.md](spec/behaviour.md) states every rule exactly.

## How hits are ranked

Nearest-neighbour search ranks passages by how closely their wording resembles
the query. `Reranker` asks Jev of each hit whether it states the answer, all in
one request, keeps those scoring at least
`rank_threshold`, and orders them by score. The same request asks which single
passage answers best, and that pick goes first.

Over-fetch from your index, two or three times the results you show, and pass
all of them.

## Command line

```sh
context-atlas chunk guide.md --pretty
context-atlas chunk extracted.json            # a list of blocks
context-atlas chunk notes.txt --no-jev
context-atlas chunk guide.md > chunks.json
context-atlas rank "How long do I have to return an order?" chunks.json --limit=3
context-atlas ingest terms.html --store=./documents
context-atlas ingest rates.xlsx --store=./documents --id=rates-2026
context-atlas ingest session.jsonl --format=chat --store=./documents
context-atlas append new-lines.jsonl --format=chat --id=session.jsonl --store=./documents
context-atlas retrieve "What is the late payment fee?" --store=./documents
```

`chunk` prints `{"chunks", "outline", "used_jev"}`. `rank` takes a JSON list
of `{"id", "text", "heading_path"}` or the output of `chunk`, and prints
`[{"id", "score", "text"}]`. `ingest` reads any supported format and prints the stored
document, or with `--store` saves it in that directory; `append` adds new text to the end of a
stored prose document, asking Jev only about the new blocks; `retrieve` answers from a store by exact
terms (vector search needs the library) and prints `{"text", "sources", "abstained", "trace"}`.
Exit codes: 0 done, 1 bad input, 2 Jev did not answer. Each package installs the same command.

## Config

Every package takes the same keys, with the same defaults, listed and described
in [spec/config.schema.json](spec/config.schema.json). On the command line,
pass a JSON file of them with `--config=FILE`. In PHP:

```php
new Chunker($client, new Config(maxTokens: 500, overlapTokens: 0));
Config::fromArray(['max_tokens' => 500]);
```

## What it does not do

- **Read PDFs or other binary formats** (XLSX and DOCX aside). A PDF records where glyphs are
  drawn, not its headings or paragraphs: extract the text with a tool made for the format and
  pass headings and paragraphs as blocks, or convert to Markdown.
- **Render HTML.** The HTML reader drops page furniture (navigation, scripts, forms, hidden
  elements) by markup alone; text that only CSS hides is read.
- **Parse YAML in full.** Markup is read from indentation: flow collections and block scalars
  stay inside the node that holds them, and the documents of a stream read as one.
- **Embed or store anything.** Units go to whatever embedding model and vector store you
  use, and documents are stored by you.
- **Count real tokens.** A token is `chars_per_token` characters, and a Han, Kana or Hangul
  character one token, which is close for English and CJK and within about 25% for other
  European languages.
- **Answer questions in two steps.** "How did the MP who spoke against the bill vote?" needs
  the speaker found first and then searched for; retrieval does one search. An agent that asks
  a follow-up question does the second step.
- **Compute over tables inside documents.** Only CSV and XLSX tables get computed answers;
  a rate table in a policy is retrieved as text. Computing over document tables was measured
  and gave no gain, because the answering model does the arithmetic from the retrieved rows.
- **Read minutes written in reported speech as a conversation.** "Claire Neale noted that…"
  stays prose; speakers are found in `Name: text`, transcript and speaker-markup forms.

## Documentation

- [spec/behaviour.md](spec/behaviour.md) - chunking and ranking rules, for anyone porting or checking the packages
- [spec/document.md](spec/document.md) - the document format, exact-term search and retrieval
- [spec/prose.md](spec/prose.md) - prose profiles, detection and links
- [spec/tabular.md](spec/tabular.md) - the spreadsheet profile
- [spec/markup.md](spec/markup.md) - YAML, JSON, JSONL, XML and TOML: outline, subtrees and links
- [spec/formats](spec/formats) - the document, transcript and chat log readers
- [spec/config.schema.json](spec/config.schema.json) - config keys and defaults
- [spec/fixtures.json](spec/fixtures.json) - the recorded cases all three packages are tested against
- [docs/javascript.md](docs/javascript.md), [docs/python.md](docs/python.md)
