# HTML

How an HTML document becomes blocks (`spec/behaviour.md`, Blocks). No DOM or HTML library is
used: a tokenizer feeds a stack of open elements that builds a tree, and a reader walks the
tree. The PHP implementation is `php/src/Parsing/HtmlParser.php` with `php/src/Parsing/Html/`;
the cases are the `read` fixtures named `html ...`.

## Input

The input is UTF-8 (PHP scrubs invalid sequences with `mb_scrub`; output for invalid input is
not part of the contract). Line endings become `\n`. The tokenizer works on positions in the
string, and every character it looks for is ASCII, so byte offsets (PHP), UTF-16 code units
(JavaScript) and code points (Python) give the same tokens. Regexes below are ASCII-only and
run without a Unicode flag; "anchored at `p`" means PHP `\G` with an offset, JavaScript a
sticky (`y`) regex with `lastIndex = p`, Python `pattern.match(text, p)`. Tag and attribute
names are lowercased ASCII-only. **Space** in tags means `[\t\n\f\r ]`.

## Character references

Applied once, left to right, to text and to attribute values (never decoded twice), by the
regex `&(?:#([0-9]+)|#[xX]([0-9A-Fa-f]+)|([A-Za-z][A-Za-z0-9]*));`:

- **Numeric** (groups 1 and 2): leading zeros are ignored; more than 8 remaining digits, or a
  value above U+10FFFF, 0, or a surrogate (U+D800-U+DFFF) gives U+FFFD. Values 128-159 found
  in `Entities::WINDOWS_1252` map to that code point (as browsers read them); any other value
  is that code point.
- **Named** (group 3): a name in `Entities::NAMED` (the 252 HTML 4 names plus `apos`;
  case-sensitive) becomes its code point; any other name is left as written.
- No semicolon, no match: `&amp`, `&#;`, `&#x;` and `a&b` stay as written.

Ports copy both tables from `php/src/Parsing/Html/Entities.php`.

## Tokens

From position `p = 0`, text runs to the next `<`. Each text run is decoded and appended to the
current element. At a `<`, the first rule that matches decides:

1. **Comment** `<!--`: dropped up to and including the next `-->` searched from `p + 4`, or to
   the end.
2. **Bogus**: `<!` (doctype, `<![CDATA[`), `<?`, or `</` not followed by an ASCII letter:
   dropped up to and including the next `>` searched from `p + 2`, or to the end. So
   `<![CDATA[x]]>` drops `x]]`... up to the first `>`, as HTML does outside SVG.
3. **End tag**: `<\/([A-Za-z][^\t\n\f\r \/>]*)[^>]*(?:>|\z)` anchored at `p`; name is group 1.
4. **Start tag**: `<([A-Za-z][^\t\n\f\r \/>]*)` anchored at `p`, then attributes (below).
5. Otherwise `<` is text.

**Attributes**, repeating from just after the tag name: skip space; at the end of the input the
whole tag is dropped (nothing is created) and reading ends; `>` ends the tag; `/>` ends it as
**self-closing**; any other `/` is skipped. Else the name is
`[^\t\n\f\r \/>][^\t\n\f\r \/>=]*`; if `[\t\n\f\r ]*=[\t\n\f\r ]*` follows, the value is
`"([^"]*)(?:"|\z)`, `'([^']*)(?:'|\z)` or `([^\t\n\f\r >]*)`, decoded; otherwise the value is
empty. An unterminated quote therefore runs to the end and drops the tag. The first of a
repeated attribute name wins.

**Raw text**: after a start tag (not self-closing) of `iframe`, `noembed`, `noframes`,
`noscript`, `script`, `style`, `textarea`, `title` or `xmp`, everything up to the first
`</name` (ASCII case-insensitive) followed by space, `/`, `>` or the end is one text child of
that element, decoded only for `textarea` and `title`. The end tag is then read normally. With
no such end tag the raw text runs to the end.

## Tree

A document node at the bottom of a stack of open elements; the **current** element is the top.
Text is appended to the current element, merging with a text child just before it. The
document node never closes.

**Closing `targets` within `boundaries`**: walk the stack from the top; at the first element
named in `targets`, pop it and everything above it; if an element named in `boundaries` comes
first, or the stack runs out, nothing happens. **Scope** is `button`, `caption`, `html`,
`table`, `td`, `template`, `th`.

**Start tag** `name`, in order:

1. If `name` is one of `address article aside blockquote center dd details dialog dir div dl dt
   fieldset figcaption figure footer form h1-h6 header hgroup hr li main menu nav ol p pre
   search section summary table ul`: close `p` within scope.
2. `li`: close `li` within scope + `menu ol ul`. `dd`/`dt`: close `dd dt` within scope + `dl`.
   `h1`-`h6`: if the current element is `h1`-`h6`, pop it. `tr`: close `tr` within
   `table tbody tfoot thead`. `td`/`th`: close `td th` within `table tbody tfoot thead tr`.
   `tbody`/`tfoot`/`thead`: close `tbody tfoot thead` within `table`, and if none closed,
   close `tr` within `table`.
3. Append the element to the current element. Push it unless it is self-closing (any element:
   `<div/>` is empty) or void: `area base br col embed hr img input keygen link meta param
   source track wbr`.

**End tag** `name`: `</br>` acts as `<br>`. Otherwise close `name` within: nothing (the stack
runs out) for `table`; `table` for `caption tbody td tfoot th thead tr`; scope for anything
else. An end tag with nothing to close is ignored, so a stray `</span>` or a second `</p>` does
nothing, and `</div>` inside a cell cannot close a `div` outside the table. Elements open at the
end of the input end there.

## Where reading starts

The first element in document order (pre-order, the whole tree) named `main` or whose `role`
is `main`; else the first `body`; else the document node. An element **opens an article** when
it is `article` or `main` or has role `main`; the start element counts, its ancestors do not.
The start element is read even if the rules below would drop it.
Attribute tokens (`role`, `aria-hidden`) are trimmed of space and lowercased ASCII-only.

## Dropped elements

An element and everything in it is skipped when it:

- is `aside audio button canvas head iframe map nav noscript object script select style svg
  template textarea title video`, except an `aside` with a class token containing `footnote`
  or with role `note` (footnotes and notes are content), a `button` inside a heading's line
  text (accordion headings put their text in one), and a `button` that is the only content
  (other children being whitespace text) of a `summary` or `dt` read by the walk;
- is a `form` with no `main`, `article` or `h1`-`h6` descendant (a form wrapping a page is
  read through; its controls are dropped by the rule above, `input` is void);
- has a `hidden` or `popover` attribute (any value), or `aria-hidden` equal to `true`;
- has role `complementary`, `navigation`, `search` or `tooltip`;
- has a class token exactly equal to `catlinks`, `mw-editsection`, `mw-jump-link`, `navbox`,
  `noprint` or `printfooter` (furniture), or `print-only`, `printonly`, `show-for-print`,
  `visible-print`, `visible-print-block` or `visible-print-inline` (print only);
- has a class token exactly equal to `d-none`, `hidden`, `hide`, `invisible`, `is-hidden`,
  `js-hidden` or `u-hidden`, and no class token matching `:|^d-(?:sm|md|lg|xl|xxl)-` (a
  responsive class such as Tailwind `md:block` or Bootstrap `d-lg-block` may show it again);
- has a `style` attribute which, with space removed and ASCII letters lowercased, split at
  `;`, has a part equal to `display:none`, `display:none!important`, `visibility:hidden` or
  `visibility:hidden!important`, unless it has a `math` descendant (MathML is commonly hidden
  next to an image fallback, which is `aria-hidden`);
- is `header` or `footer`, or has role `banner` or `contentinfo`, and no element between the
  start element (inclusive) and it opens an article;
- is a **permalink**: an `a` whose `href` starts with `#` and whose text (as for `pre`, below)
  squashes to exactly `#`, `§` or `¶`.

**Tokens** of `class` and `itemprop` are the value split at runs of space, case kept (class
matching is case-sensitive). Screen-reader text (`sr-only`, `visually-hidden` and the like) is
kept: it often carries meaning. Tooltip and popover classes are not dropped (no class name
separates the hidden text from the visible trigger reliably); the `popover` attribute is.
Dropping applies everywhere below: in the walk, in headings, lists, cells and `pre`.

## Blocks

The walk keeps a text **buffer** and a stack of **open blocks**, starting with the start
element. **Flushing** squashes the buffer, emits it as a paragraph if not empty, and empties
it. For each child of the start element, in order: text is added to the buffer; a dropped
element is skipped; a speaker element is read as in Speakers; any other element is read as
follows, then the walk continues with the next child. At the end, flush.

| Element | Reading |
| --- | --- |
| `br` | add `\n` to the buffer (it collapses to a space) |
| `math` | add its maths text (below) to the buffer |
| `h1`-`h6`, or role `heading` | flush; heading of level n (for role `heading`: `aria-level` when it is `1`-`6` after trimming, else 2), text = squash(line text, buttons kept); then flush |
| `dl` that is a marker list (below) | flush; marker lines (below), emitted like a list; flush |
| `pre` | flush; code block (below); flush |
| `table` | flush; table block (below); flush |
| `ul`, `ol`, `menu` | flush; list lines (below); emit them as one paragraph joined by `\n`; flush |
| block: `address article blockquote body caption center dd details dialog dir div dl dt fieldset figcaption figure footer header hgroup hr html legend li main p search section summary tbody td tfoot th thead tr` | flush; push it on the open blocks; walk its children; flush; pop it; if it is the current speaker's scope, clear the speaker |
| anything else (inline or unknown) | add its script mark (below) to the buffer, then walk its children into the same buffer |

A block is emitted only when its text trims to something. `img` and other void elements add
nothing. Squash and trim are those of `spec/behaviour.md` (Unicode whitespace, so `&nbsp;`
collapses too).

**Line text** of an element: its text children as written, plus for each child element: nothing
if dropped; a space for `br`; its maths text for `math`; space + its line text + space for a
heading, `pre`, `table`, list or block element; otherwise its script mark (given the line text
so far) then its line text. Callers squash it. Role `heading` is not a block inside line text.

**Script mark** of an element, given the text before it: for a `sup` or `sub` whose squashed
line text matches `^[-+−]?(?:[0-9][0-9.,]*|\p{L})$` (Unicode) and whose preceding text ends
with a code point in category `L` or `N`: `^` for `sup`, `_` for `sub`; otherwise nothing.
A footnote reference, a `sup` whose only non-whitespace child is an `a` whose `href` contains
`#`, gets nothing. So `10<sup>23</sup>` reads `10^23`, `H<sub>2</sub>O` `H_2O`, while
`1<sup>st</sup>` and `W3C<sup>®</sup>` stay `1st` and `W3C®`.

**Maths text** of a `math`: its `alttext` trimmed when not empty, with a whole-value TeX
wrapper `^\{\\displaystyle\s*(.*)\}$` (dot matches newlines) replaced by group 1 trimmed;
otherwise its text children, recursively, skipping `annotation` and `annotation-xml`,
squashed. Speaker elements are not special inside line text.

**Repeated link rows**: a paragraph (a list paragraph included) is a **link row** when the
walk or list that built it met at least one `a` element (not dropped), no `a` whose squashed
line text has more than 4 words, and every line of its text, after removing a leading
`^\s*(?:-|-?[0-9]+\.)\s` and trimming, has at most 8 whitespace-separated words and does not
end in `.`, `!` or `?`. Its **key** is its text with every run of `[0-9]` removed, squashed;
a key with no ASCII letter makes it no link row. After the walk, every link row whose key
belongs to at least 3 link rows is removed (before the title rule). This drops per-item rows
such as "Link to this speech · In context · Individually · (Citation: …, c387)" and keeps a
"See also" list, which appears once, and footnote markers such as `[1]`, which have no letter.
A "link met" for the walk's buffer is an `a` child read by the walk since the last flush; for a
list, an `a` read inside its items since the list paragraph was last emitted.

**Title**: when no heading of level 1 was emitted, the first `title` element in document order,
not looking inside `svg`, gives a level-1 heading in front of all blocks: its text children
joined and squashed, if not empty.

### Speakers

A **speaker element** has a class token matching
`(?:^|[-_])(?:speakers?|orateur|oratore|orador|redner|sprecher|発言者)(?:[-_]+(?:name|nom|nome|nombre))?$`
(Unicode; so `speaker`, `debate-speech__speaker`, `speaker-name`, `orateur_nom`, not
`speaker-and-content`, `speaker__position` or `intervenant`, which marks a whole speech), or
an `itemprop` token `author`. Met in the walk (not in line text, lists or cells), it is not
read as text:

1. **Full** = squash(its line text) without a trailing run of `[\s\-–—:.,;]` (Unicode
   whitespace). If empty, the element is ignored (the speaker stays).
2. **Name** = the same for its first descendant (document order) with a class token
   containing `name`, when that is not empty; else the full text.
3. Note whether the buffer trims to nothing, flush, then the speaker becomes
   `{"speaker": name}`, plus `"speaker_full": full` when it differs from the name.
4. Its **scope** is the top open block, except when that is a `p`, the buffer was empty, no
   block was emitted since the `p` opened, and an open block lies below it: then the scope is
   that block (the speaker opens the speech's first paragraph, so the speech is the block
   around it).
5. The next paragraph flushed from the buffer loses a leading run of `[\s\-–—:.,;]`
   (`M. Dupont. - Texte` reads `Texte`).

While a speaker is set, every emitted paragraph (lists included; not headings, code or
tables) gets it as its meta. It is replaced by the next speaker element and cleared when its
scope's block ends. Paragraphs with no speaker are left to the `Name:` prefix pass
(`spec/prose.md`). A `<dt>`/`<dd>` pattern is not read as speakers: it cannot be told apart
from a glossary.

### Lists

A list at depth `d` (0 for a list read by the walk) has a counter starting at 1, or for `ol` at
its `start` attribute when that, trimmed of space, matches `^-?[0-9]{1,9}$`. Its children in
order, with **indent** `"  " × d` and **marker** `<counter>. ` for `ol`, `- ` otherwise:

- text that squashes to something: the line indent + marker + text, counter up by one;
  whitespace text is ignored;
- a dropped element: skipped;
- `ul`/`ol`/`menu`: its lines at depth `d + 1`, its own counter;
- `li`: an item (below), then the counter goes up by one, whether or not it gave a line;
- any other element: its children are read by these same rules, same depth and counter.

An **item** starts with prefix = indent + marker and empty text, and reads its children in
order: text is added; a dropped element is skipped; `br` adds a space; a list ends the line
(below) and adds its lines at depth `d + 1`; a `pre` or `table` ends the line, emits the list
lines so far as a paragraph, then emits its code or table block (the list goes on afterwards,
in a new paragraph); any other block element or heading adds a space, its children read the
same way, and a space; any other element has its children read the same way. At the end of
the item, end the line.

In an item, `math` adds its maths text, any other inline element its script mark before its
children, and a `dl` that is a marker list ends the line and adds its marker lines at depth
`d + 1`.

**Marker lists**: a `dl` whose children are only whitespace text, `dd`, dropped elements and
at least one `dt`, every `dt`'s squashed line text matching
`^(?:\(?(?:[0-9]{1,3}|[A-Za-z]{1,2}|[ivxlcIVXLC]{1,6})[.)]|[-–•*·])$` (`1.`, `a)`, `(iv)`,
`-`), as statutes number their points. A glossary (`dt` terms) does not qualify. Its lines at
depth `d`: for each non-dropped `dd`, an item as above whose prefix is `"  " × d` + the
squashed text of the nearest `dt` before it (since the previous `dd`) + a space, or just the
indent when there is none.

**Ending the line**: squash the text and empty it; if not empty, add the line prefix + text,
and the prefix becomes `"  " × (d + 1)` (later lines of the item are indented, unmarked).

### Code

Text of the `pre`: text children as written, `\n` for a `br`, nothing for a dropped element,
the same text recursively for any other element. One leading `\n` is removed, then trailing
space, `\t`, `\n` and `\f`. Inline `code` outside `pre` stays in its paragraph.

### Tables

**Rows**: children of the table in order, skipping text, nested `table` elements and dropped
elements; a `caption` gives the caption (squashed line text) if none was found yet; a `tr` is a
row, a **head row** when inside a `thead`; any other element is searched the same way.
**Cells** of a row: its child `td`/`th` elements, skipping text, `table`, `tr` and dropped
elements, searching inside any other element. A cell's text is its squashed line text (so a
nested table becomes one line in the cell). `colspan` and `rowspan` are used when, trimmed of
space, they match `^[0-9]{1,9}$`, clamped to 1-100; otherwise 1.

**Title row**: when there is no caption, at least two rows, and the first row has exactly one
cell, non-empty, with colspan at least 2 and at least the sum of the colspans of every other
row, that cell's text is the caption and the row is removed (before the grid; its rowspan is
ignored).

**Grid**: with an empty carry map (column -> text, rows left), for each row: at column 0, for
each cell, first place the carried cells while the current column has one (decrementing its
rows left, removing it at 0, advancing the column), then place the cell's text `colspan` times,
recording a carry of `rowspan - 1` rows for each of those columns when `rowspan > 1`. After the
last cell, place carried cells again while the current column has one.

**Header**: the first grid row when it is a head row or all its cells are `th` (and it has
cells); it is then removed from the rows. A header whose texts are all empty becomes `[]`.
Rows whose texts are all empty are dropped. With no header and no rows, nothing is emitted.

The block is type `table`, text = `Table: <caption>` when the caption is not empty, then the
header (if any) and each row, cells joined by ` | `, one line each, joined by `\n`; meta
`{"caption": <string, "" when none>, "header": [...], "rows": [[...], ...]}`.

## Malformed markup, summarised

| Input | Result |
| --- | --- |
| `<p>a<div>b</div>` | `p` closed by the block start: paragraphs `a`, `b` |
| `<li>a<li>b`, `<td>a<td>b<tr>` | previous item, cell, row closed |
| `<h2>a<h3>b</h3>` | `h2` closed: two headings |
| `</span>` with no open `span`, a second `</p>` | ignored |
| `</br>` | a `br` |
| unclosed elements at the end | end there; text is kept |
| `<b` or `<a href="x` at the end | tag dropped with the rest |
| `1 < 2`, `<3` | text |
| `<script>` with no `</script>` | the rest of the input is script, dropped |
| `<div/>` | an empty `div` |

## Not handled

Rowspan and colspan follow the simple grid above, not the full HTML table model; text placed
directly in a table outside cells is dropped instead of moved before it; `<li value>`,
`<ol reversed>` and CSS (`display: none`) are ignored.
