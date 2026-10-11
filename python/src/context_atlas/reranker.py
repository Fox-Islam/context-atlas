from __future__ import annotations

from collections.abc import Sequence

from typesafe_sdk import Choice, Noul, SystemOneResponse

from . import jev, text
from .config import Config
from .jev import Jev, JevRequest
from .passage import Passage, RankedPassage

PREFIX = 'c_'

BEST = 'best'

LABEL_LENGTH = 60

SUBJECT = 'the passages the search found'

INTENT = 'the question being asked'


class Reranker:
    """
    Reorders search hits by whether they answer the question asked.

    Nearest-neighbour search finds passages written like the query, which is close to but not
    the same as passages that answer it. Over-fetch from the search, pass the hits here, and
    keep the first few.

    Each passage gets one yes/no question, all in one request, and those scoring at least
    `rank_threshold` come back best first. With `cross_check`, the same request asks which
    single passage answers best and moves that pick to the front, which corrects scores that
    all hedge.
    """

    def __init__(self, jev: Jev, config: Config | None = None) -> None:
        self._jev = jev
        self._config = config or Config()

    def rank(self, passages: Sequence[Passage], query: str, limit: int | None = None) -> list[RankedPassage] | None:
        """
        Best first, at most `limit`. None when there are no passages, the query is blank, or Jev
        does not answer, never a partial list, so a caller can keep the order its search gave.
        """
        if not passages or text.trim(query) == '':
            return None

        labelled = {_label(passage, index): passage for index, passage in enumerate(passages)}
        response = jev.send(self._jev, self._request(labelled, query))

        if response is None:
            return None

        scores = self._scores(response, list(labelled))[:limit]

        return [RankedPassage(labelled[label], score) for label, score in scores]

    def _request(self, labelled: dict[str, Passage], query: str) -> JevRequest:
        questions: dict[str, Noul | Choice] = {
            PREFIX + label: Noul(
                instructions=f'Does `{label}` answer the question?',
                criteria={
                    'true': f'`{label}` states the answer, or a part of it.',
                    'false': (
                        f'`{label}` is on the same subject but does not answer the question, '
                        'or only asserts that it does.'
                    ),
                },
            )
            for label in labelled
        }

        if self._config.cross_check:
            questions[BEST] = Choice(
                instructions='Which single one answers the question most directly?',
                criteria=dict.fromkeys(labelled),
            )

        state = {
            SUBJECT: {label: text.head(item.text, self._config.passage_chars) for label, item in labelled.items()},
            INTENT: query,
        }

        return {'state': state, 'questions': questions}

    def _scores(self, response: SystemOneResponse, labels: list[str]) -> list[tuple[str, float]]:
        """Label and score pairs, best first; ties keep passage order."""
        answered = {label: score for label in labels if (score := jev.noul(response, PREFIX + label)) is not None}
        kept = [(label, score) for label, score in answered.items() if score >= self._config.rank_threshold]
        kept.sort(key=lambda item: item[1], reverse=True)
        pick = jev.choice(response, BEST)

        if pick is None or pick not in labels or (not kept and not self._config.cross_check_rescue):
            return kept

        return [(pick, answered.get(pick, 0.0)), *[item for item in kept if item[0] != pick]]


def _label(passage: Passage, index: int) -> str:
    """A name for a passage from its heading path, with its position on the end so two under one heading differ."""
    name = ' > '.join(passage.heading_path) if passage.heading_path else f'passage {index + 1}'
    found = text.slug(name, LABEL_LENGTH)

    return f'{found or "untitled"}-{index}'
