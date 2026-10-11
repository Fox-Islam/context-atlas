from __future__ import annotations

import json
import math
from dataclasses import fields
from pathlib import Path

import pytest

from context_atlas import Config, ContextAtlasError
from context_atlas.config import RULES

SCHEMA = json.loads((Path(__file__).resolve().parents[2] / 'spec' / 'config.schema.json').read_text(encoding='utf-8'))


def test_defaults_are_the_schema_defaults() -> None:
    defaults = {key: spec['default'] for key, spec in SCHEMA['properties'].items()}

    assert Config().to_dict() == defaults
    assert [item.name for item in fields(Config)] == list(defaults)


def test_rules_are_the_schema_rules() -> None:
    for key, spec in SCHEMA['properties'].items():
        rule = RULES[key]
        assert (rule.type, rule.minimum) == (spec['type'], spec.get('minimum', 0))
        assert rule.maximum == spec.get('maximum', math.inf)
    assert list(RULES) == list(SCHEMA['properties'])


def test_from_dict_sets_the_keys_given() -> None:
    config = Config.from_dict({'max_tokens': 100, 'seam_threshold': 1, 'cross_check': False})

    assert (config.max_tokens, config.seam_threshold, config.cross_check) == (100, 1.0, False)
    assert config.min_tokens == 200


def test_unknown_key_is_refused() -> None:
    with pytest.raises(ContextAtlasError, match='Unknown config key "maxTokens"'):
        Config.from_dict({'maxTokens': 100})


@pytest.mark.parametrize(
    ('key', 'value'),
    [('max_tokens', 1.5), ('max_tokens', True), ('max_tokens', 0), ('cross_check', 1), ('seam_threshold', '0.5')],
)
def test_wrong_type_is_refused(key: str, value: object) -> None:
    with pytest.raises(ContextAtlasError):
        Config.from_dict({key: value})
