# MDX

How MDX becomes blocks (`spec/behaviour.md`, Blocks): the lines that are not prose are blanked,
and the result is read by the Markdown rules (`spec/behaviour.md`, Markdown). JSX and
JavaScript are not parsed; the rules below are line patterns. The PHP implementation is
`php/src/Parsing/MdxParser.php`; the cases are the `read` fixtures named `mdx: ...`.

## Input

`\r\n` and `\r` become `\n`; lines are the text split on `\n`. **Trim** and **blank** are the
`spec/behaviour.md` rules. Regexes are ASCII-only and run without a Unicode flag; lengths play
no part.

When the first line is exactly `---` and a later line is exactly `---` or `...`, the lines up to
and including that one (front matter) are copied unchanged; the Markdown rules then drop them.

## Lines

The other lines are read in order with this state: an open **fence** (its marker), an open
**unwrapped fence** (its marker), an **ESM depth**, an open **tag**, and an open **comment**.
Each line produces one output line (two extra blank lines for a wrapped element), in this order
of checks:

1. **Inside a fence**: the line is copied; it closes the fence when it matches
   `^ {0,3}` + the marker's character repeated at least as many times as the marker + `[ \t]*$`.
2. **Unwrapped fence closer**: with an unwrapped fence open, a line closing it (same test) is
   blanked and closes it.
3. **Inside an ESM statement** (depth > 0): blanked. A blank line sets the depth to 0;
   otherwise the line's bracket balance is added.
4. **Inside a tag**: blanked; the tag stays open unless the trimmed line ends with `>`.
5. **Fence opener** (no comment open): `^ {0,3}(`{3,}|~{3,})[ \t]*([^ \t]*)`. When group 2 is
   exactly `mdx-code-block` and no unwrapped fence is open, the line is blanked and opens an
   unwrapped fence with marker group 1: its content is read as MDX by these same rules.
   Otherwise the line is copied and opens a fence with marker group 1; its content is copied
   as written, imports, comments and tags included.
6. **ESM** (no comment open): a line matching `^(?:import|export)(?=[ \t{*])` (at column 0) is
   blanked, and the depth becomes its bracket balance when that is positive, so a statement
   whose `{`, `[` or `(` stay open runs on until they close or a blank line.
7. Otherwise **comments** are removed (below), then, with `t` the trimmed result:
   - **Admonition marker** (Docusaurus): `t` matches
     `^:{3,}([A-Za-z][A-Za-z0-9_-]*)?(?:\[([^\]]*)\]|[ \t]+(.*))?$` (`:::tip`, `:::tip Title`,
     `:::note[Title]`, a closing `:::`). The title is group 2, else group 3, trimmed: when it is
     not empty, output a blank line, the title and a blank line (the title becomes a paragraph
     of its own); otherwise a blank line. The content between markers is read as usual.
   - **Wrapped element**: `t` matches
     `^<([A-Z][A-Za-z0-9_.]*)(?:[ \t][^<>]*)?>(.*)<\/\1[ \t]*>$`: output a blank line, group 2
     without HTML (below), and a blank line, so `<TabItem value="a">First tab.</TabItem>` is a
     paragraph of its own.
   - **Tag line**: `t` matches `^(?:<\/?[A-Z][A-Za-z0-9_.]*(?:[ \t][^<>]*)?\/?>|<\/?>)$`
     (an opening, closing or self-closing tag of a capitalised component, or a fragment
     `<>`/`</>`), or `t` is empty: a blank line.
   - **Tag start**: `t` matches `^<[A-Z][A-Za-z0-9_.]*(?:[ \t][^<>]*)?$` (an opening tag whose
     `>` is on a later line): a blank line, and a tag opens (check 4).
   - Otherwise the line with its comments removed and without HTML; a blank line when that
     leaves it blank.

**Without HTML.** The line is split into backtick code spans (a backtick, then the shortest run
up to the next backtick, both included) and the text between them; in the text between, every
match of `<\/?[a-z][a-z0-9-]*(?:[ \t][^<>]*)?\/?>|<!--.*?-->` is removed. So
`<summary>Toggle me!</summary>` gives `Toggle me!`, a line holding only `<details>` becomes
blank, and `` `<summary>` `` written as inline code is kept.

The **bracket balance** of a line is its count of `{`, `[` and `(` minus its count of `}`, `]`
and `)`, strings and comments included.

**Comments.** With a comment open, the line is searched for `\*\/[ \t]*\}`: without a match the
whole line is removed; with one, the line is cut to the text after it and the comment closes.
Then, repeatedly, the first match of `\{[ \t]*\/\*` is found: if `\*\/[ \t]*\}` matches after
it, the text from the opener to the end of the closer is removed; otherwise the line is cut
before the opener and a comment opens. The search ignores backticks, so `{/* x */}` written in
inline code outside a fence is removed too.

The output lines, joined with `\n`, are read by the Markdown rules. JSX expressions such as
`{props.title}`, components inside a line of text (`Press <Kbd>Ctrl</Kbd>`) and HTML tags
that span lines are left in the text.
