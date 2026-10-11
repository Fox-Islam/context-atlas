from __future__ import annotations

from typing import Any

from support import FakeJev

from context_atlas import Collection, Processor, parse_markdown


def collection() -> Collection:
    processor = Processor()

    return Collection([
        processor.ingest_blocks(parse_markdown('# Fees\n\nInvoices are monthly.'), 'a'),
        processor.ingest_blocks(parse_markdown('# Fees\n\nInvoices are weekly.'), 'b'),
    ])


def test_nearest_with_four_parameters_is_given_the_documents() -> None:
    seen: list[Any] = []

    def nearest(query: str, limit: int, kinds: list[str] | None, documents: list[str] | None) -> list[dict[str, str]]:
        seen.append((limit, documents))
        return []

    Processor(FakeJev([]).client).retrieve('invoices', collection(), nearest, ['b'])

    assert seen == [(200, ['b'])]


def test_nearest_with_three_parameters_is_called_without_them() -> None:
    seen: list[Any] = []

    def nearest(query: str, limit: int, kinds: list[str] | None) -> list[dict[str, str]]:
        seen.append(limit)
        return []

    Processor(FakeJev([]).client).retrieve('invoices', collection(), nearest)

    assert seen == [20]
