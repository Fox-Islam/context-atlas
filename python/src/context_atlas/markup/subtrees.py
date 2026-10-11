"""
Cuts a key tree into units: a subtree that fits MAX tokens is one unit; a bigger one keeps its
own lines and its children of SMALL tokens or fewer as a header unit, and each larger child is
cut the same way. A unit's text is a breadcrumb line (`title: path`) and its source lines with
their common indent removed, so a schema read alone names where it sits.

A node's parent is its nearest ancestor path that has a node (TOML has none for `tool` in
`[tool.ruff]`). A child of more than SMALL tokens lying outside its parent's span, such as a
`[package.metadata]` written after other tables, is cut on its own by the node whose span holds
it, and its lines are left out of that node's unit. A path given twice (a repeated key) is one
child of its parent, at its first position, cut from its last node.

Path subtrees completed 35 of 40 questions on an OpenAPI document, a Compose file and a CI
workflow, against 30 for 40-line windows; following `$ref` and alias links took it to 38.

Links: `ref` from the unit holding a `$ref: "#/..."` (a JSON pointer into the same file) or an
alias (YAML, or an XML reference to an `id`) to the unit where its target starts.
"""
from __future__ import annotations

import re
from typing import Any

from .. import compat, text
from ..document import Link, Unit

MAX = 450

SMALL = 90

MARKUP = 'markup'

_EMPTY = re.compile(r'^\s*(#.*)?$', re.ASCII)
_INDEX = re.compile(r'^()\[\d+\]$', re.ASCII)
_TAGGED_INDEX = re.compile(r'^([^\[\]]*)\[\d+\]$', re.ASCII)
_ITEM_END = re.compile(r'\[\d+\]$', re.ASCII)
_NAMING_END = re.compile(r'(?:name|id|title|key)$', re.IGNORECASE | re.ASCII)


def build(outline: dict[str, Any], title: str, chars_per_token: int = 4) -> dict[str, Any]:
    """`{units, links}` for an outline from one of the readers."""
    cutter = _Cutter(outline, title, chars_per_token)
    cutter.cut('')

    return {'units': cutter.units, 'links': cutter.links()}


class _Cutter:
    def __init__(self, outline: dict[str, Any], title: str, chars_per_token: int) -> None:
        self.outline = outline
        self.title = title
        self.chars_per_token = chars_per_token
        self.entries: dict[str, dict[str, Any]] = {}
        self.children: dict[str, list[str]] = {}
        self.detached: list[str] = []
        self.done: set[str] = set()
        self.values: dict[str, dict[str, dict[str, int]]] = {}
        self.siblings: dict[str, list[str]] = {}
        self.units: list[Unit] = []
        self.owner: dict[int, str] = {}
        for entry in outline['entries']:
            self.entries[_key(entry['path'])] = entry
        for key, entry in self.entries.items():
            if not entry['path']:
                continue
            parent = self._parent_of(entry['path'])
            self.children.setdefault(parent, []).append(key)
            if not _within(entry, self.entries[parent]) and self._tokens(entry['start'], entry['end']) > SMALL:
                self.detached.append(key)

    def cut(self, key: str) -> None:
        if key == '' and self.outline.get('records') and '' in self.children:
            self._cut_records()
            return
        self.done.add(key)
        entry = self.entries[key]
        whole = key != '' and (self._tokens(entry['start'], entry['end']) <= MAX or key not in self.children)
        big = [] if whole else [
            child
            for child in self.children.get(key, [])
            if self._tokens(self.entries[child]['start'], self.entries[child]['end']) > SMALL
        ]
        detached = [
            found
            for found in self.detached
            if found not in self.done
            and _within(self.entries[found], entry)
            and not any(_within(self.entries[found], self.entries[b]) for b in big)
        ]
        covered: set[int] = set()
        for child in [*big, *detached]:
            covered.update(range(self.entries[child]['start'], self.entries[child]['end']))
        span = range(entry['start'], max(entry['start'], entry['end'] - 1) + 1)
        own = [i for i in span if i not in covered]
        useful = [i for i in own if not _EMPTY.match(self._line(i))]
        if own and (whole or len(useful) > 1 or not big):
            self._emit_own(key, own, big)
        for child in [*big, *detached]:
            if child not in self.done:
                self.cut(child)

    def _emit_own(self, key: str, own: list[int], big: list[str]) -> None:
        """
        Emits a node's own lines as one unit, or, past MAX tokens, as packs split at its small
        children: lines outside them join the pack being filled, and a child that would take a
        pack holding a child past MAX starts the next one. A pack's label ends with the first and
        last child it holds: `(top level) (build … cascade > [3])`.
        """
        entry = self.entries[key]
        path = entry['path']
        if text.tokens(self._text(own), self.chars_per_token) <= MAX:
            self._emit(path, own)
            return

        child_of: dict[int, str] = {}
        for child in self.children.get(key, []):
            found = self.entries[child]
            if child not in big and _within(found, entry):
                for i in range(found['start'], found['end']):
                    child_of[i] = child
        spans: dict[str, list[int]] = {}
        for line in own:
            if line in child_of:
                spans.setdefault(child_of[line], []).append(line)

        packs: list[list[int]] = [[]]
        held: list[list[str]] = [[]]
        for n, line in enumerate(own):
            child = child_of.get(line)
            if child is not None and (n == 0 or child_of.get(own[n - 1]) != child):
                if held[-1] and text.tokens(self._text([*packs[-1], *spans[child]]), self.chars_per_token) > MAX:
                    packs.append([])
                    held.append([])
                held[-1].append(child)
            packs[-1].append(line)

        for pack, children in zip(packs, held, strict=True):
            self._emit(path, pack, self._range(path, children))

    def _cut_records(self) -> None:
        """
        Cuts the records of a JSONL file in order: a record of more than SMALL tokens is cut on its
        own, and the runs of smaller records between them are packed into units of at most MAX
        tokens, each labelled by its first and last record; a pack of one record is that record's
        own unit.
        """
        self.done.add('')
        pack: list[int] = []
        held: list[str] = []
        for child in self.children.get('', []):
            found = self.entries[child]
            lines = list(range(found['start'], max(found['start'], found['end'] - 1) + 1))
            if self._tokens(found['start'], found['end']) > SMALL:
                self._emit_pack(pack, held)
                pack, held = [], []
                self.cut(child)
                continue
            if held and text.tokens(self._text([*pack, *lines]), self.chars_per_token) > MAX:
                self._emit_pack(pack, held)
                pack, held = [], []
            pack.extend(lines)
            held.append(child)
        self._emit_pack(pack, held)

    def _emit_pack(self, lines: list[int], held: list[str]) -> None:
        if len(held) == 1:
            self.cut(held[0])
        elif held:
            self._emit([], lines, self._range([], held))

    def _range(self, path: list[str], children: list[str]) -> str | None:
        """
        `(<first> … <last>)` naming the first and last child by their label segments after `path`,
        `(<first>)` for one, None for none.
        """
        names = [' > '.join(self._label(self.entries[child]['path'])[len(path) :]) for child in children]
        if not names:
            return None
        if len(names) == 1:
            return f'({names[0]})'

        return f'({names[0]} \u2026 {names[-1]})'

    def _text(self, numbers: list[int]) -> str:
        return '\n'.join(self._line(i) for i in numbers)

    def _emit(self, path: list[str], numbers: list[int], named: str | None = None) -> None:
        body = [self._line(i) for i in numbers]
        indents = [len(line) - len(line.lstrip(' ')) for line in body if compat.trim(line) != '']
        indent = min(indents) if indents else 0
        body = [line[min(indent, len(line) - len(line.lstrip(' '))) :] for line in body]
        label = self._label(path)
        unit_id = f's{len(self.units) + 1}'
        where = ('(top level)' if not path else ' > '.join(label)) + ('' if named is None else f' {named}')
        joined = '\n'.join(body).rstrip(' \t\n\r\x00\x0b')
        meta = {
            'path': list(path),
            'lines': [numbers[0] if numbers else 0, (numbers[-1] if numbers else 0) + 1],
            'profile': MARKUP,
        }
        segments = label if named is None else [*label, named]
        self.units.append(Unit(unit_id, 'subtree', f'{self.title}: {where}\n{joined}', segments, meta))
        for i in numbers:
            self.owner.setdefault(i, unit_id)

    def _label(self, path: list[str]) -> list[str]:
        """
        The path with each sequence index named by one of the item's children (`_name`):
        `steps > [name=Install dependencies]`. An outline that lists its own label fields (XML adds
        the `@` forms) also names a `tag[i]` segment: `item[name=Home]`.
        """
        pattern = _TAGGED_INDEX if 'labels' in self.outline else _INDEX
        out = []
        for i, segment in enumerate(path):
            match = pattern.match(segment)
            if match:
                name = self._name(path[: i + 1])
                if name is not None:
                    segment = f'{match.group(1)}[{name[0]}={name[1]}]'
            out.append(segment)

        return out

    def _name(self, item: list[str]) -> tuple[str, str] | None:
        """
        The field and value naming an item: the first label field with a value; else a child whose
        key ends in `name`, `id`, `title` or `key` (any case) and has a value; else a child with a
        non-empty one-line value, cut to 40 characters and `…` when longer than 60. In the last
        two, the first child whose value no same-name sibling item shares wins, else the first
        child. Children that are items themselves are passed over.
        """
        for field in self.outline.get('labels') or ['name', 'id', 'title', 'key']:
            found = self.entries.get(_key([*item, field]))
            if found is not None and found['value'] is not None:
                return field.lstrip('@'), found['value']

        children = [
            self.entries[child]
            for child in self.children.get(_key(item), [])
            if self.entries[child]['value'] is not None and not _ITEM_END.search(self.entries[child]['path'][-1])
        ]
        ending = [c for c in children if _NAMING_END.search(c['path'][-1].lstrip('@'))]
        plain = [c for c in children if c['value'] != '' and '\n' not in c['value']]
        for candidates in (ending, plain):
            if not candidates:
                continue
            chosen = next((c for c in candidates if self._unique(item, c)), candidates[0])
            value = chosen['value']
            if _same(candidates, plain) and len(value) > 60:
                value = value[:40] + '\u2026'
            return chosen['path'][-1].lstrip('@'), value

        return None

    def _unique(self, item: list[str], child: dict[str, Any]) -> bool:
        """
        Whether no same-name sibling of the item (another item under the same parent path whose last
        segment differs only in its index) has a child at the same relative path with the same value.
        """
        group = _key([*item[:-1], _ITEM_END.sub('', item[-1], count=1)])
        relative = _key(child['path'][len(item) :])
        counts = self.values.setdefault(group, {}).get(relative)
        if counts is None:
            counts = {}
            for sibling in self._siblings(item, group):
                found = self.entries.get(sibling + '\x1f' + relative)
                if found is not None and found['value'] is not None:
                    counts[found['value']] = counts.get(found['value'], 0) + 1
            self.values[group][relative] = counts

        return counts.get(child['value'], 0) == 1

    def _siblings(self, item: list[str], group: str) -> list[str]:
        if group not in self.siblings:
            parent = item[:-1]
            tag = re.compile(re.escape(_ITEM_END.sub('', item[-1], count=1)) + r'\[\d+\]$', re.ASCII)
            self.siblings[group] = [
                key
                for key in self.children.get(self._parent_of(item), [])
                if len(self.entries[key]['path']) == len(item)
                and self.entries[key]['path'][:-1] == parent
                and tag.match(self.entries[key]['path'][len(item) - 1])
            ]

        return self.siblings[group]

    def links(self) -> list[Link]:
        links: dict[str, Link] = {}
        for entry in self.entries.values():
            source = self._owner_of(entry['path'])
            value = entry['value'] if entry['value'] is not None else ''
            target = None
            if entry['path'] and entry['path'][-1] == '$ref' and value.startswith('#/'):
                target = [part.replace('~1', '/').replace('~0', '~') for part in value[2:].split('/')]
            elif entry['alias'] is not None:
                target = self.outline['anchors'].get(entry['alias'])
            destination = self._owner_of(target) if target is not None else None
            if source is not None and destination is not None and destination != source:
                links.setdefault(f'{source} {destination}', Link('ref', source, destination))

        return list(links.values())

    def _owner_of(self, path: list[str]) -> str | None:
        entry = self.entries.get(_key(path))

        return None if entry is None else self.owner.get(entry['start'])

    def _parent_of(self, path: list[str]) -> str:
        """The key of the nearest ancestor path that has a node; the root's otherwise."""
        for n in range(len(path) - 1, 0, -1):
            key = _key(path[:n])
            if key in self.entries:
                return key

        return ''

    def _tokens(self, start: int, end: int) -> int:
        return text.tokens('\n'.join(self.outline['lines'][start:end]), self.chars_per_token)

    def _line(self, i: int) -> str:
        lines = self.outline['lines']

        return lines[i] if 0 <= i < len(lines) else ''


def _key(path: list[str]) -> str:
    return '\x1f'.join(path)


def _within(inner: dict[str, Any], outer: dict[str, Any]) -> bool:
    return inner['start'] >= outer['start'] and inner['end'] <= outer['end']


def _same(left: list[dict[str, Any]], right: list[dict[str, Any]]) -> bool:
    """PHP's `===` on two lists of entries: the same entries in the same order."""
    return len(left) == len(right) and all(a is b or a == b for a, b in zip(left, right, strict=True))
