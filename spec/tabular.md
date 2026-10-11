# Tabular profile

Spreadsheets (CSV, XLSX) are not chunked. Each table is cleaned and profiled at ingestion, with
no Jev request; at retrieval Jev picks the table, measure, operation, grouping and filter values
from options built from the real schema, and code filters and computes.

## Reading

- **CSV**: drop a UTF-8 byte-order mark; text that is not valid UTF-8 is decoded as
  Windows-1252. Standard CSV quoting, no escape character. Rows are numbered from 1, cells from
  0; a cell is dropped when it trims to nothing, a row when no cell is left.
- **XLSX**: sheets in workbook order, with their names and whether they are hidden (`hidden` or
  `veryHidden`). A shared string is its `t` text followed by the `t` of each rich-text run; an
  inline string is its `is/t`; any other cell is its `v`. A relationship target that starts
  with `/` is a path from the package root, any other a path from `xl/`. A row or cell without a
  reference takes the position after the one before it. Hidden sheets are skipped.

## Tables

Per visible sheet, walking its rows:

- A row of two or more cells right after a table row (row number + 1) extends the table, its
  cells read up to the header's width.
- Any other row of two or more cells starts a table: it is the header (cells up to the highest
  column number); the one-cell rows collected since the last table are its caption, and the last
  caption line starting `Table ` and a word is its title.
- A one-cell row ends any table and is collected as caption and as sheet text.
- A table with no rows is not kept: its caption lines and its header cells (non-empty, joined by
  `: `) are prepended to the next table's caption.
- Columns whose header is empty and every cell blank are dropped.

All cell text is squashed (whitespace runs to one space, trimmed).

## Cells

- **Blank**: `''`, `#`, `[x]`, `[c]`, `[z]`, `[u]`, `-`, `–`, `..`, `n/a`, `N/A`, `NA`, `:`.
- A leading `£`, `$` or `€` (after an optional minus) is the unit; a trailing `%` is the unit.
- What is left, with commas and spaces removed, matching `-?\d+(\.\d+)?([eE][-+]?\d+)?` is a
  number (float).
- `d/m/yyyy` or `m/d/yyyy` becomes `yyyy-mm-dd`. The order is per table: day first unless a
  cell's first field is at most 12 and its second above 12 before any cell's first field above
  12 is met.
- Anything else stays as written.
- `[note N]`, `[provisional]` and `[revised]` markers are removed from headers and cells
  (case-insensitive) and squashed; a cell's note numbers are recorded against its cleaned text,
  a header's against its column.

## Normalising a table

- **Header names**: empty becomes `Column N` (N from 1); a lone `£`, `$` or `€` becomes
  `Amount (£)` etc.
- **Melting**: a header cell after the first is a period when it is `yyyy`, `yyyy Nth quarter`
  (1st to 4th) or `yyyyQn`. When there are at least 5 periods and they are at least half the
  header, each row becomes one row per period: the other columns, then `Year`, then (when any
  period has a quarter) `Quarter` and `Period` (the header text), then the value. The value
  column is named after the first unit-like word in brackets in the title or caption
  (`\(([A-Za-z%£$€]{1,10})\)`), else `Value`; when a constant text column whose name contains
  "indicator" or "series" exists, it takes that column's value instead.
- **Rows** keep their source as `sheet!row`.

## Columns

For each column, over all rows: present values (not null); distinct values counted by key
(numbers by `Number.key`), most frequent first, ties in first-seen order.

- **type**: `number` when at least 90% of present values are numbers; else `date` when at least
  90% are `yyyy-mm-dd` strings; else `text`.
- **role**, first match:
  1. `constant`: one distinct value, more than one row, no blanks;
  2. `period`: named `Year`, `Quarter` or `Period`, or of type date;
  3. `id`: more than 60% of present values distinct and the name has the word no, number, id
     or code;
  4. `text`: type text with distinct values averaging over 80 characters;
  5. `measure`: type number;
  6. `category`: at most 60 distinct values;
  7. `label`.
- Then **aliases**: a label, category or id column whose values map one to one onto another
  such column with the same distinct count, and whose top values are shorter in total, becomes
  `alias` of it.
- Fields: `name`, `type`, `role`, `blank`, `distinct`, `top` (first 6 keys), `unit` (the cell
  unit seen in the column, else `(GWh)`-style units in its name, else `%` for "per cent"),
  `min`/`max` for number and date columns, `values` (all keys) for category, period (sorted
  naturally), label and id columns.

## Joining

A lookup table is one with a label, id or alias column whose distinct count equals its row
count. Its category columns with fewer distinct values than that key are copied onto every
other table of the same document that has the key column, when at least 90% of that table's
distinct key values are found in the lookup. The joined table is profiled again and records
`"<columns> joined from "<title>" on <key>"`.

## Notes

A sheet named `Note` or `Notes` (any case) supplies notes: in each of its tables, the header and
every row whose first cell is `Note N` give note N the second cell. A label marked with notes in
one table carries them in every table where a value of a column with listed values (category,
label, period, id, alias) equals it, case-insensitively.

## Stored form

A document's `data.tables` is a list of tables, each with `columns` as a list in column order,
because a JSON reader built on plain objects (JavaScript) reorders names that look like
integers. Readers index columns by name after loading. Column names are always strings, in
stored documents and in requests, and every map in a Jev request keeps insertion order on the
wire (the JavaScript package writes the request body itself, as JSON.stringify would reorder
integer-like keys).

## Units at ingestion

Each table is a unit `t1`, `t2`... of kind `table`, path `[sheet]`, meta `{table: index}`, text
the card as text (below, 6 examples). A sheet with no table and some text is a unit `s<n>` of
kind `passage` (n is the sheet's position among visible sheets, from 1) with its lines joined
by newlines. A table is titled by its caption title, else `"<document title>: <sheet>"` when the
document has more than one visible sheet, else the document title, with ` (n)` added when the
sheet holds several tables.

## Card

Per column, by role:

- measure: `number (unit), min to max`
- period: `period|date, N values from first to last`
- constant: `same in every record: value`
- alias: `another name for each <column>, e.g. a; b; c`
- category: `one of N values: a; b; ...` (every value)
- otherwise: `identifier|text, N distinct, e.g. ...` (the examples, each cut to 60 characters)

then `; blank in N records` unless constant. The card is `{title, sheet, records, columns,
about?, layout?, joined?}`: `about` is the first 4 caption lines joined by spaces, `layout` the
melt description, `joined` the join notes joined by `; `.

## Planning

Exact Jev wording is in `Planner` (PHP) and recorded in the fixtures. Three requests:

1. **Route** over the candidate tables, labelled `t1`...: the cards with value hints (each label
   and id column gains `; values sharing a word with the question: ...`, up to 5, most shared
   words first); choice `table` (plus `none`), noul `meaning`, noul `answerable`. No table, or
   `answerable` below 0.5, abstains. `meaning` at 0.5 or more returns the meaning context.
2. **Columns**: choice `measure` over measure columns `m1`... plus `none`; choice `operation`
   over list, sum, average, count, max, min, plus change and percent when the table has a
   measure column and a period column; choice `group` over `none` and category, period and
   label columns `g1`...; choice `rank` over `none` and the same columns (asked when there is
   one), which column the question asks to come first or last; noul `f<i>` per filterable
   column (not constant, text or alias); noul `b<i>` per category column with blanks, listing
   up to 12 of the blank rows' labels. Filter and blank-row nouls count at 0.5 or more.
   When `group` is `none`, the `rank` column becomes the group for sum, average, count,
   change and percent, and for max or min with no measure (the value with the most or fewest
   records); a max or min of a measure keeps its single record and ignores `rank`.
3. **Values**, per filtered column in order, keyed `c<n>`:
   - period: choices `kind` (one, span, all), `one`, `from`, `to` over the period values;
   - measure (only when the question has numbers): choices `cmp` (above, atleast, below,
     atmost, none) and `num` over the question's numbers labelled `n0`...;
   - category: a noul per value, labelled `v0`...; label and id: the same over a shortlist of 12
     values by score, highest first, column order among equals. For each question word in
     order (repeats included), the values with a word matching it each add 1 / the number of
     such values, so a rare name ("Blackpool") outranks a word half the column shares
     ("council"). Sums are of doubles in that order.
   A value noul counts at 0.5 or more. A category, label or id column where no value counts is
   **unmatched**: it is not filtered, and the plan lists it (a period column answered `all`, or
   a measure answered `none`, is not unmatched).

The plan is `{measure, operation, group, filters, drop_blank, unmatched, relaxed}`, and the
trace carries every field of it. `relaxed` is set by the zero-match fallback below, else null.

**Words** of a text: lowercase runs of letters and digits, three characters or more, minus the
stop words in `Planner`. Two words match when equal or when both have four or more characters
and one starts with the other.

**Numbers** in the question: `zero` as 0, then each `(?:[£$€]\s?)?(\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?)\s*(million|billion|thousand|bn|m|k)?\b`
(case-insensitive), scaled by 1e6, 1e9 or 1e3, written as the trimmed match; then each
`\b(a|one|two|three|four|five|six|seven|eight|nine|ten|twelve|twenty|fifty|hundred)\s+(hundred|thousand|million|billion)\b`
(case-insensitive; `a` is 1), the word's number times 1e2, 1e3, 1e6 or 1e9, written as the
match ("one billion" is 1e9).

## Zero-match fallback

Filter values are always real values of their column, so a value that is spelled differently
cannot occur; no records means the constraints contradict each other or ask for something the
table does not hold. The constraints of a plan, in order, are its filters (in plan order) then
its blank-row columns (written `<column> not blank`).

- When the plan selects no record and has two or more constraints, each constraint is tried
  left out on its own. Of those whose removal selects at least one record, the one left out is
  the one the question names least, then the one that selects the fewest records, then the
  first in order. How fully the question names an `in` filter is the highest, over its chosen
  values, of the share of the value's words that match a question word (a value with no words
  adds nothing; the filter starts at 0); every other constraint scores 1. A value Jev picked
  for a word it shares with the question ("The Hub - Transport Advisory" for "Transport
  Focus") goes before one the question names outright. `relaxed` is
  `{drop: "filter"|"blank", column, matched}`, `matched` the records it then selects. No Jev
  request is made.
- Otherwise (one constraint, or no single removal helps) `relaxed` stays null and the
  context states that nothing matched, with what each constraint selects alone.

Only one constraint is ever left out, and the context always shows the plan as asked first, so
a reader can still answer "none" when the relaxed question is not the one asked.

## Executing

- **Filters**: a blank-row column must be present; `in` matches the value's key against the
  chosen values; `range` compares numerically when both sides are numeric, else by key as
  strings, with either end open, and with its ends swapped when the start is after the end
  (in matching and in the query line); `threshold` needs a number value.
- **Relaxed**: when the plan selects no record and `relaxed` is set, the constraint it names is
  removed and everything below runs on the plan without it.
- No measure with sum, average, max, min, change or percent is a count. Sum, average, count,
  change or percent with no group and an `in` filter naming several values groups by that
  column.
- **change** and **percent** need a span: the first `range` filter on a period column with both
  ends (after any swap). Each end's figure is the sum of the measure's number values in the
  records whose period key equals that end, null when there are none; change is end minus
  start, percent is change / start × 100 (null when either is null or the start is 0). Without
  a span the operation is sum, and the context states it. Overall, the records shown are those
  at either end; grouped, none.
- **count** counts distinct source rows; the others use number values only, the rest counted as
  blank. With no number value, sum, average, max and min are null (a sum of blanks is not 0),
  written `no data` in result and group lines (no unit); a blank count shows as `N blank`. max
  and min also return their record (the first with the extreme value).
- **Grouped**: groups keyed by value key (`(blank)` for null), sorted by value, highest first,
  lowest first when the planned operation is min (null last either way, equal values in
  first-seen order), at most 60 shown as `  key: value unit (N records, M blank)`; a change as
  `  key: <figure> (<start>: <start figure>; <end>: <end figure>)`, the figure with the
  measure's unit for change and `%` for percent.
- **Records shown**: grouped results none; max or min with at most 60 matches all of them sorted
  by the measure; another aggregate its record, else the top 5 by measure; a list with a
  measure and more than 60 matches sorted by the measure; else the matches. All are written
  when there are at most 60; otherwise the first 40, then a `Not shown` line. A filtered list
  slightly over 40 records is usually the whole answer ("every payment to Network
  Rail", 46 records), while a list of thousands cannot be written whole, so a sample of 40
  and a summary serve it better than 60.

## Context

```
Source: <title> [<table id>], <N> records. Columns: <non-constant columns, with units>
Query: <operation> of <measure>; <filters>; <column> not blank; grouped by <column>
No <column> value matched the question, so the records are not filtered by <column>.
Matched <n> records.
No record matches the filters. Each on its own matches: <constraint> (<n> records); ...
Result: ...
Records (<k> of <n>):
source | <columns>
<sheet!row> | <values by key>
Not shown: <n> more records, <measure> from <min> to <max> <unit>, sum <sum> <unit>.
Note <N>: <text>
```

- The `No <column> value matched` line comes once per unmatched column, in plan order.
- In the query line percent is written `percent change`. A change result is
  `Result: change|percent change of <measure> from <start> to <end> = <figure> (<start>: <a>; <end>: <b>)`,
  or, grouped, `Result: ... from <start> to <end> by <column> (<N> groups):` and the group
  lines. Without a span, `A change needs a span of a period column with a start and an end,
  and none was chosen, so this is the sum.` follows the query line.
- When the plan has a measure, the operation is not count, and every matched record is blank in
  the measure, `<measure> is blank in all <n> matched records.` follows the match lines. For
  each `range` filter on a period column it adds ` The table holds <measure> values for
  <column> from <first> to <last> only.` (the column's listed values, in order, that some record
  with a number in the measure has), or ` The table holds no <measure> value for any
  <column>.` A source that leaves its latest year empty then reads as missing, not as 0.
- `No record matches the filters` comes when nothing matched and the plan (after any
  relaxation) has a constraint; each constraint's count is of the records it selects alone.
- `Not shown` comes when records were cut; its range and sum (`Number.format`) are over the cut
  records' number values of the measure (` <unit>` only when the measure has one), and it is
  `Not shown: <n> more records.` when there is no measure or none of them has a number.
- With `relaxed` applied, the context opens with the plan as asked, then the plan without the
  constraint, both written with the query rules (operation and implicit grouping included):

```
Source: ...
Query: <the plan as asked>
Matched 0 records.
No record matches every filter, so this one is left out: <constraint>.
Query: <the plan without it>
Matched <n> records.
Result: ...
```

The meaning context is `Table: <title> [<id>]`, the card as text with 8 examples, every record
when there are 60 or fewer, and each referenced note as `Note N (<where>): <text>`.

## Number

`Number.format`: whole numbers below 10,000 plain, from 10,000 with comma thousands separators;
other numbers to four decimals, trailing zeros and point dropped, separators from 10,000.
`Number.key`: the same without separators. `(none)` for null.
