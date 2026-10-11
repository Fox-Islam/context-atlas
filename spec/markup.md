# Markup

YAML, JSON, JSON Lines, XML and TOML files are read as a key tree with the line span of every
node, cut into path subtrees, and linked where a `$ref`, a YAML alias or an XML `id` reference
points elsewhere in the file. Ingestion sends no Jev request. `spec/fixtures.json` cases of kind
`markup-ingest` (and `prose-retrieve` cases whose documents carry `markup`) are tested against
this file.

`ingestMarkup(text, id, format, title?)`: `format` is `yaml`, `json`, `jsonl`, `xml` or `toml`
(any other is an error: `Cannot read "<format>" as markup; use yaml, json, jsonl, xml or toml.`);
`title` defaults to the id. Lines and strings may be of any length: where a rule below is a
regex, an implementation must accept the same text without failing on long input (the PHP
package writes a quoted string as `"[^"\\]*+(?:\\.[^"\\]*+)*+"` and scans JSON by hand).
The document's `profile` is `markup`, `detected` is `{profile: "markup", score: null, by:
"format"}`, and `data` is `{format}`.

## YAML outline

Lines are the text split on `\n` after turning `\r\n` and `\r` into `\n`. A line's indent is its
count of leading spaces; its content is the rest with trailing whitespace removed. Lines read in
order:

1. A blank line, or one whose content starts with `#`, is skipped.
2. While a block scalar is open, a line indented deeper than its owner is skipped; otherwise the
   block scalar ends.
3. While a flow collection is open, each line adds its bracket depth (below) and is skipped
   until the depth returns to 0.
4. `---` or `...` alone or followed by whitespace is skipped.
5. Otherwise the line opens a node, as below. A line that opens nothing belongs to the node
   above it.

Open nodes form a stack whose bottom is the root (path `[]`, indent -1).

- **Item**: content `-` or starting with `- `. Nodes are closed from the top while the top's
  indent is greater than the line's, or equal to it and the top is not a key (a key keeps the
  sequence written under it at its own indent). The item's path is its parent's plus
  `[<n>]`, n counting that parent's items from 0. The rest after the dash (leading spaces
  removed, at column indent + the dash and spaces) may start with an anchor; then, when it is a
  key line and does not start with `[` or `{`, it is read as a key at that column, otherwise as
  the item's value.
- **Anchor line**: content starting with `&` when a node is open; the anchor names the top node,
  and any rest is read as its value.
- **Key**: content matching
  `^(?:"((?:[^"\\]|\\.)*)"|'((?:[^']|'')*)'|([^\s#'"\[\]{},&*!|>%@`-][^#]*?|-[^\s#][^#]*?))\s*:(?:\s+(.*))?$`.
  The key is the double-quoted text unescaped, the single-quoted text with `''` read as `'`, or
  the plain text. Nodes are closed while the top's indent is at least the line's. The path is
  the parent's plus the key. The value is group 4 trimmed, after an optional anchor.
- **Anchor**: `&name` (name up to whitespace or `,[]{}`) at the start of a value; it maps the
  name to the node's path (a later anchor of the same name replaces it).
- **Value**: `*name` sets the node's `alias`. `|` or `>` with optional digits, `+`, `-` and a
  trailing comment opens a block scalar owned by the node's indent. A value starting with `[`
  or `{` opens a flow collection when its bracket depth is above 0. Any other value not
  starting with `#` is the node's scalar: a double-quoted string unescaped, a single-quoted one
  with `''` as `'`, or the plain text with a trailing ` #...` comment removed and trimmed.
- **Unescaping**: `\n` and `\t` are a newline and a tab; a backslash before any other character
  stands for that character.
- **Bracket depth**: opening minus closing brackets (`[`, `{`, `]`, `}`) outside single or
  double quotes, stopping at a `#` at the start or after a space.
- **Spans**: a node starts at its line, moved up over directly preceding comment lines. When
  closed by a line, it ends there, moved up over trailing blank and comment lines but never to
  its own first line; at the end of the text every open node ends at the line count, trimmed
  the same way. The root spans every line.

## JSON outline

The text is parsed as JSON (whitespace: space, tab, `\r`, `\n`; strings and numbers as in
RFC 8259) and printed in one layout, which gives the lines:

- A value starts a line: `<indent><lead><value>`, indent two spaces per depth, lead `"key": ` (the
  key as written) for an object member, nothing for an array element or the root.
- An empty object or array prints `{}` or `[]`; otherwise the opening bracket ends the line,
  members follow at depth + 1, each but the last followed by `,` on its last line, and the
  closing bracket gets its own line at the value's indent.
- Strings, numbers, `true`, `false` and `null` print as written in the source.

A node's path segment is the decoded key, or `[<i>]`; its span runs from its first printed line
to the line after its last. A string's value is its decoded text (empty when an escape leaves
an unpaired surrogate); other scalars' values are their raw text. Invalid JSON is an error that
names the byte offset.

## JSONL outline

The text is split on `\n`. A line holding only spaces, tabs and `\r` is blank and skipped. Every
other line is read and printed by the JSON outline, on its own, and its printed lines are
appended in order. The line's entries become item `[<i>]` of the root, i counting the non-blank
lines from 0: each entry's path gets `[<i>]` in front and its span is moved down by the number
of lines printed before it. The root spans every printed line (none for a file with no records).

The records are cut in order (Units): the root has no unit of its own. A record of more than 90
tokens is cut like any node; the runs of smaller records between them are packed into units of
at most 450 tokens, a record joining the current pack unless the pack already holds one and
the record's lines would take it past 450. A pack of one record is that record's unit (its
whole span, labelled as the record); a pack of several is a unit of the root labelled
`(top level) (<first> … <last>)`, the records named as in Packs (Units). A file with no records
is one empty unit of the root.

A line that is not valid JSON is an error: `Invalid JSONL at line <n>: Invalid JSON at byte <b>.`,
n the line's number in the text counting from 1 (blank lines counted), b the byte offset within
that line.

## XML outline

The text is read as bytes by the tokenizer below (no DOM or XML library) and printed in one
layout, which gives the lines. Offsets are byte offsets.

- **Prolog and epilog**: a UTF-8 byte order mark at the start is skipped. Before the root
  element, whitespace (space, tab, `\r`, `\n`), processing instructions (`<?` to the next
  `?>`), comments (`<!--` to the next `-->`) and doctypes are skipped; after it, the same
  except doctypes. A doctype (`<!DOCTYPE`, case-sensitive) ends at the first `>` outside
  single or double quotes and outside `[...]`, where comments are skipped whole. Anything else
  outside the root element is an error.
- **Name**: `[A-Za-z_:\x80-\xff][A-Za-z0-9_:.\x80-\xff-]*`, so a prefix such as `xlink:href`
  is part of the name.
- **Start tag**: `<` and a name, then repeatedly: optional whitespace, then `/>` (an element with
  no content), `>` (content follows), or an attribute, which needs whitespace before it. An
  attribute is a name, optional whitespace, `=`, optional whitespace, and a value in `"` or
  `'` running to the next same quote.
- **Content**: text up to the next `<` is added to the current run, entities decoded. Then
  `<!--` is skipped to `-->`, `<![CDATA[` adds the bytes up to `]]>` to the run as written,
  `<?` is skipped to `?>`, and any other `<!` is an error. Otherwise the run ends: collapsed, and
  kept as a text item of the element when not empty. `</` must be followed by the element's own
  name, optional whitespace and `>`; any other `<` starts a child element.
- **Entities**: `&lt;` `&gt;` `&amp;` `&quot;` `&apos;` and `&#<decimal>;` `&#x<hex>;` (UTF-8)
  are decoded in text and attribute values. A numeric reference to 0, above U+10FFFF or to a
  surrogate, any other named entity (`&nbsp;`), and a bare `&` stay as written.
- **Collapse**: every run of space, tab, `\r` and `\n` becomes one space, and leading and
  trailing spaces are removed. Attribute values are collapsed after decoding, as text runs are.
- **Errors**: `Invalid XML at byte <b>.`, b the offset of the `<` of an unclosed comment, CDATA
  section, processing instruction or doctype; of the `</` of an end tag with another name or
  without `>`; of the opening quote of an unclosed attribute value; the length of the text when
  it ends inside an element; otherwise the first byte that does not fit the rules above (text
  or a second element after the root, no root element).

Printing, indent two spaces per depth, the root element at depth 0; an element's open tag is
`<tag` plus ` name="value"` per attribute in source order (`"` in a value printed as `&quot;`):

- no content: `<indent><tag .../>`;
- only text: `<indent><tag ...>text</tag>` on one line;
- otherwise `<indent><tag ...>`, then its items in order at depth + 1 (a text item as
  `<indent>text`, a child element as above), then `<indent></tag>`.

Text and attribute values are printed decoded. The root (path `[]`) spans every line; the root
element's segment is its tag. A child element's segment is its tag, or `<tag>[<i>]` when the
parent has more than one child element with that tag, i counting them from 0. An element spans
its printed lines, and its value is its text items joined with a space (null when it has
none). Each attribute is an entry with path segment `@<name>`, its collapsed value, and an empty
span: start and end both the element's first line. Entries are listed element first, then its
attributes, then its children's entries.

An attribute `id` or `xml:id` with a non-empty value anchors its element under that value (a
later one replaces an earlier one). An attribute `ref` or `idref` with a non-empty value, or
`href` or `xlink:href` whose value is `#` followed by at least one byte, is an alias of the
anchor named by its value (without the `#`). The outline lists the label fields `@name`, `name`,
`@id`, `id`, `@title`, `title`, `@key`, `key` (Units). A root element named `html` is read like
any other.

## TOML outline

Lines are the text split on `\n` after turning `\r\n` and `\r` into `\n`; spans are these
original lines, and offsets are bytes. A line's content is the line without leading and trailing
spaces and tabs. Lines read in order:

1. A blank line, or one whose content starts with `#`, is skipped.
2. `[[<key>]]` opens an item of an array of tables, `[<key>]` a table, each allowing spaces and
   tabs inside the brackets and a trailing `#...` comment.
3. `<key> = <value>` (spaces and tabs around `=`) opens a key; the lines its value continues
   onto are skipped.
4. Any other line belongs to the node above it.

- **Key**: segments joined by `.` with optional spaces or tabs around it; a segment is bare
  (`[A-Za-z0-9_-]+`), basic (`"..."`, a backslash escaping the next byte; unescaped below) or
  literal (`'...'`, as written).
- **Header path**: the key's segments appended in turn; after each segment but the last, when
  the path so far is an array of tables, `[<n - 1>]` is appended, n its item count so far. For
  `[[<key>]]` the resolved path's item count then goes up by one and `[<n - 1>]` is appended.
  The header's path becomes the current table (the root's `[]` before any header).
- **Key path**: the current table's path plus the key's segments.
- **Opening a path**: open nodes form a stack whose bottom is the root. Nodes are closed from
  the top while the top's path is not a proper prefix of the new path; then the new path's node
  opens, starting at the line moved up over directly preceding comment lines. Only headers and
  keys are nodes: `[tool.ruff]` is one node at `tool > ruff` with no node for `tool`, a dotted
  key `a.b = 1` one node at `a > b`, and `[[a]]` a node at `a > [<i>]` with none for `a`
  (Units gives each its nearest ancestor that has a node as parent).
- **Spans**: as in the YAML outline, a node closed by a line ends there, moved up over trailing
  blank and comment lines but never to its own first line; at the end of the text every open
  node ends at the line count, trimmed the same way. The root spans every line.

A value starts after the `=` and the spaces and tabs after it. Tables have no value.

- **Extent**: a value starting with `"`, `'`, `[` or `{` is scanned from its first byte. Outside
  a string, `#` ends the scan of the line, `"""` and `'''` open a multi-line string, `"` and
  `'` a single-line one, `[` and `{` add 1 to the depth and `]` and `}` take 1 off. Inside `"`
  or `"""` a backslash skips the next byte. A single-line string closes at its quote byte, or at
  the end of its line; a multi-line string closes at a run of three or more of its quote byte,
  consuming the run up to five. The value ends after the first byte (or line end) at which no
  string is open and the depth is 0 or less; if the text ends first, it runs to the end.
- **Arrays and inline tables** (`[`, `{`): no value and no children.
- **Multi-line strings**: the value's text from its first byte to its end, lines joined by
  `\n`, is null unless it is at least six bytes and ends with the opening delimiter. Otherwise
  the value is the text between the first three and the last three bytes, less one leading
  `\n`; a `'''` string as written, a `"""` string unescaped.
- **Single-line strings**: the value's text matching `^"((?:[^"\\]|\\.)*)"$` is group 1
  unescaped, matching `^'([^']*)'$` is group 1; otherwise null.
- **Other values**: the rest of the line up to its first `#`, without leading and trailing
  spaces and tabs (null when empty), so numbers, booleans and dates are their raw text.
- **Unescaping**, left to right: a backslash followed by optional spaces or tabs, a newline and
  any spaces, tabs and newlines is removed with them; `\uXXXX` and `\UXXXXXXXX` (hex) are that
  code point in UTF-8 unless it is above U+10FFFF or a surrogate; `\b` `\t` `\n` `\f` `\r` `\"`
  `\\` are backspace, tab, newline, form feed, carriage return, `"` and `\`; a backslash before
  any other byte stays as written with it.

A table or key opened twice, such as `[a]` given again after `[b]`, is a repeated path (Units).
A sub-table written after other tables, such as `[package.metadata]` at the end of the file, is
not: it is a child of `[package]` lying outside its span (Units).

## Units

A node's parent is the node of its nearest ancestor path that has one, the root otherwise; its
children are listed in the order of the nodes. When a path repeats (a key given twice at one
level), the last node with it stands for the path and is listed once among its parent's
children, in the place of the first; the earlier node's lines stay with the parent.

A child of more than 90 tokens whose span is not inside its parent's span is **detached**. In
YAML, JSON, JSONL and XML a child always lies inside its parent; in TOML a sub-table written
after other tables does not, and a repeated path can leave an earlier node's child outside the
last node.

Starting from the root, a node is cut:

- A node other than the root whose span is at most 450 tokens, or that has no children, is one
  unit of its whole span less the spans of the detached nodes inside it.
- Otherwise its children of more than 90 tokens are big. Its own lines are its span less the
  big children's spans and the spans of the detached nodes inside it but not inside a big
  child; they form a unit when more than one of them is neither blank nor a comment, or when it
  has no big child.
- Either way, each big child is then cut in order, and then each of those detached nodes in the
  order of the nodes, skipping any node already cut. A detached node is thus cut once: as a big
  child of its parent when the parent is cut in pieces first, otherwise by the node whose span
  holds it, usually the root. A small child outside its parent's span stays among the lines of
  the node whose span holds it.

**Packs.** When a node's own lines (the second rule) come to more than 450 tokens, they form
several units instead of one. Its small children are the children of 90 tokens or fewer whose
span is inside its own; a child's lines are those of its own lines inside its span. The own
lines are read in order into packs, starting with one empty pack: a line outside every small
child joins the current pack; the first line of a small child starts a new pack when the
current pack already holds a child and its lines plus all of that child's lines would come to
more than 450 tokens, and the child's lines join the pack either way. Each pack is a unit, in
order, whose label adds a last segment naming the first and last child it holds:
`(<first> … <last>)` (U+2026 with a space on each side), or `(<first>)` for one child, each
child named by its label segments after the node's, joined with ` > `. The text's first line
puts it after the node's label with a space: `hugo.toml: (top level) (baseURL … mediaTypes)`.

Token counts are of the span's lines joined by `\n` (`spec/behaviour.md`). Units are `s1`,
`s2`... in the order cut, of kind `subtree`:

- **text**: `<title>: <label>` (`(top level)` for the root), a newline, then the unit's lines
  with their smallest indent (over non-blank lines) removed, joined by `\n` and right-trimmed;
- **path**: the label segments joined with ` > `, where an item segment `[<n>]` (in an XML
  outline `<tag>[<n>]`) becomes `[<field>=<v>]` (`<tag>[<field>=<v>]`) from the first of:
  1. the label fields in order, `name`, `id`, `title`, `key` (XML: `@name`, `name`, `@id`,
     `id`, `@title`, `title`, `@key`, `key`), the first with a scalar value, as it is;
  2. the item's children with a value whose last path segment, less a leading `@`, ends in
     `name`, `id`, `title` or `key` in any ASCII case (`/(?:name|id|title|key)$/i`); the value
     as it is;
  3. the item's children whose value is not empty and holds no `\n`; the value as it is when it
     has at most 60 Unicode code points, else its first 40 code points followed by `…` (U+2026).

  In steps 2 and 3 the item's children are taken in order (as listed under Units), and the
  first whose value is unique among the item's same-name siblings is chosen, else the first.
  Same-name siblings are the items whose path is the item's parent path plus a segment that
  differs from the item's only in its index (`[<j>]`, or `<tag>[<j>]` for the same tag), the
  item included; a value is unique when no other of them has a node at the same path below it
  (the child's segments after the item's) with the same value. Steps 2 and 3 pass over
  children whose last segment is itself an item (`[<n>]`), and any value counts, a number's raw
  text included. The field is the child's last segment without a
  leading `@` (`userId`, `android:name`, `question`);
- **meta**: `{path, lines, profile}`, `path` the raw segments and `lines` `[first, last + 1]`.

A line belongs to the first unit that holds it, and a node to the unit holding its first line.

## Links

`ref`, from the unit a node belongs to, to the unit the target node belongs to, for:

- a node whose last path segment is `$ref` and whose value starts with `#/`: the target path is
  the rest split on `/`, each segment with `~1` read as `/` and `~0` as `~`;
- a node with an `alias` (a YAML alias, or an XML reference attribute): the target is the
  anchor's path.

A link is dropped when either end belongs to no unit or both ends are the same unit, and listed
once per pair, in the order of the nodes.

## Retrieval

The prose retriever (`spec/prose.md`) takes markup candidates by their `profile`: pool 9, no
scope, the first 3 kept units. Then `ref` links are followed from the gathered units, in both
directions, for two hops at most. Each hop takes, in order, every unit linked to a unit gathered
in the previous hop (the first hop: all gathered) that is not gathered yet, at most 25, and asks
one request: state `{question, "the parts found so far": head 1600 of every gathered unit,
"linked parts": {"linked-<i>": head 1200}}`, a noul `c_linked-<i>` per unit worded by direction
(the unit the gathered one points to, or a unit pointing to it). Those scoring 0.4 or more are
gathered and form the next hop.
