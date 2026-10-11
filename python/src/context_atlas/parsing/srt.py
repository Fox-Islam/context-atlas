"""
Reads SubRip captions: one paragraph per utterance of each cue, with the speaker from a leading
`Name:` and the cue's start time in meta. A cue starts at each timing line, so a missing index or
a blank line inside a cue's text is read through. The rules are stated in
spec/formats/transcripts.md.
"""
from __future__ import annotations

import re

from ..blocks import Block
from . import captions

_TIMING = re.compile(r'^[ \t]*([0-9]+):([0-9]{2}):([0-9]{2})[,.][0-9]{1,3}[ \t]*-->')
_INDEX = re.compile(r'^[ \t]*[0-9]+[ \t]*$')
_MARKUP = re.compile(r'<[^>]*>|\{\\[^}]*\}')


def parse(source: str) -> list[Block]:
    reader = captions.Captions()
    found: list[Block] = []
    time: str | None = None
    cue: list[str] = []

    for line in captions.normalise(source).split('\n'):
        timing = _TIMING.match(line)
        if timing is None:
            cue.append(line)
            continue
        if cue and _INDEX.match(cue[-1]):
            cue.pop()
        if time is not None:
            found.extend(_cue(reader, cue, time))
        time = captions.time(timing.group(1), timing.group(2), timing.group(3))
        cue = []
    if time is not None:
        found.extend(_cue(reader, cue, time))

    return found


def _cue(reader: captions.Captions, lines: list[str], time: str) -> list[Block]:
    return reader.blocks([_MARKUP.sub('', line) for line in lines], time)
