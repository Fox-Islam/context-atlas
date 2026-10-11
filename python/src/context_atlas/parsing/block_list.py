"""Block lists given as JSON: the shape a PDF or HTML extractor hands over."""
from __future__ import annotations

import json

from ..blocks import Block
from ..errors import ContextAtlasError


def parse(source: str) -> list[Block]:
    """
    A JSON list of `{"type", "text", "level"?, "meta"?}`, the shape a PDF or HTML extractor hands over.

    :raises ContextAtlasError: when the JSON is invalid, not a list, or holds a malformed block
    """
    try:
        value = json.loads(source)
    except ValueError as error:
        raise ContextAtlasError('The blocks are not valid JSON.') from error

    if not isinstance(value, list):
        raise ContextAtlasError('The blocks must be a JSON list.')

    return [Block.from_dict(item) for item in value]
