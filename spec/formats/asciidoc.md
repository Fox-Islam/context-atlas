# AsciiDoc

How an AsciiDoc document becomes blocks (`spec/behaviour.md`, Blocks). No Asciidoctor: one pass
over lines, with delimited blocks read by running the reader again on their content. The PHP
implementation is `php/src/Parsing/AsciidocParser.php` with `php/src/Parsing/BlockBuilder.php`;
the cases are the `read` fixtures named `asciidoc: ...`. Blocks are emitted as in
`spec/formats/rst.md`, **Output** (text lines, items, extend, close, heading, paragraph, code,
table, dedent, delimited values).

Regexes are ASCII-only and run without a Unicode flag, except those marked **(u)**. Lengths are
in characters (code points).

## Lines

Line endings become `\n`; trailing spaces and tabs are removed from every line. A line is
**blank** when it is then empty. Tabs are kept.

## Reading

A run keeps `blank` (true at the start), `attached` (false), the pending block attributes and
block title (both empty), and the list kind `K` of the open list. The top-level run is the
**document** run. "The open list" means the open block is a list with lines in it. For each
line, the first rule that applies:

1. **Blank**: close the open block unless it is a list; set `blank`, clear `attached`.
2. **Comment block** `^\/{4,}$`: skip through the next line identical to it (or to the end).
   Nothing is closed.
3. **Comment or conditional line**: a line starting with `//`, or matching
   `^(?:ifdef|ifndef|ifeval|endif)::[^ \t]*\[.*\]$`, is skipped. Nothing is closed, so a
   paragraph continues across it; conditional content is always kept.
   **Include** `^include(?:-code)?::([^\[]*)\[.*\]$`: the file is not read; emit the paragraph
   `[include: <target>]`, target = group 1 trimmed with inline substitutions; set `blank`.
4. **List end**: the line's list kind is computed (Lists, below). When a list is open, `blank`
   is set and the kind differs from `K` (a non-item has no kind, so it always differs), close
   the list.
5. **Two-line section title**: the open block is not a list and has exactly one line, which
   starts with **(u)** `^[\p{L}\p{N}_]`; this line matches `^(=+|-+|~+|\^+|\++)$`; and the two
   lengths differ by at most 1. The open line is removed and becomes a heading of level 1 for
   `=`, 2 for `-`, 3 for `~`, 4 for `^`, 5 for `+`. When this is a level-1 title in the document
   run and nothing has been emitted yet, the **document header** is skipped. Set `blank`.
6. Let **start** be `blank` or `attached` or no block open; then clear `blank` and `attached`.
7. **Delimited block** `^(?:-{4,}|\.{4,}|\+{4,}|_{4,}|={4,}|\*{4,}|--|[|,:]={3,})$`, wherever
   it appears (it ends an open paragraph): its content is the lines up to the next line
   identical to it, or to the end. Close, then by delimiter:
   - `|===`, `,===`, `:===` (any count of `=`): a table (Tables), captioned by the pending title.
   - `++++…` (passthrough): dropped.
   - otherwise the pending title, when not empty, is a paragraph first; then `----…` (listing)
     and `....…` (literal) are code of the content as written (not dedented); `____…` (quote),
     `====…` (example), `****…` (sidebar) and `--` (open) run the reader on the content.

   The pending attributes and title are cleared, `blank` is set, and reading goes on after the
   closing line.
8. **Block attribute line** `^\[(|[#.%A-Za-z0-9_{,"'\[].*)\]$`, wherever it appears (so
   `[source,python]`, `[NOTE]`, `[[anchor]]`, `[%header]`): close; the pending attributes become
   the text between the outer brackets (replacing any before); set `blank`.
9. **List continuation**: a line that is exactly `+` is dropped and sets `attached`.
10. At a start only:
    - **Attribute entry** `^:!?[A-Za-z0-9_][A-Za-z0-9_-]*!?:(?:[ \t].*)?$`: defined (Attributes)
      and dropped. Set `blank`.
    - **Block title** `^\.([^. \t].*)$`: the pending title becomes group 1. Set `blank`.
    - **Heading** `^(={1,6}|#{1,6})[ \t]+(.+?)(?:[ \t]+\1)?$`: level = the length of group 1,
      text = group 2 (a closing run equal to the opening one is dropped). When it is `=` of
      level 1 in the document run and nothing has been emitted yet, the document header is
      skipped. The pending attributes and title are cleared; set `blank`.
    - **Block macro** `^[a-z][A-Za-z0-9_-]*::[^ \t]*\[.*\]$` (`image::`, `toc::`, `video::`) or a
      break `^(?:'{3,}|<{3,})$`: close and drop it; set `blank`.
    - Otherwise a block begins here: the pending title, when not empty, is emitted as a
      paragraph; the **style** is the pending attributes' leading `^([A-Za-z][A-Za-z0-9_-]*)`
      lowercased (empty when none); the pending attributes and title are cleared. When the line
      is not a list item and the style is `source`, `listing` or `literal`, or the line starts
      with a space or tab (a literal paragraph), this line and the following lines up to the
      next blank line, dedented, are code; set `blank`.
11. **Content**: a list item, at a start or with a list open, adds an item (when no list was
    open `K` becomes its kind); its text is the description text for a description item, else
    the item text. Else with a list open: extend (a continuation line). Else a text line.

At the end of a run, close.

**Document header**: the lines after the title up to (not including) the next blank line are
dropped (author line, revision line, comments), attribute entries among them being defined
first, and the open block is closed.

**Inline substitutions** apply to heading text, block titles emitted before a paragraph or a list
(not the title of a delimited block, which is emitted as written), paragraph lines, item texts and
continuation lines, table cells and captions, and include targets; never to code. In order:

1. Each `\{([A-Za-z0-9_][A-Za-z0-9_-]*)\}` whose name lowercased is defined becomes its value;
   an undefined reference stays as written.
2. `xref:([^ \t\[]+)\[([^\]]*)\]` becomes group 2 trimmed, or group 1 when that is empty.
3. `<<[^,>]+,[ \t]*([^>]+?)[ \t]*>>` becomes group 1 (`<<id>>` stays as written).
4. `(?:link:([^ \t\[]+)|\b((?:https?|ftp|irc):\/\/[^ \t\[]+|mailto:[^ \t\[]+))\[([^\]]*)\]`
   becomes `<text> (<url>)`, text = group 3 trimmed and url = group 1, else group 2; just the url
   when the text is empty. A bare URL without `[...]` stays as written.

## Attributes

An attribute entry is matched by `^:(!?)([A-Za-z0-9_][A-Za-z0-9_-]*)(!?):(?:[ \t]+(.*))?$`; the
name is group 2 lowercased. While the value (group 4, empty when absent) ends with ` \` and a
line follows, the ` \` is replaced by a space and the next line, trimmed, is appended (that line
is consumed). With a `!` in group 1 or 3 the name is undefined; otherwise its value is the
value after inline substitutions (so earlier attributes expand at definition), squashed.
Definitions apply to the lines after them, in document order, across nested blocks.

Before any definition, these built-in character attributes are defined (a document definition
replaces one, and `:name!:` removes it): `asterisk` `*`, `nbsp` U+00A0, `sp` a space, `empty`
and `blank` the empty string, `zwsp` U+200B, `amp` `&`, `lt` `<`, `gt` `>`, `quot` `"`, `apos`
`'`, `plus` `+`, `caret` `^`, `tilde` `~`, `backtick` `` ` ``, `startsb` `[`, `endsb` `]`, `vbar`
`|`, `pp` `++`, `cpp` `C++`, `deg` U+00B0, `two-colons` `::`, `two-semicolons` `;;`, `brvbar`
U+00A6, `ldquo` U+201C, `rdquo` U+201D, `lsquo` U+2018, `rsquo` U+2019, `wj` U+2060.

## Lists

A line's kind, first match:

| Pattern | Kind |
| --- | --- |
| `^[ \t]*(\*{1,5}\|-\|\.{1,5}\|\d+\.\|[A-Za-z]\.\|[ivxIVX]+\)\|<(?:\d+\|\.)>)[ \t]+(?=[^ \t])` | `unordered` when group 1 starts with `*` or is `-`; `callout` when it starts with `<`; else `ordered` |
| `^[ \t]*([^ \t].*?)(?::{2,4}\|;;)(?:[ \t]+(.*))?$` | `description` |

(`\|` in the table is `|`.) The **item text** is the first pattern's match trimmed, one space,
and the rest of the line, all trimmed. The **description text** is group 1 trimmed, `:`, then a
space and group 2 trimmed when that is not empty: `CPU:: The brain.` gives `CPU: The brain.`,
and a definition on the following lines extends it. Nested items (`**`, `..`) stay in the same
list; items of another kind start a new list only after a blank line.

## Tables

The format is group 1 of `\bformat="?(csv|dsv|tsv)\b` in the attributes, else `psv` for `|`,
`csv` for `,` and `dsv` for `:`. The first row is the header when the attributes match
`%header\b|\b(?:options|opts)="?[^"\]]*\bheader\b`, or when the content's first line is not
blank, its second line exists and is blank, and the attributes do not match
`%noheader\b|\b(?:options|opts)="?[^"\]]*\bnoheader\b`. The table is emitted with the pending
title as caption and the remaining rows.

**CSV, DSV, TSV**: each non-blank content line is one row: delimited values with `,` for CSV;
for DSV and TSV the line split on every `:` or tab, each part trimmed.

**PSV**: the content lines are joined with `\n` and split at every `|` not preceded by `\`. The
piece before the first `|` is dropped, except that when it, trimmed, matches the cell spec
`^(?:(\d+)\*)?(?:(\d+)?(?:\.\d+)?\+)?[<^>]?(?:\.[<^>])?[adehlmsv]?$` it is the first cell's
spec. Each later piece is a cell; every piece but the last may end with the next cell's spec,
matched by `[ \t\n]((?:\d+\*)?(?:\d*(?:\.\d+)?\+)?[<^>]?(?:\.[<^>])?[adehlmsv]?)\z` when group 1
is not empty, and cut off. In a cell's text `\|` becomes `|`; the text is squashed. A spec with
`n*` repeats the cell `n` times; one with `n+` (group 2 of the cell spec) follows each copy with
`n - 1` empty cells. Row spans (`.n+`), alignment and styles are otherwise ignored.

The column count is, from a `cols` attribute (group 1 of `\bcols="([^"]*)"`, else group 2 of
`\bcols=([^,\]]*)`, trimmed): the number itself when it is all digits; otherwise the sum over
its entries split on `,` or `;` of `n` for an entry starting `\s*(\d+)\*`, else 1. Without
`cols`, it is the number of cells (after expansion) in the first non-blank content line read
alone. The cells are cut into rows of that many (at least 1), the last row possibly shorter.

## Not read

Other inline markup and inline macros (`image:`, `kbd:`, `footnote:`) stay in the text; `NOTE:`
admonition paragraphs keep their label. Nested tables (`!===`), multi-line CSV fields, the
`[comment]` style and `[source]` on an open block are not read. The text of an indented line after
a blank line in a list is a literal paragraph (code), not part of the item.
