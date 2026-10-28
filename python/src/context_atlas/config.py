from __future__ import annotations

import math
from dataclasses import asdict, dataclass
from typing import Any

from .errors import ContextAtlasError


@dataclass(frozen=True)
class Config:
    """
    Every tunable number. Sizes are approximate tokens of `chars_per_token` code points each.

    spec/config.schema.json describes each key and holds the same defaults and rules, which
    python/tests/test_config.py checks.
    """

    max_tokens: int = 800
    min_tokens: int = 200
    overlap_tokens: int = 80
    topic_min_tokens: int = 60
    split_long_paragraph_at: int = 900
    chars_per_token: int = 4
    seam_threshold: float = 0.5
    refers_back_weight: float = 0.4
    refers_back_protect: float = 0.5
    snippet_chars: int = 240
    batch_size: int = 40
    max_pairs: int = 2000
    rank_threshold: float = 0.4
    passage_chars: int = 2400
    cross_check: bool = True
    cross_check_rescue: bool = False
    conversation_replies: bool = True
    conversation_outcomes: bool = True
    conversation_corrections: bool = True
    nearest_reserved: int = 0

    @staticmethod
    def from_dict(values: dict[str, Any]) -> Config:
        """
        Builds a config from the snake_case keys spec/config.schema.json names, checking each
        value against the type, `minimum` and `maximum` given there. An integer key takes any
        whole number, `500.0` included, because JavaScript cannot tell it from `500`.

        :raises ContextAtlasError: on an unknown key, or a value outside its rule
        """
        checked: dict[str, int | float | bool] = {}

        for key, value in values.items():
            if key not in RULES:
                raise ContextAtlasError(f'Unknown config key "{key}".')
            checked[key] = _checked(key, value, RULES[key])

        return Config(**checked)  # type: ignore[arg-type]

    def to_dict(self) -> dict[str, int | float | bool]:
        return asdict(self)


@dataclass(frozen=True)
class _Rule:
    type: str
    minimum: float = 0
    maximum: float = math.inf


# The type and range of each key, as spec/config.schema.json gives them; python/tests/test_config.py
# checks the two agree.
RULES: dict[str, _Rule] = {
    'max_tokens': _Rule('integer', 1),
    'min_tokens': _Rule('integer', 0),
    'overlap_tokens': _Rule('integer', 0),
    'topic_min_tokens': _Rule('integer', 0),
    'split_long_paragraph_at': _Rule('integer', 1),
    'chars_per_token': _Rule('integer', 1),
    'seam_threshold': _Rule('number', 0, 1),
    'refers_back_weight': _Rule('number', 0, 1),
    'refers_back_protect': _Rule('number', 0, 1),
    'snippet_chars': _Rule('integer', 1),
    'batch_size': _Rule('integer', 1),
    'max_pairs': _Rule('integer', 0),
    'rank_threshold': _Rule('number', 0, 1),
    'passage_chars': _Rule('integer', 1),
    'cross_check': _Rule('boolean'),
    'cross_check_rescue': _Rule('boolean'),
    'conversation_replies': _Rule('boolean'),
    'conversation_outcomes': _Rule('boolean'),
    'conversation_corrections': _Rule('boolean'),
    'nearest_reserved': _Rule('integer', 0),
}


def _checked(key: str, value: Any, rule: _Rule) -> int | float | bool:
    if rule.type == 'boolean':
        if not isinstance(value, bool):
            raise ContextAtlasError(f'Config key "{key}" must be true or false.')
        return value

    integer = rule.type == 'integer'
    number = (
        isinstance(value, (int, float))
        and not isinstance(value, bool)
        and math.isfinite(value)
        and (not integer or float(value).is_integer())
    )

    if not number or value < rule.minimum or value > rule.maximum:
        expected = f'an integer at least {_bound(rule.minimum)}' if integer else (
            f'a number between {_bound(rule.minimum)} and {_bound(rule.maximum)}'
        )
        raise ContextAtlasError(f'Config key "{key}" must be {expected}.')

    return int(value) if integer else float(value)


def _bound(value: float) -> str:
    return str(int(value)) if float(value).is_integer() else str(value)
