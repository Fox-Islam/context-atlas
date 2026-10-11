"""
Reads WebVTT captions: one paragraph per utterance of each cue, with the speaker from a
`<v Name>` voice span or a leading `Name:` and the cue's start time in meta. NOTE, STYLE and
REGION blocks are dropped. The rules are stated in spec/formats/transcripts.md.
"""
from __future__ import annotations

import re

from .. import text
from ..blocks import Block
from ..errors import ContextAtlasError
from . import captions

_HEADER = re.compile(r'^WEBVTT(?:[ \t].*)?$')
_TIMING = re.compile(r'^[ \t]*(?:([0-9]+):)?([0-9]{2}):([0-9]{2})\.[0-9]{3}[ \t]+-->[ \t]+')
_SKIPPED = re.compile(r'^(?:NOTE|STYLE|REGION)(?:[ \t]|$)')
_VOICE = re.compile(r'<v(?:\.[^ \t\n\f.>]+)*(?:[ \t\n\f]+([^>]*))?>|</v>')
_TAG = re.compile(r'<[^>]*>')
_ENTITY = re.compile(r'&(amp|lt|gt|nbsp|lrm|rlm|quot|apos|#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6});')
_ENTITIES = {'amp': '&', 'lt': '<', 'gt': '>', 'nbsp': '\xa0', 'lrm': '', 'rlm': '', 'quot': '"', 'apos': "'"}


def parse(source: str) -> list[Block]:
    """:raises ContextAtlasError: when the first line is not `WEBVTT`"""
    lines = captions.normalise(source).split('\n')
    if not _HEADER.match(lines[0]):
        raise ContextAtlasError('A WebVTT file must start with a "WEBVTT" line.')

    reader = captions.Captions()
    found: list[Block] = []
    for group in _groups(lines)[1:]:
        found.extend(_cue(reader, group))

    return found


def _groups(lines: list[str]) -> list[list[str]]:
    """Runs of lines between blank lines, also split before a timing line that cannot belong to the run before it."""
    groups: list[list[str]] = []
    group: list[str] = []
    for line in lines:
        if text.trim(line) != '':
            if '-->' in line and _starts_cue(group, not groups):
                groups.append(group)
                group = []
            group.append(line)
        elif group:
            groups.append(group)
            group = []
    if group:
        groups.append(group)

    return groups


def _starts_cue(group: list[str], header: bool) -> bool:
    """
    Whether a line holding `-->` ends the open run: always in the header, otherwise unless the
    run is one line without `-->` (a cue identifier).
    """
    return bool(group) and (header or len(group) > 1 or '-->' in group[0])


def _cue(reader: captions.Captions, group: list[str]) -> list[Block]:
    if _SKIPPED.match(group[0]):
        return []
    if '-->' in group[0]:
        at = 0
    elif len(group) > 1 and '-->' in group[1]:
        at = 1
    else:
        return []
    timing = _TIMING.match(group[at])
    if timing is None:
        return []

    time = captions.time(timing.group(1) or None, timing.group(2), timing.group(3))
    found: list[Block] = []
    for speaker, raw in _voices('\n'.join(group[at + 1 :])):
        lines = _decode(_TAG.sub('', raw)).split('\n')
        if speaker is None:
            found.extend(reader.blocks(lines, time))
        else:
            block = reader.utterance(text.squash(' '.join(lines)), time, speaker)
            if block is not None:
                found.append(block)

    return found


def _voices(cue: str) -> list[tuple[str | None, str]]:
    """Splits cue text at `<v>` voice spans: text before the first and after a `</v>` has no voice."""
    segments: list[tuple[str | None, str]] = []
    speaker: str | None = None
    at = 0
    for match in _VOICE.finditer(cue):
        segments.append((speaker, cue[at : match.start()]))
        at = match.end()
        speaker = None if match.group(0) == '</v>' else text.squash(_decode(match.group(1) or ''))
        speaker = speaker or None
    segments.append((speaker, cue[at:]))

    return segments


def _decode(source: str) -> str:
    def replace(match: re.Match[str]) -> str:
        name = match.group(1)
        if name[0] != '#':
            return _ENTITIES[name]
        code = int(name[2:], 16) if name[1] in 'xX' else int(name[1:])
        valid = 0 < code <= 0x10FFFF and not 0xD800 <= code <= 0xDFFF

        return chr(code) if valid else '�'

    return _ENTITY.sub(replace, source)
