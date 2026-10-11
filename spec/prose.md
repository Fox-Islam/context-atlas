# Prose profiles

Prose is chunked as in `spec/behaviour.md`; then each top-level section gets a profile, and
ingestion builds the links that profile's retrieval follows. Exact Jev wording is in
`php/src/Prose` and recorded in the fixtures (`prose-ingest`, `prose-retrieve`).

| Profile | For | Links | Pool | Scope choice |
| --- | --- | --- | --- | --- |
| guidance | help articles, guides, FAQs | needs | 9 | one passage, several places |
| normative | terms, licences, policies, contracts, regulations, standards | needs, defined_by, refers_to | 9 | one passage, a whole section, several places |
| narrative | articles, histories, biographies, reports | needs | 18 | one passage, several places |
| procedural | installation guides, manuals, runbooks, tutorials | needs, refers_to | 12 | one passage, all the steps, the next or previous step, several places |
| reference | API references, glossaries, catalogues, specification tables | none | 9 | one entry, named entries, all entries with a property |
| conversational | transcripts, minutes, debates, chat logs, discussion threads | reply_to, output_of, decides, corrects | 9 | one message, exchange, one person, outcome, correction, overview |

## Units

Before chunking, a heading whose text is `note`, `tip`, `warning`, `caution`, `important`,
`hint` or `info` (any case, optionally followed by `:`) becomes a paragraph, so an aside stays
in the section it belongs to.

Each chunk becomes a unit `p1`, `p2`... of kind `passage`, path its heading path, meta
`{blocks, profile}`. `data` holds `outline` (from the chunker) and `sections` (section key =>
profile).

## Reference entries

A section whose profile is reference is not chunked: its blocks (non-empty, in order, with
their heading paths) become entry units `e<n>` (n continues the unit count) of kind `entry`,
meta `{name, row, group, fields, blocks, profile}`. `fields` is a list of `{field, values}` in
first-seen order, so a field named like a number keeps its place after a JSON round trip.

- **Heads.** When any block of the section names an entry in `meta.entry`, a block starts an
  entry when its `meta.entry` differs from the previous block's. Otherwise a paragraph with a
  letter starts one when it is a call signature (`^[A-Za-z_][\w.]*\(.*\)\s*(->.*)?$`, or
  `^[A-Z]\w*\.\w+$`, at most 120 characters) or when it is at most 60 characters, does not
  end in `.`, `!`, `?`, `:`, `;` or `,`, and the next block is a paragraph more than twice as long.
  Following non-table blocks join the entry until the next head or heading. A block before
  any head (or after a heading or table, before the next head) is not dropped; the rules below
  place it. A list paragraph (every line opening with `- `, `* `, `+ `, or 1–9 digits and `.`
  or `)`, then whitespace) whose items are glossary-like (more than half of them match
  `^.{1,60}?(?::\s| – | - )\S`, Unicode) becomes one entry per item, its marker removed, named
  by the item's text up to the first `: `, ` – `, ` - ` or ` (` within 60 characters (else its
  first 60 characters), with fields from the item's text. Any other block, a plain list
  included, starts an entry named by the last heading of its path (`section` when it has
  none), which following blocks join as above.
- **Empty headings.** A heading followed by nothing, or by a heading of the same or a higher
  level (a smaller or equal number), is an entry of its own: name and text its squashed text,
  no fields. A vote count written as a heading ("Aye: 100 MPs") stays findable. An entry's text
  is its blocks' trimmed texts joined by a blank line, prefixed with `[path]` and a blank line
  when it has a path.
- **Fields.** From the signature (`meta.signature`, else the name): `parameter`, each name in
  the brackets with `=...`/`:...` and leading `*` removed, skipping `/` and `self`. From the
  text: `added`, `changed`, `deprecated` (version numbers after "added in version",
  "versionadded::", "new in version"; "changed in version", "versionchanged::"; "deprecated
  since version", "deprecated::"; any case), and `exception` (capitalised words ending in
  `Error`, `Exception` or `Warning`). Parameters are listed as written; the other lists keep
  first-seen order without duplicates.
- **Tables.** A table block's caption, header and rows come from `meta.caption`/`header`/`rows`
  when given, else from its text (a `Table: caption` line, then lines split on `|` with outer
  pipes trimmed, Markdown separator rows dropped; the first row is the header). Each row with
  a key (first cell) and at least two non-empty cells is one entry, or, when the key is one or
  two digits or a Roman numeral (`[IVX]{1,5}`) and two or more columns after the first are
  headed by a one- or two-capital group (`^([A-Z]{1,2})( series)?\b`), one entry per group
  named group + key; when instead the caption names `X series`, one entry named `X` + key.
  An entry's fields are its non-empty cells by header; its text is four lines joined by
  newlines: `[section] Table: caption` (each part only when present, so the line can be
  empty), the header joined by ` | `, the row joined by ` | `, and `Entry <name>: <header>: <value>;
  ...`. Row entries are units of kind `entry` too, with `row` true and `group` the caption (or
  the section).

Reference sections build no links.

## Conversations

A section is conversational when its blocks whose `meta.speaker` is a non-empty string hold at
least half the characters (trimmed) of its non-heading blocks, whatever the detection request
answers for it (the request lists every section regardless, so section labels do not depend on
speakers); conversational is not among the detection options, and an answer that is not an offered
option counts as guidance. A caller may pass it as the profile. The section is not chunked: its
non-heading, non-empty blocks become messages. Block meta read: `speaker`, `speaker_full` (the
name as the source gives it in full), `turn`, `time`, `reply_to` (the `turn` this one answers;
`null` means unknown), `attachment` (`true` for a tool result, reasoning, a system prompt or
other material that belongs to a message instead of being said in it).

- **Speakers in text.** When no profile is given (or the profile is conversational), blocks
  are read for speakers before chunking. A run is the consecutive non-heading blocks with the
  same heading path. Its paragraphs (non-empty after trimming) are tested, on the trimmed text
  (Unicode, dot matches newline), first against the cased form, then the uncased form:
  - Cased: `^(?:\[?(\d{1,2}:\d{2}(?::\d{2})?)\]?\s+)?(NAME)(?:\s*[(（]([^()（）\n]{1,80})[)）])?(?:\s*:\s+|\s*：\s*|\.\s+[–—-]\s+)(\S.*)$`,
    where NAME is `\p{Lu}[\p{L}\p{M}'’.-]*(?:\s+(?:(?:J)\s+)*\p{Lu}[\p{L}\p{M}'’.-]*){0,3}(?:\s+(?:J)\s+\p{Ll}[\p{L}\p{M}'’.-]*)?`
    and J is `of|for|the|and|de|du|des|del|della|di|da|do|dos|das|la|le|les|van|von|der|den|zu|y|e`
    (so `Name: `, `Name (role): `, `Name : `, `Name：`, `M. Name. – ` and `M. le président : `).
  - Uncased: `^(?:\[?(\d{1,2}:\d{2}(?::\d{2})?)\]?\s+)?(?:[○◯◎●]\s*)?(\p{Lo}{1,16})(?:\s*[(（]([^()（）\n]{1,80})[)）])?(?:\s*[:：]\s*|(?<=\p{Lo}|[)）])[\x{3000} ]+)(\S.*)$`,
    counted only when the name is followed by `:` or `：`, or the paragraph opens with one of
    `○◯◎●` (after the optional time): a name in a script without letter case followed only by
    a space is a speaker in records that mark speakers so, not in prose.
  Group 1 is the time, 2 the name, 3 the role, 4 the text. A match does not count when the
  name, or its first word, lowercased, is in the not-names list (`Speakers::NOT_NAMES`: English
  labels such as `note`, `example`, `answer`, `q`, `a`, their French, German, Spanish and
  Italian equivalents, and `注 例 注意 備考`), is shorter than two characters or contains a
  digit. A run with a paragraph that already has `meta.speaker` is left alone. Otherwise, when
  it has at least 4 paragraphs, at least 60% of them match, and the matches name at least 2
  different speakers, each matching paragraph becomes a paragraph of its text (group 4,
  trimmed) with `speaker` (the name), `speaker_full` (`name (role)`, only when a role is given)
  and `time` (when given) added to its meta; each paragraph without a match after one takes the
  previous match's `speaker` and `speaker_full`.
- **Turns.** Consecutive blocks with the same heading path and the same `turn` (or, when the
  block has no `turn`, the same `speaker`) form a turn. A block with `attachment: true` is a
  turn of its own, never joined to the blocks around it. Turns are numbered from 0 in order.
- **Units.** Each message turn's block texts (trimmed) are packed into parts of at most 400
  tokens, a block never split; a new part starts when the next block would pass 400. Each part
  is a unit `m<n>` (n continues the unit count) of kind `message`, text `[path | who]` (`[who]`
  with no path; `path` joined by ` > `), then ` (continued)` on parts after the first, a
  newline and the part's blocks joined by newlines. `who` is `speaker_full`, else `speaker`,
  else `unattributed`, followed by `, time` when there is one. Meta `{speaker, time, turn,
  blocks, profile}`, `turn` being the id of the turn's first part, then `source_turn` (the
  block's `turn`, as a string) when the block has one, plus `outcome: true` on outcome parts.
- **Outputs.** An attachment turn's text is cut into parts by lines: whole lines while the
  part stays within 400 tokens, a line longer than 1600 characters (400 × `chars_per_token`)
  first cut every 1600 characters. Each part is a unit `a<n>` of kind `output`, text and meta as
  a message's, with `attachment: true` after `profile`. Outputs are searched like any unit, and
  no Jev question asks about them.
- **output_of** links an output turn's first part to the first part of the turn its
  `reply_to` names when that turn is earlier, else to the last message turn before it (no link
  when there is none).
- **reply_to** links a message turn's first part to the first part of the turn it answers.
  From the source when `reply_to` names an earlier turn. Otherwise, when `conversation_replies`
  is on, for each message turn with a speaker that has a message turn before it, Jev chooses:
  batches of 6 turns, state `{"messages in order": {"m<t>": head 400 of the turn's first
  part}}` holding each asked turn and the six message turns before it (sorted by number), a
  choice `r<t>` over those earlier `m<t>` plus `none`.
- **decides** (when `conversation_outcomes` is on). Batches of 20 message parts, state `{"messages": {id: start and end}}` (600 characters:
  a longer text is shown as its first 300 and last 300 characters joined by ` … `), an outcome
  noul per part; 0.5 or more marks it an outcome. For each outcome part, the candidates are the
  first earlier part of each section and the 5 earlier parts most similar to it; one choice
  `settles` (state `outcome`: start and end 800, `earlier messages`: head 400 each) links the
  outcome to the pick.
- **corrects** (when `conversation_corrections` is on). For each message part with a speaker
  after the first message part: the part its turn replies to
  (for a turn's first part only), then its 3 most similar earlier parts, without duplicates,
  each make a pair `k<i>`. Batches of 15, state `{"pairs": {"k<i>": {"earlier", "later"}}}`
  (start and end 500 each); a yes links the later part to the earlier.
- **Similarity** is the cosine of term counts (exact-search terms of the text without its
  header line); ties keep document order, and parts sharing no term are not similar.

Links are listed reply_to and output_of (by turn), then decides, then corrects, after the
other sections' links.

## Appending

`append(document, blocks, profile?)` adds blocks to a prose document (a markup or tabular
document is an error: `A <profile> document cannot be appended to; ingest it again.`). An unknown
profile is the same error as at ingestion, before anything is read. With no
profile or the conversational one, speakers in text are read from the new blocks first, as at
ingestion.

- The document's **tail** is its last run of `message` and `output` units with one path, read
  back from the last unit (none when the last unit is of another kind).
- The **lead** is the new blocks before the first heading. When the tail is not empty and lead
  blocks with a non-empty `speaker` hold at least half the lead's characters (trimmed), the lead
  continues the conversation: its non-empty blocks, keyed by their index plus the document's
  next block number, with the tail's path, are built as above after the tail's turns. The
  tail's turns are its units whose `turn` is their own id, with `source_turn` as the turn, so a
  new `reply_to` can name them; they get no new links and are not asked about again. The new
  message parts are judged for outcomes against all earlier message parts, and paired for
  corrections with any earlier message part.
- The other new blocks are ingested as prose on their own (with `profile`); each unit id and
  each meta `turn` keeps its letters and has its number raised by the document's highest unit
  number plus the count of units the lead made, links are renamed to match, and block indices
  in meta `blocks` and in the outline are raised by the next block number (plus the lead's
  length). Their heading paths start from their own headings.
- The next block number is one more than the largest block index in any unit's `blocks` or
  the outline (0 when there is none). Ids continue from the highest unit number.
- The result keeps the document's id, profile and `detected`; units and links are the old ones
  followed by the new; `data` gets the new outline entries appended and the new section
  profiles merged in; the exact-term statistics are built again over every unit.

## Detection

The section depth is the first heading depth (from 0) at which the chunks' heading paths take
two or more different values (0 when they never do), so a document under a single title is
split by the headings below it. A chunk's section key is its headings down to that depth
joined by ` > ` (`""` when it has none); sections are in order of first appearance. One
request: state `{"sections": {"s1": {"title", "opening", "headings"}}}`, where `title` is the
last heading of the key, `opening` the head of the section's first chunk without its `[...]`
prefix (600 characters) and `headings` the distinct deeper headings (at most 20); a choice per
section over the profile descriptions. An unknown answer is guidance. The document's profile is
the one whose sections hold the most tokens (ties: first in descending-sort order, which keeps
first-seen order); `detected.score` is the highest confidence among that profile's sections.

Conversational sections (see "Conversations") are set after detection, which is skipped when
every section is conversational; when they hold the most tokens, `detected` is `{profile:
"conversational", score: null, by: "speakers"}`. A profile passed by the caller applies to
every section (`by: "caller"`). Without Jev, or when the request fails, every other section is
guidance (`by: "default"`).

## Links

Built only when at least one section's profile uses them.

- **needs**, over every consecutive pair of the units whose profile builds needs, in document
  order, in batches of 30 pairs:
  state `{"pairs": {"n<i>": {"end_of_first", "start_of_second"}}}` (end of the first text, 300
  characters; head of the second without its prefix, 300). A yes (0.5 or more) links the
  second to the first.
- **defined_by**, over the units of profiles that use it:
  1. Definition units: batches of 30, state `{"passages": {"d<i>": head(text, 500)}}`, one noul
     per unit.
  2. Pairs: for each unit (in order) and each definition unit (in order, not itself), the
     first shared term that is distinctive makes a pair. A term here is a lexical term of four
     characters or more from the text without its prefix; it is distinctive when it appears in
     at most `max(2, floor(0.2 × n))` of the `n` units that build defined_by links, counted over
     those units only.
  3. Batches of 25 pairs, state `{"pairs": {"u<i>": {"passage": head 500, "definitions": head
     700}}}`; a yes links the passage to the definition unit.
- **refers_to**, parsed without Jev:
  - A unit's numbers: for each heading in its path, then the head (40 characters) of its text
    without prefix, a leading `(section|clause|article|regulation|rule|paragraph|schedule|part)?`
    followed by `\d+[A-Z]?(\.\d+)*` (Unicode digits) ending at Unicode whitespace, `.`, `:`,
    `)`, `-`, `–`, `—` or the end. The keyword is matched case-insensitively with Unicode case
    folding (so `ſ` matches `s`); the number as written. The first unit to carry a number owns
    it.
  - A heading may also be numbered by a single capital letter followed by `. ` ("Q. Indemnity").
  - References in a unit's text (without prefix): a keyword (`sections?`, `clauses?`,
    `articles?`, `regulations?`, `rules?`, `paragraphs?`, `schedules?`, `parts?`, any case)
    followed by a list of numbers `(\d+[A-Z]?|[A-Z]\b)(\.\d+)*(\([a-z0-9]+\))*` (capitals
    as written) separated by commas, "and", "or" or "to". Each resolves to the unit owning the
    number, else the number without its parenthesised part.
  - Wiki-style anchors: `#` followed by a capital letter and 2 to 60 characters up to `#`, `.`,
    `,`, `;`, `:`, `(`, `)` or a newline. The captured text, trimmed and lowercased, resolves to
    the longest unit heading (last heading of a path, lowercased) that it starts with,
    followed by the end or a space ("#Prepare first" finds "Prepare"; of equal-length headings,
    the first in unit order), and links to the first two units under that heading.
  - A unit never refers to itself, and each target is linked once, in the order first
    referenced (number references before anchors).

Links are listed needs first, then defined_by, then refers_to. A failed request leaves its
links out.

## Retrieval

Candidates are the passage and entry units retrieval found (`spec/document.md`), best first.
A reference profile retrieves as in "Reference retrieval" below, a conversational one as in
"Conversation retrieval"; the others as follows.

0. **Spread**: the candidates are reordered so that each document's first three candidates
   keep their order and come first; every further candidate of a document follows, in order,
   after them. Near-copies of one document then do not fill the ranking of a workspace.
1. **Profiles**: the profiles of the first five candidates (a unit's `meta.profile`, unknown
   ones read as guidance), most common first (ties: first seen). With one profile, it gathers
   from every candidate as below. With several, each gathers from its own candidates (the
   candidates of that profile, in order) as below; the units of all are merged without
   duplicates in document order, and the trace is `{profiles: {profile: trace}}` for those
   whose requests were answered. Nothing answered is a failed retrieval; nothing kept by any is
   an abstention.
2. **Rank** the first pool-size candidates: state `{question, "the passages the search found":
   {"p<i>": head(text, n)}}` (n is 2400, or for narrative the larger of 2400 and 4 ×
   `max_tokens`, so the whole chunk is read), an answers noul per passage, choice `best`, the
   profile's `scope` choice when it has one, and for procedural an `anchor` choice (the
   passage that describes the step the question names, or `none`) and a `direction` choice
   (`after`, `before`); a scope answer that is not one of the options counts as `one passage`.
   Kept: scores of 0.4 or more, highest first, ties in pool order; the `best` pick moves to the
   front when kept. Nothing kept is an abstention, whose trace is `{reason, profile, scores}`.
3. **Gather** by profile and scope:
   - guidance and normative: `one passage` (and no scope) the first 3; `a whole section` every
     passage of the first kept one's document with the same path; `several places` the first
     6, then a completeness noul over them (head 1200 each), and when it is below 0.5 the next
     pool-size candidates are ranked the same way without a scope and their kept passages
     added.
   - narrative: `several places` keeps the passages scoring 0.5 or more, up to 8, or the first
     3 when fewer than 2 do; otherwise the first 3.
   - procedural: the first 3 (6 for `several places`); `all the steps` adds every passage with
     the first kept one's path; `the next or previous step` adds the content passage next to
     the anchor in the chosen direction, then asks a noul of each content passage up to two
     away from the first two kept ones and adds those at 0.5 or more. A content passage is
     one whose text without prefix has at least 25 tokens; the anchor itself always counts.
4. **Expand** from what was gathered, following each gathered unit's links to units not yet
   gathered:
   - needs: added;
   - refers_to: one request with a noul per target, kept at 0.5 or more;
   - defined_by: one request with a noul per target plus a choice of the one the answer
     depends on most (or `none`); kept at 0.5 or more, or when picked.
   States carry `the passages gathered` (head 800 each) and the targets (head 800 each).
5. The units, without duplicates, in document order (document ids compared as byte strings,
   then position in the document), their texts joined by a blank line, are the context. Its
   trace is `{profile, scope, scores}`.

## Reference retrieval

1. **Named entries**: every entry unit of the candidates' documents whose name (or, for a
   call signature, the name before the bracket), three characters or more, appears in the
   question as a whole word (not preceded by a word character or `.`, not followed by a word
   character or `(`), case-insensitive; longest names first, at most 6.
2. **Rank** the first 9 candidates plus the named entries not among them. Labels are the slug
   (40 characters) of the entry's name, or of the unit's path joined by spaces (`passage` when
   empty), then `-<position>`. State `{question, "the entries the search found": {label:
   head(text, 2400)}}`; an answers noul per label, a `n_<label>` noul for each named entry
   ("Is `x` one of the entries the question names or asks about?"), choice `best`, choice
   `scope` over the reference shapes (an unknown answer counts as `one entry`). Kept as for
   other profiles.
3. **Gather**: `one entry` the first 3 kept; `named entries` the named entries whose `n_` noul
   is 0.5 or more, then the first 3 kept; `all entries with a property` a filter:
   - Options: every field value (trimmed, `∶` read as `:`) of every unit of the candidates'
     documents, two characters or more, that the question contains as a whole token
     (case-insensitive, not touching a letter, digit or `_`), keyed by field, group (the
     row's caption for table rows, else empty) and value, in first-seen order, each with the
     entries holding it.
   - One request: choice `filter` over `f<i>` labelled "entries whose `<field>` is <value>
     (<n> entries)" or "rows of `<group>` whose `<field>` is <value> (<n> entries)", plus
     `none`. A pick returns its entries (at most 30).
   - Without options or a pick, the first 24 candidates in batches of 12 are each asked
     whether they are one of the entries the question asks for; those at 0.5 or more are
     returned, or, when none are, the first 3 kept.
4. The units in document order are the context; the trace is `{profile, scope, scores}` plus
   `filter: {field, group, value}` when a filter was picked.

## Conversation retrieval

1. **Rank** the first 9 candidates, then the two outcome parts of their documents closest to the
   question that are not among them, labelled `message-<i>`: state `{question, "the messages the
   search found": head 1600 each}`, an answers noul per label, choice `best`, choice `scope`
   over the conversational shapes (unknown: `one message`), and, when the candidates'
   documents have speakers, choice `speaker` over each speaker's slug (30 characters, first
   seen; `speaker` when the slug is empty or `0`) labelled with the full name in the message
   header, plus `nobody in particular`.
   Kept as for other profiles, then the first 3 (6 for `overview`).
2. **Expand**, for each kept message in order:
   - the closest part of its reply parent's turn, when the shape is `exchange` or the kept
     message's turn has fewer than 60 tokens (header lines left out);
   - `exchange`: the closest parts of the two turns replying to it that are closest to the
     question;
   - `correction` / `outcome`: every part linked by `corrects` / `decides` to a part of its
     turn, in either direction.
   Then `outcome` adds the outcome part closest to the question in the kept messages'
   documents and the parts it decides; `one person` (with a speaker picked) asks of the 12
   parts by that speaker closest to the question, kept ones excluded, and `overview` of the
   first 18 candidates not kept: state `{question, messages: head 1200}`, a noul per
   `message-<i>`, kept at 0.5 or more.
   "Closest" is the cosine of term counts with the question, ties in the order given.
3. The units without duplicates in document order are the context; the trace is `{profile,
   scope, speaker, scores}`.
