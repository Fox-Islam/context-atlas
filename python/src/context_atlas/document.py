"""
Everything ingestion builds for one input document, as plain data the caller stores where it
likes and hands back at retrieval. The PHP, JavaScript and Python packages read and write the
same JSON shape, described in spec/document.md.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

from .errors import ContextAtlasError

FORMAT = 2


@dataclass(frozen=True)
class Unit:
    """One retrievable piece of a document: `text` is what the caller embeds and retrieval returns."""

    id: str
    kind: str
    text: str
    path: list[str] = field(default_factory=list)
    meta: dict[str, Any] = field(default_factory=dict)

    @staticmethod
    def from_dict(value: dict[str, Any]) -> Unit:
        path = value.get('path') or []
        meta = value.get('meta') or {}

        return Unit(
            str(value['id']),
            str(value['kind']),
            str(value['text']),
            [str(item) for item in (path if isinstance(path, list) else [path])],
            meta if isinstance(meta, dict) else {},
        )

    def to_dict(self) -> dict[str, Any]:
        """Without `path` and `meta` when they are empty."""
        value: dict[str, Any] = {'id': self.id, 'kind': self.kind, 'text': self.text}
        if self.path:
            value['path'] = list(self.path)
        if self.meta:
            value['meta'] = dict(self.meta)

        return value


@dataclass(frozen=True)
class Link:
    """A typed relation between two units of one document; `from_` is `from` in the JSON."""

    type: str
    from_: str
    to: str

    @staticmethod
    def from_dict(value: dict[str, Any]) -> Link:
        return Link(str(value['type']), str(value['from']), str(value['to']))

    def to_dict(self) -> dict[str, str]:
        return {'type': self.type, 'from': self.from_, 'to': self.to}


@dataclass
class Document:
    id: str
    profile: str
    units: list[Unit]
    links: list[Link] = field(default_factory=list)
    lexical: dict[str, dict[str, Any]] = field(default_factory=lambda: {'length': {}, 'terms': {}})
    detected: dict[str, Any] | None = None
    data: dict[str, Any] = field(default_factory=dict)

    @staticmethod
    def from_dict(value: dict[str, Any]) -> Document:
        """
        Reads a stored document. Every number in table rows and column bounds becomes a float,
        since JavaScript writes `1960.0` as `1960`, and `[]` reads as an empty map.

        :raises ContextAtlasError: when it is not a format 2 document
        """
        version = value.get('format')
        if isinstance(version, bool) or version != FORMAT or not isinstance(version, int):
            raise ContextAtlasError(f'Not a format {FORMAT} document.')

        lexical = _map(value.get('lexical'))

        return Document(
            str(value['id']),
            str(value['profile']),
            [Unit.from_dict(unit) for unit in value.get('units') or []],
            [Link.from_dict(link) for link in value.get('links') or []],
            {
                'length': _map(lexical.get('length')),
                'terms': {term: _map(units) for term, units in _map(lexical.get('terms')).items()},
            },
            _detected(value.get('detected')),
            _data(_map(value.get('data'))),
        )

    def to_dict(self) -> dict[str, Any]:
        return {
            'format': FORMAT,
            'id': self.id,
            'profile': self.profile,
            'detected': self.detected,
            'units': [unit.to_dict() for unit in self.units],
            'links': [link.to_dict() for link in self.links],
            'lexical': {'length': self.lexical['length'], 'terms': self.lexical['terms']},
            'data': self.data,
        }

    def unit(self, unit_id: str) -> Unit | None:
        return next((unit for unit in self.units if unit.id == unit_id), None)


def _map(value: Any) -> dict[str, Any]:
    return value if isinstance(value, dict) else {}


def _detected(value: Any) -> dict[str, Any] | None:
    if not isinstance(value, dict):
        return None

    score = value.get('score')

    return {
        'profile': str(value.get('profile') or ''),
        'score': None if score is None else float(score),
        'by': str(value.get('by') or ''),
    }


def _data(data: dict[str, Any]) -> dict[str, Any]:
    tables = data.get('tables')
    if isinstance(tables, list):
        data = {**data, 'tables': [_table(table) for table in tables]}

    return data


def _table(table: dict[str, Any]) -> dict[str, Any]:
    found = table.get('columns')
    columns: Any = (
        [_bounds(column) for column in found]
        if isinstance(found, list)
        else {name: _bounds(column) for name, column in _map(found).items()}
    )
    rows = [
        {**row, 'values': {name: _float(value) for name, value in _map(row.get('values')).items()}}
        for row in table.get('rows') or []
    ]
    maps = {name: _map(table.get(name)) for name in ('row_notes', 'column_notes', 'notes', 'units')}

    return {**table, 'columns': columns, **maps, 'rows': rows}


def _bounds(column: dict[str, Any]) -> dict[str, Any]:
    return {**column, **{name: _float(column[name]) for name in ('min', 'max') if name in column}}


def _float(value: Any) -> Any:
    return float(value) if isinstance(value, int) and not isinstance(value, bool) else value
