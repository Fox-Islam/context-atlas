# Behaviour

What every implementation does, precisely enough that the PHP, JavaScript and
Python packages produce the same chunks, send Jev the same requests and print
the same output. `spec/fixtures.json` holds the cases each package is tested
against; this file gives the rules that produce them.

## Text rules

- **Length** is counted in Unicode code points (PHP `mb_strlen`, Python `len`,
  JavaScript `[...text].length`, never `text.length`).
- **Tokens** of a text are `ceil((length - wide) / chars_per_token + wide)`, where `wide` is the
  number of its characters whose Unicode Script_Extensions include Han, Hiragana, Katakana or
  Hangul (so shared marks such as `ー`, `、` and `・` count too; PCRE's `\p{Han}` matches this way).
- **Whitespace** is exactly U+0009-U+000D, U+0020, U+0085, U+00A0, U+1680,
  U+180E, U+2000-U+200A, U+2028, U+2029, U+202F, U+205F and U+3000: the set
  PHP's PCRE2 matches with `\s` and the `u` flag. JavaScript's and Python's
  `\s` differ from it, so those packages use this set explicitly.
- **Categories** (`Lu`, `Lt`, `Mn`) come from the runtime's Unicode tables, so
  a code point assigned in a newer Unicode version than the runtime knows can
  be classified differently between packages.
- **Trim** removes leading and trailing whitespace.
- **Squash** trims, then replaces each run of whitespace with one space.
- **Sentences**: trim, then split at every run of whitespace that follows `.`,
  `?`, `!` or `…` and precedes a code point in Unicode category `Lu`. The split
  removes the whitespace. A text with no such place is one sentence.
- **Head** of a text, `n` characters: trim, then its first `n` code points.
- **End** of a text, `n` characters: trim, then its last `n` code points (the
  whole text when shorter).
- **Tail** of a text, `t` tokens: when the text is at most `t × chars_per_token`
  code points, the whole text. Otherwise take its last `t × chars_per_token`
  code points; if they contain a run of whitespace that follows `.`, `?`, `!`
  or `…` and precedes a code point in category `Lu` or `Lt`, start just after
  the first such run; otherwise, if they contain any whitespace, start just
  after the first run of it; otherwise keep them all.
- **Slug** of a name, `n` characters: Unicode NFKD, drop code points in
  category `Mn`, lowercase, replace each run of characters outside `[a-z0-9]`
  with `-`, trim `-` from both ends, cut to `n` code points, and trim a
  trailing `-` again.

## Blocks

A block is `{type, text, level?, meta?}` with type `heading`, `paragraph`,
`table` or `code`. Only a heading has a level. `meta` is carried through and
never read.

### Markdown

Line endings become `\n`. A first line of exactly `---` opens front matter,
dropped up to and including the next line that is exactly `---` or `...`
(nothing is dropped if there is none). Then line by line, with "open lines"
collecting the current paragraph, list or table:

1. **Fence**: a line matching `^ {0,3}(`{3,}|~{3,})` closes the open block and
   starts a code block. Lines up to a line matching `^ {0,3}` + the fence
   character repeated at least as many times + `[ \t]*$` are its text, joined
   with `\n`; an unclosed fence runs to the end. The fence lines are dropped,
   and an empty code block is not emitted.
2. **Blank** (empty after trim): closes the open block.
3. **ATX heading** `^ {0,3}(#{1,6})[ \t]+(.+?)(?:[ \t]+#+)?[ \t]*$`: closes
   the open block; heading of level = the number of `#`, text = group 2
   trimmed.
4. **Setext underline** `^ {0,3}(=+|-+)[ \t]*$`, when the open block is a
   paragraph (not a list, not a table) with lines in it: the open lines,
   joined with spaces, become a heading, level 1 for `=` and 2 for `-`.
5. **Thematic break** `^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$`: closes the
   open block and is dropped.
6. **Content**: a line matching `^ {0,3}\|` is a table line, anything else a
   paragraph line. A list item matches `^ {0,3}(?:[-*+]|\d{1,9}[.)])[ \t]+`.
   If there are open lines and either the kind (table or paragraph) differs
   from the open block's, or this is a list item and the open block is not a
   list, close the open block first. The open block takes this line's kind,
   becomes a list if this line is a list item, and gains the line: a table line
   trimmed, a paragraph line with a leading `^ {0,3}>[ \t]?` removed and then
   trimmed.

Closing emits the open lines, if any, as one block: a table or a list joins
its lines with `\n`, a paragraph with a space. The open block then resets to
an empty, non-list paragraph. Inline markup is left in the text.

### Plain text

A leading byte-order mark is removed; `\r\n`, `\r` and form feeds become
`\n`. The text is split at `\n[ \t]*\n` into pieces; pieces that squash to
nothing are dropped. A line's indent is its count of leading spaces and tabs;
the body indent is the indent of the pieces' first non-blank lines whose
pieces hold the most characters when squashed (the smallest on a tie; 0 with
no pieces). Each piece, with its
non-blank lines:

1. **Code** when every non-blank line is indented at least body + 2: the lines,
   each with the piece's smallest indent removed and right-trimmed, joined
   with `\n`.
2. **Numbered heading** when it has one line and its squashed text matches
   `^((?:Appendix|Annex)\s+[A-Z]|\d+)((?:\.\d+)*)\.?\s+(\p{Lu}.{0,78})$`
   (Unicode) with group 3 not ending in `.`, `,`, `;` or `:`: level 1 plus the
   number of `.` in group 2.
3. **Title heading** when it has one line indented no more than the body, its
   squashed text is at most 60 characters, starts with an uppercase letter and
   does not end in `.`, `,`, `;` or `:`, and the next piece squashed is more
   than twice as long (in characters): level 1.
4. Otherwise a paragraph of the squashed piece.

### Block lists

A JSON list of block objects. Anything else is an error, including an empty
object, as is a block without a known type and a string text, or a heading
without an integer level. A `level` on any other block is dropped. Error
messages are fixed strings, recorded in `spec/fixtures.json`, and never carry
a JSON parser's own message.

## Chunking

### 1. Blocks

Blocks whose text trims to nothing are dropped, but every index reported
(`blocks` on a chunk, `block` in the outline) is the block's index in the
input. Below, "index" means the position among the kept blocks. Each kept
block has `tokens` = tokens of its trimmed text.

### 2. Heading paths

Walking the blocks with an empty path (level → title) and section 0: a heading
of level `L` removes every entry of level `L` or deeper, sets level `L` to its
squashed text, and adds one to the section. Every block records the path's
titles in level order, the section, and a level: its own for a heading, else
the deepest level in the path, else none.

### 3. Stretches

A **section stretch** is the non-heading blocks of one section, in order, when
there are at least two and their tokens sum to more than `min_tokens`. Each
unit is keyed `b{index}`.

A **sentence stretch** is the sentences of a paragraph block (type paragraph
only) whose tokens exceed `split_long_paragraph_at`, when it has at least two.
Each unit is keyed `b{index}s{sentence index}` and has the tokens of its
sentence.

Section stretches come first, in section order, then sentence stretches in
block order. When the prose profiles chunk a document with no profile or the
conversational one, a stretch whose blocks with a non-empty `meta.speaker` hold
at least half its characters (each unit's trimmed text; the block is the one
its key names, `b12s3` naming block 12) is dropped: a conversation is not
chunked. A **seam** is each unit after the first, keyed by that unit, with
the previous unit's text as its first side and its own as its second. Seams
keep this order. Only the first `max_pairs` are asked about.

Nothing is asked when there is no Jev or no seam.

### 4. Asking about seams

Seams go to Jev in batches of `batch_size`, one request per batch, in order.
A request's state is

```json
{"seams": {"<key>": {"end_of_first": "<end, snippet_chars>", "start_of_second": "<head, snippet_chars>"}}}
```

and for each seam, in order, two nouls:

| Name | Instructions | True | False |
| --- | --- | --- | --- |
| `p_<key>` | ``In seam <key>, is the second passage about the same specific subject as the first, rather than just the same document?`` | `the same specific subject: a reader looking for one would want the other` | `a different subject, even though both belong to the same document` |
| `r_<key>` | ``In seam <key>, does the second passage depend on the first to be understood?`` | `it refers back to the first, continues its list or example, or answers it, so it makes little sense read alone` | `it can be understood without the first` |

If any request fails, no seam has a score and the chunks are the structural
ones. A seam has a score only when `p_<key>` came back as a noul; `r_<key>`
counts as 0 when missing. An answer of another type than the one asked counts
as missing, here and in ranking.

### 5. Choosing cuts

Per stretch, each unit `i > 0` has a cut cost: infinite when its seam has no
score or `refers_back ≥ refers_back_protect`, else
`(1 − refers_back_weight) × continues + refers_back_weight × refers_back − seam_threshold`.

A piece is a run of units `[start, end)`. Its cost is infinite when its tokens
are below `topic_min_tokens`, unless it is the whole stretch; otherwise it is
the cut cost of `start` (0 for `start = 0`). The planner minimises the total
cost of the pieces covering the stretch by dynamic programming: for each `end`
from 1 to `n`, try each `start` from `end − 1` down to 0, and keep a candidate
only when it is strictly cheaper than the best so far. The cuts are the starts
of the pieces after the first. A cut unit `b{i}` marks block `i` as opening a
topic; `b{i}s{j}` marks sentence `j` of block `i`.

`used_jev` is true only when every request was answered.

### 6. Packing

A current chunk holds block indexes (each once, in order), text and tokens.
Appending adds the index if new, joins the text with `\n\n`, and adds the
tokens. Flushing closes the current chunk and starts an empty one, marked as
opening a topic or not; flushing a chunk with no text does nothing, and leaves
its mark as it was.

For each block in order:

1. A **heading** flushes the current chunk (not opening a topic), then the
   current chunk is replaced by an empty one holding only the heading's index,
   with no text and no tokens. Its text is the end of the path the chunk is
   prefixed with, so it is not repeated, and a heading followed by another
   heading before any other block makes no chunk (the outline keeps it).
   Nothing else happens for a heading.
2. Another block **opens a topic** when it is marked and the current chunk
   holds at least `topic_min_tokens`. If it opens a topic and the current chunk
   has text, flush; the new chunk opens a topic.
3. A **paragraph** whose tokens exceed `split_long_paragraph_at` is packed by
   sentence parts (below). A **code or table block** whose tokens exceed
   `max_tokens` is appended by size as line parts (below), each part as a block.
   Any other block is appended by size, with its trimmed text:
   - if `current tokens + block tokens ≤ max_tokens`, append;
   - else if the current chunk holds at least `min_tokens`, flush, then append;
   - else append, then flush.

Sentence parts: walk the paragraph's sentences with an empty buffer. A
sentence opens a topic when it is marked and the buffer holds at least
`topic_min_tokens`. When the buffer is not empty and either
`buffer tokens + sentence tokens > max_tokens` with the buffer holding at least
`min_tokens`, or the sentence opens a topic: emit the trimmed buffer as a part
(opening a topic if its first sentence did) and start the buffer with this
sentence. Otherwise add the sentence to the buffer, joined with one space.
Emit what is left. A part whose tokens exceed `max_tokens` is replaced by its
cut pieces (below), only the first keeping its topic mark. Each part is then packed: when the
current chunk has text and either `current tokens + part tokens > max_tokens` with the chunk
holding at least `min_tokens`, or the part opens a topic, flush (the new chunk opening
a topic if the part does); then append the part.

Line parts of a block's trimmed text: its lines, where a table's first line is
its head (its first two lines when the first starts with `Table:`). The limit is
`max_tokens` less the tokens of the head joined with `\n` plus a `\n` (at least
1). Each line is cut into pieces of at most the limit; pieces fill a part while
the part's tokens (each piece counted with a trailing `\n`) stay within the
limit, and a full part is the head and its pieces joined with `\n`. A block
yields at least one part.

Cut pieces of a text over a limit of `l` tokens: while the text has more than
`l` tokens, take its longest prefix of at most `l` tokens; end the piece at the
last space in that prefix when the space is at or after half the prefix's
length, else at the prefix's end; the piece is right-trimmed and the rest
left-trimmed. What remains is the last piece.

At the end, flush a current chunk that has text.

### 7. Finishing

Each chunk takes the heading path, level and section of its first block. Its
**overlap** is the tail, `overlap_tokens`, of the previous chunk's own text
(before any prefix or overlap) when `overlap_tokens > 0`, this chunk does not
open a topic, and both have the same section and heading path; otherwise none.
Its text is `[<path joined by " > ">]\n\n` when the path is not empty, then the
overlap and `\n\n` when there is one, then its own text. `tokens` counts that
whole text; `overlap` counts the overlap.

The **outline** is every kept heading in order: `{block, title (squashed), level}`.

## Ranking

Passages are `{id, text, heading_path?}`; nothing is asked and the result is
none when there are no passages or the query trims to nothing. Each passage is
labelled by its position `i` (from 0): the slug, 60 characters, of its heading
path joined with ` > `, or of `passage <i + 1>` when the path is empty;
`untitled` when the slug is empty; then `-<i>`.

One request, with state

```json
{"the passages the search found": {"<label>": "<head of text, passage_chars>"}, "the question being asked": "<query>"}
```

a noul per passage, in order: name `c_<label>`, instructions
``Does `<label>` answer the question?``, true
``  `<label>` states the answer, or a part of it.``, false
``  `<label>` is on the same subject but does not answer the question, or only asserts that it does.``
(without the leading spaces); and, when `cross_check` is on, a choice named
`best` over the labels in order, instructions
`Which single one answers the question most directly?`.

A failed request returns none. Otherwise the passages whose noul came back at
`rank_threshold` or above are sorted by score, highest first, ties keeping
passage order. When `best` names a label: if that left nothing and
`cross_check_rescue` is off, return the sorted list as is; else move the pick
to the front with its own score (0 if its noul did not come back). Then keep
the first `limit` when one is given. Each result is `{id, score}`.

## Command line

`context-atlas chunk [FILE|-] [--format=markdown|text|blocks] [--no-jev] [--config=FILE] [--pretty]`
reads Markdown by default, a `.txt` or `.text` file as plain text and a `.json`
file as blocks; `-` or no file reads stdin. It prints the chunked result as
JSON: `{"chunks": [...], "outline": [...], "used_jev": bool}`. Without
`--no-jev`, when Jev was not used, it writes to stderr
`Jev was not used: the document had no long sections, or Jev did not answer. The chunks follow headings and sizes only.`

`context-atlas rank QUERY [FILE|-] [--limit=N] [--config=FILE] [--pretty]`
reads a JSON list of passages, or a chunk result (its `chunks`); an empty
object is neither. A passage keeps its own `id`, and one without takes its
position as a string. It prints
`[{"id", "score", "text"}]`. When Jev does not answer it writes
`Jev did not answer. Check TYPESAFE_API_KEY.` to stderr and exits 2.

`context-atlas ingest FILE|- [--id=ID] [--format=FORMAT] [--profile=PROFILE] [--title=TITLE] [--store=DIR] [--no-jev] [--config=FILE] [--pretty]`
reads a document (`spec/document.md`, Ingestion entry points). The id defaults to the file's
base name; reading stdin (`-`) needs `--id` (`Reading stdin needs --id.`) and `--format`
(`Reading stdin needs --format.`), checked in that order, and no FILE at all is
`ingest needs a FILE, or - with --format and --id.` Without `--format` the file goes to
`ingestFile`; with it, `ingest` gets the file's text and that format, except that an `.xlsx` or
`.docx` file given its own format still goes to `ingestFile`. `--profile` and `--title` are
passed through, and `--no-jev` ingests without Jev. It prints the document as JSON; with
`--store=DIR` it puts the document in a file store at DIR instead (creating it) and prints
`{"id", "profile", "detected", "units": count, "links": count, "store": DIR}`.

`context-atlas retrieve QUERY --store=DIR [--documents=ID,...] [--config=FILE] [--pretty]`
retrieves from the file store at DIR by exact terms only (no vector search), limited to the
comma-separated ids (trimmed, empty ones dropped) when given, and prints the context
`{"text", "sources", "abstained", "trace"}`; an abstention exits 0. No QUERY is
`retrieve needs a QUERY.`, no `--store` is `retrieve needs --store=DIR.`, and a DIR that is not
a directory is `<DIR> is not a directory.` When Jev does not answer it writes
`Jev did not answer. Check TYPESAFE_API_KEY.` to stderr and exits 2.

`--config` names a JSON file of the keys in `spec/config.schema.json`. Each
value is checked against its key's type, `minimum` and `maximum` there; an
integer key takes any whole number, `500.0` included. An unknown key or a value
outside its rule is an error, with the messages the fixtures record. Invalid
JSON in any input file is `Not valid JSON.` `--version` prints the version, and `help`,
`--help` or `-h` the usage. Bad input writes one line to stderr and exits 1;
an unknown command writes `Unknown command "<name>".`, a blank line and the
usage, and exits 1. JSON output is compact unless `--pretty` (four-space
indent), never escapes Unicode or slashes, and ends with a newline.
