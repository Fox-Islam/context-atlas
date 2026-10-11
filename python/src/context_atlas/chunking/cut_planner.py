from __future__ import annotations

import math

from ..config import Config
from .seam_scorer import Score, Unit


def cuts(units: list[Unit], scores: dict[str, Score], config: Config) -> list[str]:
    """
    The keys of the units that open a new piece of a stretch, in order.

    Every seam costs how strongly its two sides belong together, less `seam_threshold`, so a
    seam between two subjects has a negative cost and cutting there lowers the total. The
    cheapest set of cuts leaving no piece below `topic_min_tokens` wins, chosen over the whole
    stretch at once; leaving the stretch whole is always allowed.
    """
    n = len(units)
    costs = [0.0 if index == 0 else _cut_cost(scores.get(unit.key), config) for index, unit in enumerate(units)]
    best = [0.0] + [math.inf] * n
    origin: dict[int, int] = {}

    for finish in range(1, n + 1):
        size = 0
        for start in range(finish - 1, -1, -1):
            size += units[start].tokens
            whole = start == 0 and finish == n
            piece = math.inf if size < config.topic_min_tokens and not whole else costs[start]
            total = best[start] + piece
            if total < best[finish]:
                best[finish] = total
                origin[finish] = start

    found: list[str] = []
    finish = n
    while finish > 0 and finish in origin:
        if origin[finish] > 0:
            found.append(units[origin[finish]].key)
        finish = origin[finish]

    return found[::-1]


def _cut_cost(score: Score | None, config: Config) -> float:
    """Infinite for a seam with no score or whose second side depends on the first: it is never cut."""
    if score is None or score.refers_back >= config.refers_back_protect:
        return math.inf

    weight = config.refers_back_weight
    together = (1 - weight) * score.continues + weight * score.refers_back

    return together - config.seam_threshold
