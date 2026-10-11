from __future__ import annotations

from typing import Any

from support import FakeJev, fixture_cases
from typesafe_sdk import Noul, SystemOneResponse

from context_atlas import Chunker, Config, JevRequest, Passage, Reranker, parse_markdown

CASE = next(
    case
    for case in fixture_cases()
    if case['kind'] == 'chunk' and case['format'] == 'markdown' and len(case['calls']) == 1 and not case['fail']
)
RANK = next(case for case in fixture_cases() if case['kind'] == 'rank')


def chunk_with(hook: Any) -> dict[str, Any]:
    return Chunker(hook, Config.from_dict(CASE['config'])).chunk(parse_markdown(CASE['input'])).to_dict()


def test_a_hook_is_handed_the_request_and_sends_it_itself() -> None:
    fake = FakeJev([call['response'] for call in CASE['calls']])
    seen: list[JevRequest] = []

    def hook(request: JevRequest) -> SystemOneResponse | None:
        seen.append(request)
        return fake.client.system_one(request['state'], request['questions'])

    assert chunk_with(hook) == CASE['expected']
    assert seen[0]['state'] == CASE['calls'][0]['request']['state']
    assert all(isinstance(question, Noul) for question in seen[0]['questions'].values())


def test_a_hook_that_raises_or_returns_none_leaves_the_chunks_structural() -> None:
    def raising(_: JevRequest) -> SystemOneResponse | None:
        raise RuntimeError('cache down')

    for hook in (raising, lambda _: None):
        result = chunk_with(hook)
        assert result['used_jev'] is False
        assert not any(chunk['topic_start'] for chunk in result['chunks'])


def test_rank_returns_none_when_the_hook_does_not_answer() -> None:
    passages = [Passage.from_dict(passage) for passage in RANK['passages']]

    assert Reranker(lambda _: None).rank(passages, RANK['query']) is None
    assert Reranker(lambda _: {'answers': {}}).rank(passages, RANK['query']) is None


def test_rank_asks_nothing_for_no_passages_or_a_blank_query() -> None:
    fake = FakeJev([])

    assert Reranker(fake.client).rank([], 'q') is None
    assert Reranker(fake.client).rank([Passage('a', 'text')], ' \u3000 ') is None
    assert fake.requests == []
