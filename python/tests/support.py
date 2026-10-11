"""A TypeSafe client that answers from a script over an httpx2 mock transport and records what it was sent."""
from __future__ import annotations

import base64
import json
from collections.abc import Iterable
from pathlib import Path
from typing import Any

import httpx2
from typesafe_sdk import RetryPolicy, TypeSafeClient

from context_atlas import Document
from context_atlas.tabular import profile
from context_atlas.tabular.grid import Grid

ROOT = Path(__file__).resolve().parents[2]

FIXTURES = ROOT / 'spec' / 'fixtures.json'


class FakeJev:
    """Answers the i-th request with the i-th scripted body, or with HTTP 500 for a `None` body."""

    def __init__(self, responses: Iterable[dict[str, Any] | None]) -> None:
        self.responses = list(responses)
        self.requests: list[dict[str, Any]] = []
        self.client = TypeSafeClient(
            api_key='test-key',
            transport=httpx2.MockTransport(self._handle),
            retry=RetryPolicy(max_retries=0),
        )

    def _handle(self, request: httpx2.Request) -> httpx2.Response:
        self.requests.append(json.loads(request.content))
        index = len(self.requests) - 1
        body = self.responses[index] if index < len(self.responses) else None

        if body is None:
            return httpx2.Response(500, json={'error': 'scripted failure'})

        return httpx2.Response(200, json=body)


def fixture_cases() -> list[dict[str, Any]]:
    return json.loads(FIXTURES.read_text(encoding='utf-8'))['cases']


def spreadsheet(source: dict[str, Any]) -> Document:
    """A fixture's spreadsheet: sheet name to `{csv}`, `{csv_base64}` or `{xlsx}`, a path from the repository root."""
    sheets = []

    for name, cells in source['sheets'].items():
        if 'xlsx' in cells:
            grid = Grid.xlsx(ROOT / cells['xlsx'])
        elif 'csv_base64' in cells:
            grid = Grid.csv(base64.b64decode(cells['csv_base64']), name)
        else:
            grid = Grid.csv(cells['csv'], name)
        sheets.extend(grid.sheets)

    return profile.ingest(Grid(sheets), source['id'], source['title'])


def json_equal(actual: Any, expected: Any) -> bool:
    """
    Equal as JSON, with numbers compared by value and an empty map matching the `[]` PHP writes
    for one. `actual` is round-tripped through JSON first, so tuples and lists compare alike.
    """
    return _equal(json.loads(json.dumps(actual)), expected)


def _equal(actual: Any, expected: Any) -> bool:
    if expected == [] and actual == {}:
        return True
    if isinstance(actual, dict) and isinstance(expected, dict):
        return actual.keys() == expected.keys() and all(_equal(actual[key], expected[key]) for key in actual)
    if isinstance(actual, list) and isinstance(expected, list):
        return len(actual) == len(expected) and all(_equal(a, e) for a, e in zip(actual, expected, strict=True))
    if isinstance(actual, bool) or isinstance(expected, bool):
        return actual is expected

    return actual == expected
