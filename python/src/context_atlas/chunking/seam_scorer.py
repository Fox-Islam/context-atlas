from __future__ import annotations

from dataclasses import dataclass

from typesafe_sdk import Choice, Noul

from .. import jev, text
from ..config import Config
from ..jev import Jev, JevRequest

CONTINUES = 'p_'

REFERS_BACK = 'r_'


@dataclass(frozen=True)
class Unit:
    """One block of a section stretch (`b{index}`) or one sentence of a long paragraph (`b{index}s{sentence}`)."""

    key: str
    text: str
    tokens: int


@dataclass(frozen=True)
class Score:
    continues: float
    refers_back: float


def score(client: Jev, pairs: dict[str, tuple[str, str]], config: Config) -> dict[str, Score] | None:
    """
    Asks, for each seam, how likely the second side continues the first one's subject and how
    likely it depends on the first to be understood at all.

    The two differ where it matters: a worked example after the rule it illustrates can read
    as a change of subject while being useless alone. Only the end of the first side and the
    start of the second are sent, because a topic continues or not at the seam.

    `pairs` maps a seam key to its first and second side. Returns None, never a partial
    result, when any batch goes unanswered.
    """
    keys = list(pairs)[: max(0, config.max_pairs)]
    size = max(1, config.batch_size)
    scores: dict[str, Score] = {}

    for offset in range(0, len(keys), size):
        batch = keys[offset : offset + size]
        response = jev.send(client, _request({key: pairs[key] for key in batch}, config))

        if response is None:
            return None

        for key in batch:
            continues = jev.noul(response, CONTINUES + key)
            if continues is not None:
                scores[key] = Score(continues, jev.noul(response, REFERS_BACK + key) or 0.0)

    return scores


def _request(batch: dict[str, tuple[str, str]], config: Config) -> JevRequest:
    seams = {
        key: {
            'end_of_first': text.end(first, config.snippet_chars),
            'start_of_second': text.head(second, config.snippet_chars),
        }
        for key, (first, second) in batch.items()
    }
    questions: dict[str, Noul | Choice] = {}

    for key in seams:
        questions[CONTINUES + key] = Noul(
            instructions=(
                f'In seam {key}, is the second passage about the same specific subject as the first, '
                'rather than just the same document?'
            ),
            criteria={
                'true': 'the same specific subject: a reader looking for one would want the other',
                'false': 'a different subject, even though both belong to the same document',
            },
        )
        questions[REFERS_BACK + key] = Noul(
            instructions=f'In seam {key}, does the second passage depend on the first to be understood?',
            criteria={
                'true': (
                    'it refers back to the first, continues its list or example, or answers it, '
                    'so it makes little sense read alone'
                ),
                'false': 'it can be understood without the first',
            },
        )

    return {'state': {'seams': seams}, 'questions': questions}
