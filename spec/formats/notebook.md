# Jupyter notebooks

How a Jupyter notebook (`.ipynb`, nbformat 4) becomes blocks (`spec/behaviour.md`, Blocks).
The PHP implementation is `php/src/Parsing/NotebookParser.php`; the cases are the `read`
fixtures named `ipynb: ...`.

## Input

The text is parsed as JSON, arrays and objects nested fewer than 512 deep (deeper input is
invalid). It must be a JSON object whose `cells` member is a JSON array;
anything else, including invalid JSON, is the error
`A notebook must be a JSON object with a "cells" list.` (a fixed string, never the JSON
parser's message). `nbformat`, `metadata` and every other member are ignored, so an nbformat
3 file (cells under `worksheets`) is an error.

A **text field** (`source`, `text`, `ename`, `evalue`, `data["text/plain"]`) is a string, or an
array whose string items are joined with nothing between them (other items are skipped); any
other value or a missing member is `""`. Before use, `\r\n` and `\r` in sources and outputs
become `\n`.

**ANSI codes** are matches of `\x1B\[[0-9;?]*[A-Za-z]`, removed from outputs. **Trim** is the
`spec/behaviour.md` rule; "trailing whitespace" is a final run of that whitespace set.

## Cells

Cells are read in order; an item that is not an object, or whose `cell_type` is not a string,
is skipped. By `cell_type`:

- **`markdown`**: its source read by the Markdown rules (`spec/behaviour.md`), whose blocks are
  added as they come (a source starting with a `---` line loses its front matter there too).
  Attachments are ignored.
- **`code`**: a code block of the source, when the source is not blank (the text is the source
  as is, not trimmed); then its outputs.
- **`raw`**: the source split at `\n[ \t]*\n`; each piece, squashed, is a paragraph when not
  empty.
- any other type (such as the old `heading`): nothing.

## Outputs

`outputs` is read only when it is an array; items that are not objects are skipped. By
`output_type`:

- **`stream`** (stdout and stderr alike): its `text`.
- **`execute_result`** and **`display_data`**: `data["text/plain"]` when `data` is an object;
  other representations (images, HTML, JSON, ...) are dropped, so an output with no
  `text/plain` gives nothing.
- **`error`**: `ename` and `evalue`, each with ANSI codes removed and trimmed; the text is
  `<ename>: <evalue>`, or just the one that is not empty. A code block of it when not empty.
  The traceback is dropped.

For a stream or a `text/plain` output: remove ANSI codes, then trailing whitespace; when the
result is not blank, it is a code block with text `Output:\n` followed by it (leading
whitespace kept). Each output is its own block, even when a stream arrives in several
outputs.

Lengths play no part in this reader. The only regex with a Unicode set is the trailing
whitespace one (PHP `\s+$` with the `u` flag).
