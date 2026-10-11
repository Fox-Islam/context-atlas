# LaTeX

How LaTeX source becomes blocks (`spec/behaviour.md`, Blocks). Nothing is expanded: macros
are not defined or run, and the rules below are fixed. The PHP implementation is
`php/src/Parsing/LatexParser.php` with `LatexInline.php` (inline text) and `LatexTable.php`
(tabulars); the cases are the `read` fixtures named `latex: ...`.

## Input and conventions

- The input is UTF-8; output for invalid UTF-8 is not part of the contract. `\r\n` and `\r`
  become `\n`, U+0000-U+0003 are removed, and a leading U+FEFF is removed.
- Every character the reader looks for is ASCII, so scanning by bytes (PHP), UTF-16 code units
  (JavaScript) or code points (Python) gives the same result. Regexes are ASCII-only and run
  without a Unicode flag; `\G`/"anchored at `p`" means a match that must start at offset `p`.
- **Trim** and **squash** are the `spec/behaviour.md` rules (Unicode whitespace). **Blank**
  means empty after trim.
- **Space before an argument**: `[ \t]*`, then optionally one `\n` followed by `[ \t]*`.
- **Brace group** at `p` (`{`): scan from `p`; `\` skips the next character; `{` adds one to a
  depth, `}` subtracts one, and the `}` that brings it to 0 closes the group. The content is
  between the braces. An unclosed group runs to the end of the text.
- **Optional group** at `p` (`[`): scan from `p + 1`; `\` skips the next character; `{` and `}`
  count a brace depth; the first `]` at depth ≤ 0 closes it. Unclosed: to the end.
- **Argument** of a command, from `p`: skip space before an argument; while the next character
  is `[`, skip an optional group and space again; if the next character is `{`, the argument
  is that brace group's content and reading continues after it. Otherwise there is no argument
  and reading continues at `p` (nothing is consumed).
- **Direct groups** from `p`: while the character at `p` is `[` or `{` (nothing between
  groups), skip an optional group or take a brace group. The result is the list of brace group
  contents.

## 1. Comments and verbatim

Lines are read in order, each possibly in several steps (a "rest" of the line), with an open
verbatim environment carried between lines:

1. **Inside verbatim** named `N`: if the rest contains `\end{N}`, the text before it is added
   to the code lines when not blank, a placeholder line (below) replaces the environment, and
   the text after `\end{N}` is read on as a fresh step (not a fresh line). Otherwise the whole
   rest is a code line.
2. **Comment**: the rest is cut at its first `%` that is not escaped. Scanning left to right,
   `\` skips the next character, and `\verb` or `\Verb`, an optional `*` and a delimiter
   matching `[^A-Za-z \t\n*]` skip to the next occurrence of that delimiter (or the end).
3. **Verbatim start**: if the cut rest matches `\\begin\{(verbatim|Verbatim|lstlisting|minted)(\*?)\}`
   (first match), the text before it is output as a line when not blank, verbatim `N` (name
   plus star) opens, and reading goes on in the original (uncut) rest after the match: skip
   `[ \t]*`; if `[` follows, skip an optional group and `[ \t]*`; for `minted`, if `{` follows,
   skip a brace group. When what remains is blank the line ends; otherwise it is read on (step
   1), so it becomes the first code line.
4. Otherwise the cut rest is output as a line, unless it is blank and either a comment was cut
   from it or it is not the line's first step. So a line that is only a comment disappears
   (the lines around it stay one paragraph), while a blank line with no comment stays.

At the end of the input, an open verbatim is closed with the code lines so far. Each verbatim
gets the next number `k` from 0; its placeholder is the line `\x00k\x00` and its code is the
code lines joined with `\n`, kept exactly. The cleaned text is the output lines joined with `\n`.

## 2. Body and title

The body is the cleaned text after the first `\begin{document}` up to the first `\end{document}`
after it (to the end if there is none); without `\begin{document}` it is the whole cleaned
text.

The **title** is read from the whole cleaned text: at the first match of
`(?<!\\)\\title(?![A-Za-z])`, the argument after it, converted (inline text, below) and
squashed; empty when there is no match or no argument.

## 3. Walking the body

The walk keeps: an **open paragraph** (raw source text), a stack of **frames** (open
environments: name, kind `list`, `table`, `figure` or `other`, an item counter, a caption and
the tables found in it), the **list lines** collected for the current list block, and the
**current item** (raw text or none, marker, label, whether it is a description item, indent).
A **list depth** is the number of `list` frames.

The body is scanned with this regex, from offset 0, each search starting where the last token
ended:

```
(?<!\\)\\(?:[vV]erb\*?([^A-Za-z \t\n*]).*?\g{-1}|(begin|end)\{([^{}]*)\}|(part|chapter|section|subsection|subsubsection|paragraph|subparagraph)(?![A-Za-z])|(item|bibitem|caption|title|maketitle|par)(?![A-Za-z])|(\[))|(\n[ \t]*\n)|\x00(\d+)\x00
```

(`.` does not match `\n`; `\g{-1}` is a back-reference to the delimiter, group 1.) The text
before each token, and after the last, is **appended**: to the open paragraph when the list
depth is 0, else to the current item when there is one, else dropped (text in a list before its
first `\item`). Then, by group:

- **1 `\verb`**: the whole match is appended as text.
- **2/3 `\begin{N}` / `\end{N}`**: see Environments.
- **4 sectioning**: skip one `*` directly after the name; read the argument. Close lists
  (below), flush the paragraph, and when the converted, squashed argument is not empty record a
  heading with rank `part` 0, `chapter` 1, `section` 2, `subsection` 3, `subsubsection` 4,
  `paragraph` 5, `subparagraph` 6.
- **5 `\par`**: a paragraph break (below). **`\maketitle`**: remember that it was seen.
  **`\title`**: read and discard its argument. **`\caption`**: skip one `*`, read the argument,
  convert and squash it, then: in the nearest enclosing `table` or `figure` frame (searching
  from the top), a `table` frame takes it as its caption (the last one wins); a `figure`
  interrupts (below) and emits the paragraph `Figure: <caption>` when not empty. Outside both,
  interrupt and emit the caption as a paragraph when not empty.
  **`\item` / `\bibitem`**: from the token end, skip space before an argument; if `[` follows,
  the label is that optional group's content, converted and squashed, and reading continues
  after it. For `\bibitem`, then read and discard an argument. If there is no `list` frame,
  nothing else happens. Otherwise finish the current item, and start one: in the topmost list
  frame, an `enumerate` or `enumerate*` adds 1 to its counter when there is no label; the
  marker is `<counter>.` for those and `-` otherwise; the item is a description item in
  `description` / `description*`; indent is two spaces per list depth beyond the first.
- **6 `\[`**: display maths up to the next `\]` (or the end) becomes a maths block (below); the
  walk resumes after `\]`.
- **7 blank line**: a paragraph break.
- **8 placeholder**: a code block of that verbatim's code: interrupt, then emit it when not
  blank.

At the end of the body: close lists, then flush the paragraph.

- **Flush**: convert the open paragraph as a paragraph (section 4, Leftover arguments), squash
  it, emit it as a paragraph when not empty, and empty it.
- **Paragraph break**: flush when the list depth is 0; inside a list, append a space.
- **Interrupt** (before a block that cuts through text): flush; then, inside a list, finish the
  current item and emit the list lines. If an item was open, it goes on as an unmarked
  continuation: empty raw text, empty marker, no label, not a description item, same indent.
- **Close lists**: when the list depth is above 0, finish the current item, emit the list lines,
  and remove every `list` frame from the stack.
- **Emit list lines**: when there are any, emit them joined with `\n` as one paragraph; clear
  them.

### Environments

`\begin{N}`, by name:

| Name | Effect |
| --- | --- |
| `equation`, `align`, `gather`, `multline`, `flalign`, `alignat`, `eqnarray` (each also starred), `displaymath`, `math` | For `alignat` / `alignat*`, a `{` directly after `\begin{N}` starts a brace group that is skipped. The content up to the matching `\end{N}` becomes a maths block. |
| `comment`, `tikzpicture`, `pgfpicture`, `picture`, `filecontents`, `filecontents*` | The content up to the matching `\end{N}` is dropped. |
| `tabular` (1 required argument), `tabular*`, `tabularx`, `tabulary` (2), `longtable` (1) | Arguments are skipped: repeatedly skip `[ \t]*`, then an optional group (not counted) or a brace group (counted), until the required count is reached or neither follows. The content up to the matching `\end{N}` is read as a table (below). |
| `itemize`, `enumerate`, `description` (each also starred), `thebibliography` | Opens a list: at list depth 0 flush the paragraph, else finish the current item. For `thebibliography`, read and discard an argument. Push a `list` frame. |
| `table`, `table*` | At list depth 0 flush; skip direct groups; push a `table` frame. |
| `figure`, `figure*` | At list depth 0 flush; skip direct groups; push a `figure` frame. |
| any other | At list depth 0 flush; skip direct groups; push an `other` frame. |

**Matching end**: from the content start, find the next `\begin{N}` and the next `\end{N}`
(exact strings); a begin before the end adds one to a depth starting at 1, an end subtracts one,
and the end that reaches 0 closes the content. Without one, the content runs to the end of the
body. The walk resumes after the closing `\end{N}`.

**Maths block**: remove every `\\label\{[^{}]*\}`, split into lines, trim each, drop empty
lines; when any remain, interrupt and emit a code block of them joined with `\n`.

`\end{N}`: find the topmost (innermost) frame named `N`; if none, ignore it. Otherwise pop frames
down to and including it, and for each popped frame: a `list` finishes the current item and,
when no list frame remains, emits the list lines; a `table` flushes and emits its tables (below);
others flush when the list depth is 0.

### Lists

**Finishing an item** (when there is one): convert its raw text and squash it; the item is then
none. Its line is:

- description item: the label (if not empty) and the text (if not empty) joined with `: `,
  after `- `; nothing when both are empty;
- no label: marker, a space and the text, trimmed; nothing when the text is empty;
- with a label (non-description): the label and the text, each only if not empty, joined with
  a space.

A line that is not empty is added to the list lines with the item's indent in front.

### Tables

The content is split into rows, scanning with a brace depth: `{`/`}` add/subtract one; at
depth ≤ 0 a row ends at `\\` (then skip one `*`, and, if after `[ \t]*` there is a `[`, the
optional group; otherwise only the `*`), at `\tabularnewline`, and in a `longtable` at
`\endfirsthead`, `\endhead`, `\endfoot` and `\endlastfoot` (a "mark" that ends the row). A
command name is `[A-Za-z]+`; any other `\x` is copied as two characters. What follows the last
break is a last row.

In a longtable, rows are grouped into segments ending at each mark. A segment ended by
`\endfirsthead` is kept; one ended by `\endhead` is kept only when the table has no
`\endfirsthead`; segments ended by `\endfoot` or `\endlastfoot` are dropped; rows after the last
mark are kept.

Each kept row: remove matches of

```
\\(?:(?:toprule|midrule|bottomrule)(?:\[[^\]]*\])?|hline|hdashline|cline[ \t\n]*\{[^}]*\}|hhline[ \t\n]*\{[^}]*\}|cmidrule[ \t\n]*(?:\[[^\]]*\])?[ \t\n]*(?:\([^)]*\))?[ \t\n]*\{[^}]*\}|addlinespace(?:[ \t\n]*\[[^\]]*\])?|specialrule[ \t\n]*\{[^}]*\}[ \t\n]*\{[^}]*\}[ \t\n]*\{[^}]*\}|rowcolor[ \t\n]*(?:\[[^\]]*\])?[ \t\n]*\{[^}]*\})(?![A-Za-z])
```

In a longtable, a row matching `(?<!\\)\\caption(?![A-Za-z])\*?` sets the caption to the
converted, squashed argument after the match, and is dropped. Otherwise the row is split into
cells at `&` outside braces (`\` copies the next character with it), each converted and
squashed; a row whose cells are all empty is dropped. Rows keep their own cell count.

The rows go to the nearest enclosing `table` frame (searching from the top), whose caption a
longtable's caption replaces; without one, interrupt and emit the table with the longtable's
caption or none. A `table` frame, when it ends, emits each of its tables with its caption, or,
with no tables, the paragraph `Table: <caption>` when the caption is not empty.

**Emitting a table** (nothing when it has no rows): a table block whose text is the line
`Table: <caption>` when the caption is not empty, then each row's cells joined with ` | `, one
per line; meta `{caption, header, rows}` in that order: the caption (`""` when none), the first
row, and the rest.

## 4. Inline text

Converting scans the text from the start and builds the output:

- **`\`**: a command (below).
- **`{`**: the brace group's content, converted.
- **`}`**: dropped.
- **`$`**: inline maths kept as written. With `$$`, up to and including the next `$$`;
  otherwise up to and including the next `$`; `\` skips a character while searching. Without a
  closing one, `$` is output and scanning moves on by one.
- **`~`**: a space. **`---`**: `—`. **`--`**: `–`. **` `` `** and **`''`**: `"`. **`` ` ``**: `'`.
- anything else is copied.

**Commands**, after `\`:

- `\` at the end of the text: nothing.
- **Control symbols** (a character that is not a letter):
  - `\\`: a space; skip one `*`, then an optional group directly after.
  - `\(`: copied as written up to and including the next `\)` (or the end).
  - accents `` \' \` \^ \" \~ \= \. ``: the base is the converted brace group when `{`
    follows, a command's output when `\` follows (so `\'\i` is `í`), or one ASCII letter;
    otherwise nothing is output. The combining mark (U+0301, U+0300, U+0302, U+0308, U+0303,
    U+0304, U+0307 in that order) goes after the base's first code point and that pair is
    NFC-normalised; the rest of the base follows.
  - `\ `, `\` + newline, `\` + tab, `\,`, `\;`, `\:`, `\>`: a space. `\!`, `\-`, `\/`, `\@`:
    nothing. Any other character (`\%`, `\&`, `\$`, `\#`, `\_`, `\{`, `\}`, ...): the character.
- **Control words** `[A-Za-z]+`, then one `*` is skipped if present. Following whitespace is
  never consumed. In this order:
  1. `verb`, `Verb`: the delimiter is the next character matching `[^A-Za-z \t\n*]` (after the
     optional `*`); the text up to the next delimiter is copied; no delimiter: nothing.
  2. Letter accents `c v u H k r` followed by `{`: the converted group gets U+0327, U+030C,
     U+0306, U+030B, U+0328, U+030A as above. (Not followed by `{`: as an unknown command.)
  3. Symbols: `LaTeX` LaTeX, `TeX` TeX, `LaTeXe` LaTeX2e, `ldots` `dots` `textellipsis` `…`,
     `textendash` `–`, `textemdash` `—`, `S` `§`, `P` `¶`, `copyright` `textcopyright` `©`,
     `textregistered` `®`, `texttrademark` `™`, `pounds` `textsterling` `£`, `euro`
     `texteuro` `€`, `textbackslash` `\`, `textasciitilde` `~`, `textasciicircum` `^`,
     `textless` `<`, `textgreater` `>`, `textbar` `|`, `textunderscore` `_`, `slash` `/`,
     `quad` `qquad` `newline` `linebreak` a space, `textquoteleft` `textquoteright` `'`,
     `textquotedblleft` `textquotedblright` `"`, `textbullet` `•`, `textdegree` `°`, `dag`
     `†`, `ddag` `‡`, `i` `ı`, `j` `ȷ`, `ss` `ß`, `o` `ø`, `O` `Ø`, `ae` `æ`, `AE` `Æ`, `oe`
     `œ`, `OE` `Œ`, `aa` `å`, `AA` `Å`, `l` `ł`, `L` `Ł`.
  4. Quantities (siunitx and `units`), with argument counts `num` 1, `numrange` 2, `qty` 2,
     `SI` 2, `qtyrange` 3, `SIrange` 3, `unit` 1, `si` 1. For `unit` only: after space before
     an argument, an optional group without `=` is a value (one with `=` is options, as for
     the others) and reading continues after it. Then the arguments are read in turn (missing
     ones are empty). The numbers are the value (if any) and, except for `unit` and `si`, every
     argument but the last (`num`, `numrange`: every argument), each converted and trimmed,
     joined with `–`. The unit is the last argument read as a unit (below), except for `num` and
     `numrange` (none). The output is the numbers, a space and the unit, trimmed:
     `\qty{9.81}{\metre\per\second\squared}` gives `9.81 m/s²`, `\unit[11]{inches}` gives
     `11 inches`.
     **Unit**: the argument is split into macros `\\([A-Za-z]+)` and runs of other text, read in
     order with a pending prefix, power and joiner (all empty at first). Text: the joiner, then
     the text converted and trimmed; the joiner empties. A prefix macro (`yocto` y, `zepto` z,
     `atto` a, `femto` f, `pico` p, `nano` n, `micro` µ, `milli` m, `centi` c, `deci` d,
     `deca`/`deka` da, `hecto` h, `kilo` k, `mega` M, `giga` G, `tera` T, `peta` P, `exa` E,
     `zetta` Z, `yotta` Y) adds its symbol to the prefix. `per` sets the joiner to `/`.
     `square`/`cubic` set the power to `²`/`³`. `squared`/`cubed` append `²`/`³` to the output.
     Any other macro is a unit: its symbol from the table below, else its name; output the
     joiner, or a space when the joiner is empty and the output is not, then prefix, symbol and
     power; prefix, power and joiner empty. The result is trimmed. Units: `metre` `meter` m,
     `gram` g, `kilogram` kg, `second` s, `ampere` A, `kelvin` K, `mole` mol, `candela` cd,
     `hertz` Hz, `newton` N, `pascal` Pa, `joule` J, `watt` W, `volt` V, `ohm` Ω, `coulomb` C,
     `farad` F, `henry` H, `tesla` T, `weber` Wb, `siemens` S, `becquerel` Bq, `gray` Gy,
     `sievert` Sv, `lumen` lm, `lux` lx, `radian` rad, `steradian` sr, `litre` `liter` L,
     `tonne` t, `hour` h, `minute` min, `day` d, `hectare` ha, `electronvolt` eV,
     `degreeCelsius` °C, `degree` °, `arcminute` ′, `arcsecond` ″, `percent` %, `bel` B,
     `decibel` dB, `neper` Np, `bit` bit, `byte` B, `angstrom` Å, `astronomicalunit` au,
     `dalton` Da, `bar` bar.
  5. Fractions `frac tfrac dfrac nicefrac sfrac xfrac`: two arguments, each converted and
     trimmed, joined with `/`; preceded by a space when the output so far (of the text being
     converted) ends with a digit, so `8\nicefrac{1}{2}` gives `8 1/2`.
  6. Unwrapped: `textbf textit emph texttt underline textsc textrm textsf textsl textup textmd
     textnormal mbox text uline`: the converted argument (nothing without one).
  7. `url`: the argument, trimmed, not converted. `href`: two arguments, URL then text:
     `<converted text> (<trimmed URL>)`.
  8. `footnote`: ` (<converted argument>)` (nothing without an argument).
  9. Citations `cite citep citet citealp citealt citeauthor citeyear parencite textcite
     autocite footcite Cite Citep Citet`: the argument split at `,`, each key trimmed, empty
     keys dropped, joined with `, ` inside `[` `]`.
  10. References `ref eqref autoref cref Cref pageref nameref vref`: the argument, trimmed.
  11. Any other: take the direct groups. Dropped commands output nothing: `begin end label
     nocite documentclass usepackage RequirePackage newcommand renewcommand providecommand
     newenvironment renewenvironment newtheorem theoremstyle setlength addtolength setcounter
     addtocounter includegraphics input include bibliography bibliographystyle vspace hspace
     pagestyle thispagestyle pagenumbering author date thanks affil affiliation address email
     institute index graphicspath hypersetup lstset definecolor color geometry newgeometry
     captionsetup numberwithin lstinputlisting inputminted fontfamily fontseries fontshape
     fontsize usetikzlibrary setmainfont setsansfont setmonofont setfloatalignment`. So does a
     command whose last brace group, as written, is a dimension: it matches
     `^[ \t\n]*[-+]?(?:\d+(?:\.\d+)?|\.\d+)[ \t\n]*(?:pt|em|ex|in|cm|mm|bp|pc|sp|mu|px|\\[A-Za-z]+)[ \t\n]*$`
     (`2.5cm`, `0.5\linewidth`). Any other command outputs its last brace group converted
     (`\textcolor{red}{word}` gives `word`, `\multicolumn{2}{c}{Total}` gives `Total`) as a
     **leftover argument**, or nothing when it has none (`\centering`, `\noindent`, `\small`).

**Leftover arguments.** Converting a paragraph (the open paragraph at a flush; not headings,
items, cells or captions) notes which output came from leftover arguments (a leftover inside
another command's argument counts as that command's text). The paragraph converts to `""` when
the text outside leftover arguments squashes to empty, at least one leftover argument is not
blank, and every non-blank one, trimmed, matches `^[A-Za-z0-9._-]{1,12}$`. So a line of layout
macros such as `\pgfsetlayers{main}\mylayout{t}` leaves no paragraph, while
`\gls{API} keys are secret` and `\doccmd{sidenote[offset]{text}}` stay. In PHP the leftover
arguments are wrapped in U+0001 and U+0002 while converting, and U+0003 marks a fraction's
output, which is why the input loses those characters.

## 5. Output

Headings take levels relative to the highest one used: with `top` the smallest rank among the
recorded headings, a heading's level is `rank − top + 1`. The title is emitted, first, as a
level-1 heading when it is not empty and either `\maketitle` was seen in the body or there are
no other headings; then every other heading's level is one more. The blocks are the title (if
emitted), then the headings, paragraphs, code blocks and tables in the order they were
recorded.

Known limits: tables inside a `table` float are emitted at its end, after any text that follows
them inside the float; a heading inside a list closes the list; `\section` written inside a
brace group (such as a macro argument) starts a heading too; custom macros and environments
are not expanded, so their arguments follow the unknown-command rule.
