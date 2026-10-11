"""
What WebVTT and SRT share: a cue's lines split into utterances at `- ` lines and `Name:` lines,
the speaker read from a leading `Name:` or carried over from the last named cue, and the cue start
written as hh:mm:ss. One instance reads one file. The rules are stated in
spec/formats/transcripts.md.
"""
from __future__ import annotations

import functools
import re
from typing import Any

from .. import text
from ..blocks import Block
from ..prose.speakers import NOT_NAMES, category_class
from ..text import WHITESPACE

_DASH = re.compile(f'^-[{WHITESPACE}]+')


@functools.cache
def _name() -> str:
    word = f"[{category_class('Lu')}][{category_class('L', 'M')}'\\u2019.\\-]*"

    return f'{word}(?: {word}){{0,3}}'


@functools.cache
def _speaker() -> re.Pattern[str]:
    return re.compile(rf'^({_name()})(?: \(([^()]+)\))?:[{WHITESPACE}]+([^{WHITESPACE}].*)$')


@functools.cache
def _speaker_line() -> re.Pattern[str]:
    return re.compile(rf'^({_name()})(?: \([^()]+\))?:(?:[{WHITESPACE}]|$)')


class Captions:
    def __init__(self) -> None:
        self._current: dict[str, str] | None = None

    def blocks(self, lines: list[str], time: str) -> list[Block]:
        """
        Splits a cue's text lines (tags already removed) into utterances: a line starting `- `
        starts one (without the dash), as does a line starting `Name:` after the first line.
        """
        groups: list[list[str]] = []
        dashes: list[bool] = []
        for line in lines:
            line = text.trim(line)
            if line == '':
                continue
            dash = _DASH.match(line) is not None
            line = text.trim(_DASH.sub('', line, count=1)) if dash else line
            named = _speaker_line().match(line)
            if not groups or dash or (named is not None and _is_name(named.group(1))):
                groups.append([])
                dashes.append(dash)
            groups[-1].append(line)

        found = []
        for group, dash in zip(groups, dashes, strict=True):
            block = self.utterance(text.squash(' '.join(group)), time, None, dash)
            if block is not None:
                found.append(block)

        return found

    def utterance(self, body: str, time: str, voice: str | None = None, dash: bool = False) -> Block | None:
        """
        A block for one utterance, meta {speaker?, speaker_full?, time}. The speaker is the voice
        when given, else a leading `Name:` or `Name (role):`, else the current speaker; a dash
        utterance without a name has no speaker and clears the current one.
        """
        match = None if voice else _speaker().match(body)
        if voice:
            self._current = {'speaker': voice}
        elif match is not None and _is_name(match.group(1)):
            self._current = {'speaker': match.group(1)}
            if match.group(2) is not None:
                self._current['speaker_full'] = f'{match.group(1)} ({match.group(2)})'
            body = match.group(3)
        elif dash:
            self._current = None

        meta: dict[str, Any] = {**(self._current or {}), 'time': time}

        return None if body == '' else Block.paragraph(body, meta)


def time(hours: str | None, minutes: str, seconds: str) -> str:
    """hh:mm:ss from a timestamp's parts; the hours padded to two digits."""
    return (hours if hours is not None else '0').rjust(2, '0') + f':{minutes}:{seconds}'


def normalise(source: str) -> str:
    """Line endings as `\\n`, a leading byte order mark removed."""
    source = source.replace('\r\n', '\n').replace('\r', '\n')

    return source[1:] if source.startswith('﻿') else source


def _is_name(name: str) -> bool:
    """
    False for a name in the stoplist or starting with a word in it, compared in lower case, and
    for a name with a digit.
    """
    lowered = name.lower()

    return lowered not in NOT_NAMES and lowered.split(' ')[0] not in NOT_NAMES and re.search(r'\d', lowered) is None
