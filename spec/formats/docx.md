# DOCX

How a Word document (`.docx`, Office Open XML WordprocessingML) becomes blocks
(`spec/behaviour.md`, Blocks). DOCX is binary, so it is read from a path only: `ingest(text, …,
"docx")` is the error `DOCX is read from a file: use ingestFile.`, and `ingestFile` reads a
`.docx` file with this reader and ingests the blocks as prose. The PHP implementation is
`php/src/Parsing/DocxParser.php` with `php/src/Parsing/Docx/` (`Package`, `Styles`,
`Numbering`, `Runs`); the cases are the `read` fixtures named `docx: ...`, whose input is
`{format: "docx", file: "spec/fixtures/<name>.docx"}` (a path from the repository root).

**Conventions.** **Trim**, **squash** and **whitespace** are the `spec/behaviour.md` rules
(Unicode whitespace). Regexes are ASCII-only and run without a Unicode flag. Lowercasing a
style name is Unicode lowercase (PHP `mb_strtolower`). Element and attribute names are matched
by namespace URI and local name, never by prefix:

| Name | Namespace URIs |
| --- | --- |
| `w:` | `http://schemas.openxmlformats.org/wordprocessingml/2006/main`, `http://purl.oclc.org/ooxml/wordprocessingml/main` (Strict) |
| `mc:` | `http://schemas.openxmlformats.org/markup-compatibility/2006` |
| `m:` | `http://schemas.openxmlformats.org/officeDocument/2006/math`, `http://purl.oclc.org/ooxml/officeDocument/math` |
| `dc:` | `http://purl.org/dc/elements/1.1/` |
| relationships | `http://schemas.openxmlformats.org/package/2006/relationships` |

A `w:` attribute (`w:val`, `w:styleId`, ...) is the attribute of that local name in either `w:`
namespace. **The value of child `X`** means the `w:val` of the first child element `w:X`. An
**integer** is an attribute value matching `^-?[0-9]{1,9}$` exactly; anything else counts as
absent. Text nodes outside `w:t` / `m:t` (indentation between elements) are never read.

## Package

The file is a zip archive. Errors are fixed strings, `<name>` being the file's base name:

- the file does not exist or is not a zip archive: `Cannot open <name> as a DOCX document.`
- (PHP without the zip extension: `Reading DOCX needs the zip extension.`)

**Parts read**, in this order: `_rels/.rels`; the main document part; its relationships part;
the styles, numbering, footnotes and endnotes parts; the core properties part. A part not in
the archive counts as absent (only the main document is required).

- **Relationships** of a relationships part: its `Relationship` elements in order, skipping
  `TargetMode="External"`. Each is keyed by the last `/`-separated segment of its `Type`; the
  first of each key wins. A `Target` starting with `/` is a path from the archive root, any
  other is relative to the folder of the part the relationships belong to (the package root for
  `_rels/.rels`). The path is then normalised: split on `/`, drop empty and `.` segments, and
  let `..` remove the segment before it.
- **Main document**: the `officeDocument` relationship of `_rels/.rels`, else
  `word/document.xml`. Missing: `<name> has no main document part (<path>).`
- **Its relationships part**: `<folder>_rels/<file>.rels` for main part `<folder><file>`
  (`word/_rels/document.xml.rels`). The `styles`, `numbering`, `footnotes` and `endnotes`
  relationships give those parts, else `word/styles.xml`, `word/numbering.xml`,
  `word/footnotes.xml`, `word/endnotes.xml`.
- **Relationship types** are compared by the text after their last `/` (the whole type when it
  has none).
- **Core properties**: the `core-properties` relationship of `_rels/.rels`, else
  `docProps/core.xml`.

**Safety.** Before reading a part, its declared uncompressed size (from the zip directory) is
added to a running total for the parts read so far; when the total passes 50 MB (52 428 800
bytes), the error is `<name> is too large: its parts unpack to more than 50 MB.` A part that
is not stored or deflated (the only methods DOCX allows), cannot be unpacked, or yields a
different number of bytes than it declared, is
`<part> in <name> cannot be read.` (PHP reads at most declared size + 1 bytes.) Then, after
removing a leading UTF-8 byte order mark:

- a part containing `<!DOCTYPE` (any case) is refused with
  `<part> in <name> declares a DOCTYPE, which is not read.`, so no entity is ever declared or
  expanded and nothing is fetched (PHP also parses with `LIBXML_NONET`);
- a part whose elements nest more than 1000 deep is refused with
  `<part> in <name> is nested more than 1000 elements deep.`, judged before parsing by a scan of
  its tags: comments (`<!--` to `-->`), CDATA sections (`<![CDATA[` to `]]>`) and processing
  instructions (`<?` to `?>`) are skipped; `</` closes a level (never below 0); any other tag
  opens one unless the character before its closing `>` is `/`, where that `>` is the first
  outside single- or double-quoted attribute values; an unclosed tag, comment, CDATA section or processing
  instruction ends the scan;
- a leading XML declaration `^\s*<\?xml[^>]*\?>` is removed (the encoding it names is ignored:
  parts are read as UTF-8);
- a part that is empty or only whitespace, not valid UTF-8, or not well-formed XML 1.0 with
  namespaces (an undeclared prefix included), is refused with
  `<part> in <name> is not well-formed XML.` Only the predefined entities and character
  references can occur.
- no limit applies to the size of a single name, value, comment or text inside a part (PHP
  parses with `LIBXML_PARSEHUGE`); the 50 MB total bounds them.

## Styles

From the styles part, each child `w:style` with `w:type="paragraph"`, keyed by `w:styleId` (the
first of an id wins): its **name** (the value of child `name`, `""` when absent), `basedOn` (the
value of child `basedOn`), and from its child `w:pPr`: the outline level (the integer value of
`outlineLvl`), and from `w:pPr/w:numPr` the numId (the value of `numId`) and level (the integer
value of `ilvl`). The **default paragraph style** is the first paragraph style whose
`w:default` is `1`, `true` or `on`.

A paragraph's **style** is its `w:pPr/w:pStyle` value when that names a known paragraph style,
else the default paragraph style (or none). Its **name** is that style's own name, lowercased;
names are never inherited. An **inherited** property is looked up on the style, then on the
style its `basedOn` names, and so on, taking the first one set; at most 20 styles are visited,
and a `basedOn` that names no known style ends the chain.

## Body

The main document's root element must contain a child `w:body`; without one there are no body
blocks. The **body reader** turns a container's child elements, in order, into items:

- `w:p`: a paragraph item (below), followed by the items of the text boxes found in it.
- `w:tbl`: a table (below).
- `w:sdt`: the body reader on its child `w:sdtContent` (if any), unless it is a **table of
  contents**: its `w:sdtPr/w:docPartObj` child `docPartGallery` has the value
  `Table of Contents`; then nothing.
- `w:customXml`, `w:ins`, `w:moveTo`: the body reader on the element itself.
- `mc:AlternateContent`: the body reader on its first `mc:Choice`, or its first `mc:Fallback`
  when it has no `mc:Choice`.
- anything else (`w:sectPr`, bookmarks, `w:del`, `w:moveFrom`, ...): nothing.

### Paragraphs

For a `w:p` with properties `P` (its child `w:pPr`) and style `S`:

- **numId**: the value of `P/w:numPr` child `numId`, else inherited from `S`; **ilvl**: the
  integer value of `P/w:numPr` child `ilvl`, else inherited, else 0;
- **outline level**: the integer value of `P` child `outlineLvl`, else inherited;
- **text**: the run text (below).

Its kind, first match:

1. **title**: the name is `title`.
2. **heading**: the name matches `^heading ([1-9])$` (level = that digit), or the outline level
   is 0-8 (level = outline level + 1); the level is capped at 6. (Outline level 9 is body text.)
3. **item**: the numId is not `0` and the numbering has it (Lists). The ilvl, clamped to 0-8,
   counts the item now and gives its marker.
4. **code**: the name contains `code`, `source` or `preformatted`.
5. **caption**: the name is `caption`.
6. **paragraph**.

So a numbered heading is a heading, and its numbering is not counted.

### Run text

The text of a paragraph is built by walking its child elements recursively, in document order.
The walk keeps a **field stack** across paragraphs (fields may span them), one entry per open
complex field: a dropped flag and the instruction text read so far. Text is **dropped** while
any entry's flag is true. A **table-of-contents instruction** matches
`^\s*TOC(?![A-Za-z])` without regard to case (`TOC \o "1-3" \h`). For an element:

| Element | Output |
| --- | --- |
| `w:t` | its text content (unless dropped) |
| `w:tab`, `w:ptab` | `\t` (unless dropped) |
| `w:br`, `w:cr` | `\n` (unless dropped) |
| `w:noBreakHyphen` | `-` (unless dropped) |
| `w:softHyphen` | nothing |
| `w:sym` | the symbol (below, unless dropped) |
| `w:fldChar` | `w:fldCharType` `begin` pushes an entry (flag true, instruction `""`); `separate` sets the top entry's flag (when there is one) to whether its instruction is a table-of-contents instruction, so a field's result is kept except a table of contents'; `end` pops (when there is one). Outputs nothing. |
| `w:instrText` | appends its text content to the top entry's instruction (when there is one); outputs nothing |
| `w:fldSimple` whose `w:instr` is a table-of-contents instruction | nothing |
| `w:sdt` that is a table of contents (below) | nothing |
| `w:footnoteReference`, `w:endnoteReference` | a note marker (below) |
| `w:ruby` | the walk of its child `w:rubyBase` (the annotation is dropped) |
| `w:txbxContent` | nothing in the text; its content is read by the body reader and its items follow the paragraph (in a note, it is dropped) |
| `w:del`, `w:moveFrom`, `w:delText`, `w:delInstrText`, any `w:` element whose local name ends in `Pr` | nothing |
| any other `w:` element (`w:r`, `w:hyperlink`, `w:smartTag`, `w:sdt`, `w:sdtContent`, `w:ins`, `w:moveTo`, `w:fldSimple`, `w:drawing`, `w:pict`, `w:object`, ...) | the walk of its children |
| `mc:AlternateContent` | the walk of its first `mc:Choice`, else its first `mc:Fallback` |
| `m:t` | its text content (unless dropped) |
| any other element | the walk of its children |

So hyperlinks keep their text, field results are kept (a table of contents' are dropped, so it
does not repeat the headings) and instructions dropped (`w:fldSimple`
keeps its result runs), deletions are dropped and insertions kept, content controls keep their
content, and images and shapes contribute nothing but their text boxes. A text box written as
`mc:AlternateContent` with a DrawingML choice and a VML fallback is read once.

**Symbol**: `w:char` must match `^[0-9A-Fa-f]{1,6}$`, and the lowercased `w:font` must not
contain `symbol`, `wingdings` or `webdings` (those glyphs have no reliable Unicode
equivalent); otherwise nothing. The code point is the hexadecimal value, less 0xF000 when it is
0xF000 or more; it is output when it is at least 0x20, at most 0x10FFFF and not a surrogate.

**Note marker**: footnotes and endnotes are the children `w:footnote` / `w:endnote` of the
footnotes / endnotes part, keyed by kind and `w:id` (first wins), skipping those whose `w:type`
is `separator`, `continuationSeparator` or `continuationNotice`. A reference whose note exists,
outside a note and not dropped, outputs `[n]`: `n` numbers the referenced notes from 1 in order
of first reference, footnotes and endnotes sharing one sequence, so a note referenced again
keeps its number. Other references output nothing.

## Lists

From the numbering part's children:

- `w:abstractNum` keyed by `w:abstractNumId` (first wins): its levels, each child `w:lvl` with
  an integer `w:ilvl` of 0-8 (first wins): **start** (integer value of `start`), **format**
  (value of `numFmt`), **template** (value of `lvlText`).
- `w:num` keyed by `w:numId` (first wins): the abstract numbering (value of `abstractNumId`) and
  for each child `w:lvlOverride` with an integer `w:ilvl` of 0-8: a start override (integer
  value of `startOverride`) and a replacement level (its child `w:lvl`, read as above).
  `w:numStyleLink` and `w:styleLink` are not followed.

The numbering **has** a numId when a `w:num` with it exists and its abstract numbering exists.
The **level** `k` of a numId is the override's replacement level, else the abstract
numbering's level `k`, else a level with nothing set. Its **start** is the start override, else
the level's start, else 0.

**Counting**: counters are kept per numId (a map level → value). An item at level `L` sets
counter `L` to its value + 1, or to the start of level `L` when unset, then unsets every counter
deeper than `L`. Counters persist across everything between items, so numbering continues after
interrupting paragraphs, and a different numId (even on the same abstract numbering) counts on
its own.

**Marker**: `-` when the level's format is `bullet`. Otherwise the template (when absent,
`%<L+1>.`) with each `%k` (k 1-9) replaced by the counter of level `k − 1`, or that level's
start when unset, formatted by level `k − 1`'s format; the result is trimmed of spaces. Formats:
`decimalZero` pads values 0-9 with a leading `0`; `lowerLetter` / `upperLetter` give
`a`-`z` then `aa`, `bb`, ... (the letter `(n − 1) mod 26`, repeated `⌊(n − 1) / 26⌋ + 1` times),
values below 1 as decimal; `lowerRoman` / `upperRoman` give roman numerals for 1-3999, other
values as decimal; `bullet` and `none` give `""`; any other format (`decimal`, unknown) gives
the decimal value. So `%1.` gives `1.`, `(%2)` gives `(a)`, `%1.%2.%3` gives `2.b.i`, and a
`none` level with an empty template gives no marker.

## Tables

The rows of a `w:tbl` are its child `w:tr` elements, also found inside `w:sdt/w:sdtContent` and
`w:customXml` children (recursively); the cells of a row are its `w:tc` children, found the same
way. Each row builds a grid of cells:

1. `w:trPr` child `gridBefore` (integer, clamped 0-100, default 0) empty cells first.
2. Each cell, with `w:tcPr`: span = integer value of `gridSpan` clamped 1-100 (default 1). A
   `w:vMerge` child whose `w:val` is absent or `continue` makes it a **continuation**. A
   non-continuation cell's items are the body reader on the `w:tc`, and its text is those items
   **flattened**: a paragraph-like item gives its text squashed, a table item gives every cell
   text of its header and rows in order; the non-empty parts are joined with one space (so
   nested tables and text boxes become part of the cell's text). The cell is placed `span`
   times: a continuation takes, at each column it fills, the text of the previous row's grid
   cell at that column (`""` when there is none).
3. A row's header flag: its `w:trPr` has a `w:tblHeader` child whose `w:val` is not `0`,
   `false` or `off`.

**One-column tables**: when no row's grid has more than one cell (a layout box), the table
gives, instead of a table, the items of its cells (the first placement of each
non-continuation cell) in order, so headings and paragraphs inside it are read as body.

Otherwise the **header rows** are the leading rows with the header flag, or the first row when
the first row has none (when there are rows). In header rows a continuation cell's text is `""`
(so a header cell merged down is not repeated). The table item is (caption, header rows, other
rows), emitted by the **Table** rule of `spec/formats/rst.md` (Output): header rows merged
column by column, empty rows dropped, `Table: <caption>` first when there is a caption.

**Captions**: after the whole body is read, each table item in order looks at the items before
it, skipping paragraph and caption items whose text squashes to empty: if the first other item
is a caption item not already taken, its squashed text is the table's caption and it is taken.
Otherwise it looks the same way at the items after it. A taken caption is not emitted; any other
caption item is an ordinary paragraph.

## Output

Items are emitted in order. **Shift** is 1 when some title item's text squashes to something,
else 0.

- **title**: a level-1 heading of the squashed text.
- **heading**: a heading of the squashed text at level min(6, level + shift).
- **paragraph**, **caption** (not taken): a paragraph of the squashed text.
- Headings and paragraphs whose text is empty are not emitted.
- **item**: a list line, `"  " × ilvl` + marker + space + squashed text (no marker and space
  when the marker is empty); no line when the text is empty. Consecutive items (with only
  empty paragraphs between them) are one paragraph block, the lines joined with `\n`.
- **code**: consecutive code items (with only empty paragraphs between them) are one code block.
  Each item's text, unsquashed (tabs kept), is split on `\n` into lines; leading and trailing
  blank lines of the run are dropped and the rest joined with `\n` (nothing when none remain).
- **table**: as above.

After the body: one paragraph per referenced note, in marker order, `[n] ` + the squashed text
of each of the note's `w:p` children joined with one space (empty parts skipped), trimmed (so
an empty note gives `[n]`). Text in a note is read like body text, except that references
inside it output nothing and its text boxes are dropped; the field stack starts empty.

**Document title**: when no block is a level-1 heading, the squashed text of the first child
`dc:title` of the core properties' root element, when not empty, is a level-1 heading in front
of all blocks.

## Not read

Comments, headers, footers, the glossary, settings, images and charts (except text boxes),
`w:numStyleLink`, the `w:lvlRestart` and `w:isLgl` numbering options, list numbering of
headings, and character formatting.
