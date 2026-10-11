# Transcripts: WebVTT and SRT

How caption files become blocks (`spec/behaviour.md`, Blocks): one paragraph per utterance,
carrying the speaker and the cue's start time, so a section of them is conversational
(`spec/prose.md`, Conversations). The PHP implementation is `php/src/Parsing/VttParser.php`
and `SrtParser.php`, with the shared rules in `Captions.php`; the cases are the `read`
fixtures named `vtt: ...` and `srt: ...`.

## Shared rules

**Input.** UTF-8 (output for invalid UTF-8 is not part of the contract). `\r\n` and `\r`
become `\n`, and a leading U+FEFF is removed. Lines are the text split on `\n`. **Trim**,
**squash** and **whitespace** are the `spec/behaviour.md` rules; **blank** means empty after
trim. Regexes marked *(u)* need Unicode: `\p{Lu}`, `\p{L}`, `\p{M}` are the general categories
(`spec/behaviour.md`, Categories) and `\s` is the whitespace set; the others are ASCII-only.
Lengths play no part.

**Time.** A cue's start written as `hh:mm:ss`: the hours as written, left-padded with `0` to
two digits (`0` when the timestamp has no hours; `123` stays `123`), then the minutes and
seconds as written. Fractions are dropped.

**Utterances.** A cue's text lines (markup already removed) are split into utterances:

1. Each line is trimmed; blank lines are skipped.
2. A line matching `^-\s+` *(u)* is a dash line: that prefix is removed and the rest trimmed.
3. A line starts a new utterance when it is the first, a dash line, or matches
   `^(NAME)(?: \([^()]+\))?:(?:\s|$)` *(u)* with group 1 a **name** (below); otherwise it
   joins the current one. An utterance is a dash utterance when its first line is a dash line.

`NAME` is 1 to 4 words separated by single spaces, each a `\p{Lu}` followed by any of `\p{L}`,
`\p{M}`, `'`, `’`, `.`, `-`:

```
\p{Lu}[\p{L}\p{M}'’.\-]*(?: \p{Lu}[\p{L}\p{M}'’.\-]*){0,3}
```

A match of `NAME` is a **name** unless, lowercased (Unicode lowercase), it or its first word
(up to the first space) is in the stoplist `Speakers::NOT_NAMES` (`php/src/Prose/Speakers.php`,
the list the prose speaker pass uses), or it contains a digit. So `Ann`, `MARIA`, `Dr. Smith`
and `Jean-Luc Picard` are names; `Note`, `Step One`, `Q`, `the chair` and `Note this` are not.

**Current speaker.** A file is read with a current speaker (a `speaker` and an optional
`speaker_full`), none at the start. It carries across cues.

**Utterance block.** An utterance's lines are joined with spaces and squashed. Then, in order:

1. A WebVTT voice (not empty) becomes the current speaker, without `speaker_full`.
2. Otherwise, when the text matches `^(NAME)(?: \(([^()]+)\))?:\s+(\S.*)$` *(u)* and group 1
   is a name: the current speaker becomes group 1, with `speaker_full` `<group 1> (<group 2>)`
   when group 2 is present, and the text becomes group 3.
3. Otherwise a dash utterance clears the current speaker (the dash marks a change of speaker
   whose name is not given).
4. Otherwise the current speaker is kept: a cue without a name continues the last named one.

An empty text gives no block (the steps above apply all the same). Otherwise the block is a
paragraph with meta, keys in this order: `speaker` and `speaker_full` from the current speaker
(each when present), then `time`. Consecutive utterances by one speaker stay separate blocks (the
conversation reader joins them into one turn).

## WebVTT

The first line must match `^WEBVTT(?:[ \t].*)?$`; otherwise the error is
`A WebVTT file must start with a "WEBVTT" line.`

**Runs.** Lines are grouped into runs of non-blank lines separated by blank lines. A non-blank
line containing `-->` also ends the open run before it, when the open run is not empty and
either it is the first run (the header) or it has more than one line or its first line contains
`-->`. So a run of one line without `-->` (a cue identifier) keeps the timing line after it,
and a cue written straight after the header or after another cue's text, without a blank line,
starts a new run. The first run is the header and is dropped.

**Cues.** For each later run:

1. A run whose first line matches `^(?:NOTE|STYLE|REGION)(?:[ \t]|$)` is dropped.
2. The timing line is the first line when it contains `-->`, else the second when it contains
   `-->` (the first is an identifier, ignored); a run with neither is dropped.
3. The timing line must match `^[ \t]*(?:(\d+):)?(\d{2}):(\d{2})\.\d{3}[ \t]+-->[ \t]+`, else
   the run is dropped. Groups 1-3 give the time. Cue settings and the end time are ignored.
4. The lines after the timing line, joined with `\n`, are the cue text.

**Voices.** The cue text is split at each match of
`<v(?:\.[^ \t\n\f.>]+)*(?:[ \t\n\f]+([^>]*))?>|<\/v>`, in order. The text before the first
match is a segment with no voice; after a `<v ...>` match the following text is a segment whose
voice is group 1 with entities decoded (below) and squashed (no voice when that is empty);
after `</v>` the following text has no voice. So `<v Ben>Agreed.</v><v Cara>Seconded.` gives
two segments.

Each segment: remove every `<[^>]*>` (class, italic, bold, underline, ruby, language and
timestamp tags such as `<00:01.000>`), decode entities, and split into lines. A segment with a
voice is one utterance whose speaker is the voice (its text is not matched for `Name:`); a
segment without one is split into utterances by the shared rules. Each gives at most one block.

**Entities**, decoded in one pass, left to right, by
`&(amp|lt|gt|nbsp|lrm|rlm|quot|apos|#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6});`: `&amp;` `&`,
`&lt;` `<`, `&gt;` `>`, `&nbsp;` U+00A0 (squashing then makes it a space), `&lrm;` and `&rlm;`
nothing, `&quot;` `"`, `&apos;` `'`; a numeric reference is that code point, or U+FFFD for 0, a
surrogate or a value above U+10FFFF. Anything else (`&mdash;`, `&amp`) stays as written.

## SRT

There is no header and no error: a file without a timing line gives no blocks.

Lines are read in order. A **timing line** matches
`^[ \t]*(\d+):(\d{2}):(\d{2})[,.]\d{1,3}[ \t]*-->`; groups 1-3 give the time. Each timing
line starts a cue; the lines after it up to the next timing line are its text, except that the
last of them is dropped when it matches `^[ \t]*\d+[ \t]*$` (the next cue's index). Lines
before the first timing line are ignored. So a missing index, or a blank line inside a cue's
text, is read through.

Each line of a cue's text has every `<[^>]*>` (`<i>`, `<b>`, `<u>`, `<font ...>`) and every
`\{\\[^}]*\}` (position overrides such as `{\an8}`) removed; entities are not decoded. The lines
are then split into utterances by the shared rules.
