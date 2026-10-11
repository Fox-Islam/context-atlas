# reStructuredText

How a reStructuredText document becomes blocks (`spec/behaviour.md`, Blocks). No docutils: a
single pass over lines with a few look-aheads. The PHP implementation is
`php/src/Parsing/RstParser.php` with `php/src/Parsing/BlockBuilder.php`; the cases are the
`read` fixtures named `rst: ...`. The **Output** section below is shared by
`spec/formats/asciidoc.md` and `spec/formats/org.md`.

## Output

The readers of reST, AsciiDoc and Org emit blocks through one collector holding the **open
block**: a list of lines and a flag for whether it is a list.

- **Text line**: closes an open list first, then adds the line trimmed (Trim of
  `spec/behaviour.md`).
- **Item**: closes an open non-list block first, marks the block a list, and adds the item's
  text trimmed.
- **Extend**: joins the line, trimmed, to the last open line with one space (no space when one
  side is empty); with no open line it adds the line.
- **Replace last**: replaces the last open line; an empty replacement removes it.
- **Close**: drops empty open lines; if any remain, emits a paragraph of them joined with `\n`
  for a list or one space otherwise. The block resets to empty and not a list.
- **Heading** (text, level), **paragraph** (text): close, then emit the text trimmed unless it is
  empty.
- **Code** (lines): close, drop leading and trailing lines that trim to empty, and emit the rest
  joined with `\n` unless none remain. Code text is otherwise kept as given.
- **Table** (caption, header rows, rows): close. Every cell is squashed (`spec/behaviour.md`).
  The header is the header rows merged column by column: cell `c` of the header joins the
  non-empty cells `c` of the header rows with one space (so one header row is itself). Rows
  whose cells join to the empty string are dropped. With no header cells and no rows nothing is
  emitted. Otherwise the text is the lines `Table: <caption>` (when the caption is not empty),
  the header cells joined with ` | ` (when there are any), and each row's cells joined with
  ` | `, each line with trailing spaces removed (so a row ending in an empty cell ends in `|`),
  joined with `\n`; `meta` is `{"caption": caption, "header": [...], "rows": [[...]]}`,
  always with all three keys (caption `""` when there is none). Rows may differ in length.
- **Dedent** (lines): remove from every line the smallest count of leading spaces and tabs among
  the lines that are not blank (spaces and tabs only), each tab counting as one character.
- **Delimited values** (line, separator): a field opening with `"` after spaces and tabs runs to
  the next `"` not doubled, `""` standing for `"`, and anything after the closing quote up to the
  next separator is dropped; any other field runs to the next separator and is trimmed. A line
  ending with the separator ends with an empty field.

Lengths and offsets are in characters (code points) unless stated as bytes; regexes marked
**(u)** need Unicode matching, all others are ASCII-only and run without a Unicode flag.

## Lines

Line endings become `\n`. In every line each tab is replaced by spaces up to the next multiple
of 8 columns, counting characters, then trailing spaces and tabs are removed. A line is
**blank** when it is then empty. A line's **indent** is its count of leading spaces.

## Reading

The reader runs over a list of lines; directive and footnote bodies are read by running it
again on their own lines, sharing the collector, the title styles and the table caption. One run
keeps: `blank` (true at the start), the pending literal indent (none), the list base indent
`B` and list kind `K` (taken from the item that opened the open list), and the paragraph indent
`P`. "The open list" means the open block is a list with lines in it. For each line:

1. **Blank**: rewrite the literal marker (below); if there was one, the pending literal indent
   becomes `P`. Close the open block unless it is a list. Set `blank`, go to the next line.
2. **Literal block**: when a literal indent `L` is pending and the line's indent is greater
   than `L`, the block runs from this line through the last following line that is not blank
   and is indented more than `L`, stopping at the first non-blank line indented `L` or less.
   Emit its lines dedented as code and continue after it with the pending indent cleared and
   `blank` unchanged. Otherwise clear the pending indent.
3. **Item**: the line's item (below), or, when it has none and a term is allowed, a
   **definition term**: the next line exists, is not blank and is indented more than this line,
   and this line is neither an item nor explicit markup. A term is allowed when `blank` is set,
   or no block is open, or the open list has kind `term` and this line's indent equals `B`.
4. **List end**: when a list is open, close it if this line is an item that does not continue
   it, or is not an item while `blank` is set and its indent is at most `B`. An item continues
   the list when its indent is greater than `B`, or equal to `B` with the same kind as `K`.
5. **Block start**: when `blank` is set or no block is open (after step 4), try the block
   constructs below in order; the first that matches consumes its lines, `blank` is set, and the
   next line read is the one after them.
6. Otherwise:
   - An item, at a block start or with a list open: when no list is open, `B` = this indent and
     `K` = the item's kind. A term adds the item `<line>:` (the line as is when it already ends
     with `:`), then extends it with the next line, which is consumed, and `P` = that line's
     indent. Any other item adds its text and `P` = its content column.
   - Else with a list open: extend (a continuation of the last item); when `blank` was set,
     `P` = this indent.
   - Else: a text line; when no block was open, `P` = this indent.

   Clear `blank`.

At the end of a run, rewrite the literal marker and close.

**Literal marker**: when the last open line ends with `::`, it is replaced by: nothing when it
is exactly `::`; the line without `::` and with trailing whitespace removed when `::` follows
whitespace **(u)** `\s::$`; the line without its last `:` otherwise. Only a rewrite at a blank
line opens a literal block.

### Items

Tried in order on the whole line (`\|` in the table is `|`).

| Pattern | Kind |
| --- | --- |
| **(u)** `^ *[-*+•‣⁃](?: +\|$)` | `bullet <marker>` (the marker character, so `bullet -` and `bullet *` differ) |
| `^ *(?:\(?(?:\d+\|#\|[A-Za-z]\|[ivxlcdmIVXLCDM]+)[.)]\|\((?:\d+\|#\|[A-Za-z]\|[ivxlcdmIVXLCDM]+)\))(?: +\|$)` | `enumerated` |
| `^ *:(?:[^: ]\|[^: ][^:]*[^: ]):(?: +\|$)` | `field` |
| `^( *)\|(?: +\|$)` | `line` |

For the first three the item text is the match trimmed, one space and the rest of the line, all
trimmed; the content column is the match's length, plus 1 when the match does not end with a
space. For a line block the text is the rest of the line after the match (the `|` is dropped)
and the content column is the indent.

## Block constructs

Tried at a block start, in this order.

1. **Section title** (indent 0 only). An **adornment** is a line matching
   ``^([!-\/:-@\[-`{-~])\1*$`` (one printable ASCII punctuation character repeated).
   - *Overline*: this line is an adornment, the next line trimmed of spaces is not empty, the
     line after it is identical to this one, and the title's length is at most this line's.
     The heading is the trimmed next line; three lines are consumed; the style is the
     character followed by `o`.
   - *Underline*: this line is not an adornment, the next line is one, and the next line's
     length is at least this line's (characters, so `Café` needs 4) or is at least 4, as
     docutils accepts a short underline of 4 or more. The heading is this line;
     two lines are consumed; the style is the character followed by `u`.

   A style's level is its position, from 1, in the list of styles in order of first appearance
   in the document; a new style is appended. So the first style seen (the document title's) is
   level 1, and a style seen again returns to its level. Levels have no maximum.
2. **Transition** (indent 0 only): an adornment of at least 4 characters. Close and drop it.
3. **Explicit markup** `^ *\.\.(?: |$)`. A line that is exactly `..` (after its indent) followed
   by a blank line or the end is dropped alone. Otherwise the construct is this line and the
   following lines that are blank or indented more than this line, ending at its last non-blank
   line; its **body** is the lines after the first. Nothing is closed unless something is
   emitted. By the first line:
   - **Directive** `^ *\.\. +([A-Za-z0-9]+(?:[-_.:+][A-Za-z0-9]+)*)::(?: +(.*))?$`: name =
     group 1 lowercased, argument = group 2 trimmed of spaces. See Directives.
   - **Footnote or citation** `^ *\.\. +\[([^\]]+)\](?: +(.*))?$`: run the reader on the line
     `[<label>] <group 2>` followed by the body dedented.
   - Anything else (hyperlink targets `.. _name:`, substitution definitions `.. |name| ...`,
     comments) is dropped.
4. **Anonymous target**: the line after its indent matches `^__(?: |$)`. Dropped with the
   following lines that are blank or indented more, as for explicit markup.
5. **Grid table**: the line after its indent matches `^\+(?:-+\+)+$`. See Tables.
6. **Simple table**: the line after its indent matches `^=+(?: +=+)+$` (two or more columns),
   when it closes (see Tables); otherwise this is not a block construct.
7. **Doctest block**: the line after its indent matches `^>>>(?: |$)`. This line and the
   following lines up to the next blank line, dedented, are code.

## Directives

Options come first in the body: while lines match `^ +:([^: ]|[^: ][^:]*[^: ]):(?: +(.*))?$`
they are options (name group 1 lowercased, value group 2), and once one is found, lines indented
more than it are skipped too; the first other line, or a blank line, ends them (a body starting
with a blank line has no options). The **content** is the rest of the body, dedented. By name,
first match:

| Names | Result |
| --- | --- |
| `code`, `code-block`, `sourcecode`, `parsed-literal`, `math`, `doctest`, `testcode`, `testoutput` | code of the content (the argument, a language, is dropped) |
| `attention`, `caution`, `danger`, `error`, `hint`, `important`, `note`, `tip`, `warning`, `seealso` | run the reader on the argument line followed by the content lines, so an argument and the content lines right under it make one paragraph |
| `admonition`, `rubric`, `topic`, `sidebar`; or a name whose part after its last `:` is one of `function`, `class`, `method`, `staticmethod`, `classmethod`, `attribute`, `property`, `data`, `exception`, `decorator`, `describe`, `object`, `envvar`, `option`, `confval` (so `py:function`, `c:function`) | the argument as a paragraph, then run the reader on the content |
| `table` | the table caption becomes the argument while the reader runs on the content, then is reset to empty |
| `list-table` | a table of the list-table rows, header rows = option `header-rows` as an integer (0 when absent), caption = the argument |
| `csv-table` | a table, caption = the argument (below) |
| `toctree`, `index`, `image`, `include`, `literalinclude`, `raw`, `contents`, `highlight`, `sectnum`, `header`, `footer`, `meta`, `title`, `default-role`, `role`, `class`, `target-notes`, `module`, `currentmodule`, `testsetup`, `testcleanup` | dropped |
| any other | run the reader on the content; the argument is dropped (so `versionchanged`, `deprecated`, `figure`, `only`, `container` keep only their content) |

**list-table rows**: for each content line: a line at indent 0 matching `^[-*+](?: +|$)` starts a
new row, and the line continues with its marker replaced by spaces. Lines before the first row,
and blank lines, are skipped. A line matching `^ *[-*+](?: +|$)(.*)$` whose indent equals the
cell indent (the indent of the first such line, which sets it) adds a cell of group 1;
another line, when the row has a cell, joins the last cell with one space (both trimmed of
spaces).

**csv-table**: the delimiter is option `delim` when it is one byte, `\t` for `tab`, a space for
`space`, else `,`. When option `header` is present its value, read as delimited values with
`,`, is the first row. Each content line that is not blank is a row of delimited values. Header
rows = 1 when `header` is present (and any rows exist), plus option `header-rows` as an integer.
Quoted fields spanning lines and `file`/`url` sources are not read.

A table emitted while the reader runs inside `table` takes that caption when it has none of its
own.

## Tables

**Grid**: column bounds are the byte offsets of `+` in the border line after its indent. The
table runs over the following lines that have the same indent and whose character at that
indent is `+` or `|`. A line after the indent matching `^\+(?:[-=]+\+)+$` ends the current row
(when it has cells); one containing `=` sets the header row count to the number of rows so far
(the last such line wins; 0 when there is none). Any other line adds to the current row's
cells: walking bounds `b = 1..last` with `from = 0`, skip `b` when `b` is not the last bound and
the line's character at offset `bounds[b]` (characters, after the indent) is neither `|` nor
`+`; otherwise the piece is the characters strictly between `bounds[from]` and `bounds[b]`,
trimmed of spaces, with a leading `^\| +` (a line block inside the cell) removed. Unless the
piece matches `^[-=+]+$` (part of a row separator under a row span), cell `from` becomes its
text so far and the piece joined with a space, trimmed of spaces; cells `from` to `b - 1` exist
(empty) from then on; `from = b`. Text spanning columns thus lands in the first of them, the
others staying empty. Row spans are not followed.

**Simple**: the table runs from the border to the first blank line or the end. The lines in it,
other than the first, with the border's indent and matching the border pattern after it are
**borders**; unless there is at least one and the last line of the table is one, this is not a
table. Column starts are the byte offsets of each run of `=` in the first border. For each line
between the first and the last border: an inner border sets the header row count to the number
of rows so far when it is the first inner border (no inner border: 0 header rows); a line
matching `^[- ]*$` after the indent (blank or column-span underlines) is skipped. Other lines are
cut, after the indent, into one piece per column: from its start to the next column's start, the
last column to the end of the line (character offsets), each trimmed of spaces. When the first
piece is empty and a row exists, each piece joins the previous row's cell with a space, trimmed
of spaces (a continuation line); otherwise the pieces are a new row.

## Not read

An underline shorter than both its title and 4 characters, or under a line that is not at a
block start, leaves both lines as paragraph text.
Definition-list classifiers (`term : classifier`) stay in the term. Quoted literal blocks,
option lists, roles, substitution references and hyperlink references are left as text. East
Asian wide characters count as one character, not two columns. The RFC 2822 header of a PEP is
an ordinary paragraph.
