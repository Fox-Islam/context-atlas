# Org

How an Org document becomes blocks (`spec/behaviour.md`, Blocks). No Emacs: one pass over
lines, with greater blocks read by running the reader again on their content. The PHP
implementation is `php/src/Parsing/OrgParser.php` with `php/src/Parsing/BlockBuilder.php`; the
cases are the `read` fixtures named `org: ...`. Blocks are emitted as in `spec/formats/rst.md`,
**Output** (text lines, items, extend, close, heading, paragraph, code, table, dedent).

Regexes are ASCII-only and run without a Unicode flag, except those marked **(u)**; `(?i)` marks
ASCII case-insensitive matching. Lengths are in characters (code points).

## Lines and document settings

Line endings become `\n`; trailing spaces and tabs are removed from every line. A line is
**blank** when it is then empty. A line's **indent** is its count of leading spaces and tabs,
each counting 1.

Before reading, every line is scanned:

- **TODO keywords**: `TODO` and `DONE`, plus, from each line matching
  `(?i)^#\+(?:seq_|typ_)?todo:[ \t]*(.*)$`, the words of group 1 split on runs of spaces and
  tabs, each with a trailing `\(.*\)$` (a fast-access key such as `NEXT(n)`) removed, skipping
  empty words and `|`. Keywords are case-sensitive.
- **Shift**: 1 when some line matches `(?i)^#\+title:[ \t]*[^ \t]`, else 0. Heading levels are
  the star count plus the shift, so with a title it is level 1 and `*` headings are level 2.

## Reading

A run keeps the list base indent `B` and kind `K` (from the item that opened the open list) and
the pending caption (empty). "The open list" means the open block is a list with lines in it.
For each line:

1. **Blank**: close the open block unless it is a list.
2. **List end**: compute the line's item kind (Lists). When a list is open, close it if the line
   is an item that does not continue it, or is not an item and its indent is at most `B` (a
   line indented no deeper than the bullet ends the list, blank line or not). An item continues
   the list when its indent is greater than `B`, or equal to `B` with the same kind as `K`.
3. **Element**: the first element below that matches consumes its lines; reading goes on after
   them.
4. Otherwise clear the pending caption, then:
   - an item: when no list is open, `B` = its indent and `K` = its kind; add the item text;
   - else with a list open: extend (a continuation line);
   - else: when the line matches `^\[fn:[^\]]+\]` (a footnote definition), close first; add a
     text line.

At the end of a run, close.

## Elements

Tried in this order.

1. **Heading** `^(\*+)[ \t]+(.*)$`: level = the star count plus the shift. The text is group 2
   with, in order: a leading TODO keyword followed by spaces/tabs or the end removed
   (`^(?:K1|K2|…)(?:[ \t]+|$)`, keywords regex-escaped, in the order collected); a leading
   priority `^\[#[A-Za-z0-9]\](?:[ \t]+|$)` removed. If the text then matches
   `^COMMENT(?:[ \t]|$)`, close and drop this heading and every line up to the next heading of at
   most the same star count (or the end). Otherwise remove trailing tags **(u)**
   `[ \t]+:(?:[\p{L}\p{N}_@#%]+:)+$`, then every statistics cookie `[ \t]*\[\d*(?:\/\d*|%)\]`,
   squash, and emit the heading (nothing when the text is empty). The pending caption is cleared.
2. **Block** `(?i)^[ \t]*#\+begin_([^ \t]+)(?:[ \t].*)?$`: close. The block ends at the next line
   matching `(?i)^[ \t]*#\+end_<name>$` (name regex-escaped). With no such line only this line is
   dropped. Otherwise, by the name lowercased: `src` and `example` are code of the content
   dedented, with a leading `,` removed from lines matching `^([ \t]*),(\*|#\+)` (Org's escape for
   `*` and `#+`); `export` and `comment` are dropped; any other (`quote`, `verse`, `center`,
   special blocks) runs the reader on the content dedented.
3. **Keyword** `^[ \t]*#\+([^ \t]+?):(?:[ \t]+(.*))?$`: name = group 1 lowercased, value = group
   2 trimmed. The first `title` with a non-empty value emits a level-1 heading of the value
   (later ones are dropped); `caption` sets the pending caption to the value. Every keyword other
   than `caption`, `name` and `attr_…` closes the open block. The line is dropped.
4. **Comment** `^[ \t]*#(?:[ \t]|$)`: dropped; nothing is closed.
5. **Drawer** `^[ \t]*:[A-Za-z0-9_-]+:$`: when a line matching `(?i)^[ \t]*:end:$` follows before
   the next heading, this line through that one are dropped and nothing is closed; otherwise this
   is not an element.
6. **Planning line** `^[ \t]*(?:(?:SCHEDULED|DEADLINE|CLOSED):[ \t]*[<\[][^>\]]*[>\]][ \t]*)+$`:
   dropped.
7. **Fixed-width area**: consecutive lines matching `^[ \t]*:(?: |$)`, each with
   `^[ \t]*: ?` removed, are code.
8. **Table**: consecutive lines matching `^[ \t]*\|` (Tables).
9. **Rule** `^[ \t]*-{5,}$`: close and drop it.

## Lists

`^([ \t]*)(?:([-+])|(\d+[.)])|(?<=[ \t])(\*))(?:[ \t]+|$)` makes a line an item, unless it is a
rule; group 3 makes it `ordered`, otherwise `unordered` (so `*` is a bullet only when indented;
at column 0 it is a heading). The item text is the match trimmed, one space and the rest of the
line, all trimmed. Description items (`- term :: text`) and checkboxes stay as written.
Alphabetical bullets are not read.

## Tables

Each line is trimmed. A line starting `|-` is a rule: when no header has been set and at least
one row was read, the header row count becomes the number of rows so far. Any other line drops
its first character (`|`) and, when it ends with `|` and is longer than one character, its last;
the rest split on every `|`, each piece trimmed, is a row, unless every non-empty cell matches
`^<[lrc]?\d*>$` and at least one cell is non-empty (a column-width row), which is dropped. When
no rule set a header, or no row follows it, there is no header. The table is emitted with the
pending caption, which is then cleared. `#+TBLFM:` lines after a table are keywords. `table.el`
tables (`+---+`) are not read.

## Not read

Inline markup, links, macros (`{{{…}}}`), LaTeX fragments and entities stay in the text. A
heading with an empty title is not emitted. Footnote definitions end at a blank line like any
paragraph. Inline tasks, `#+INCLUDE:` targets and babel results are not followed (results under
`#+RESULTS:` are read like any other lines).
