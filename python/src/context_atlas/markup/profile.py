from __future__ import annotations

from ..document import Document
from ..errors import ContextAtlasError
from ..index import build as lexical
from . import json_outline, jsonl_outline, subtrees, toml_outline, xml_outline, yaml_outline


def ingest(source: str, document_id: str, markup_format: str, title: str, chars_per_token: int = 4) -> Document:
    """
    A YAML, JSON, JSON Lines, XML or TOML file as path subtrees linked by `$ref`, aliases and XML
    id references. No Jev request is made.

    :raises ContextAtlasError: for another format, or invalid JSON, JSON Lines or XML
    """
    readers = {
        'yaml': yaml_outline.read,
        'json': json_outline.read,
        'jsonl': jsonl_outline.read,
        'xml': xml_outline.read,
        'toml': toml_outline.read,
    }
    if markup_format not in readers:
        raise ContextAtlasError(f'Cannot read "{markup_format}" as markup; use yaml, json, jsonl, xml or toml.')
    outline = readers[markup_format](source)
    cut = subtrees.build(outline, title, chars_per_token)

    return Document(
        document_id,
        subtrees.MARKUP,
        cut['units'],
        cut['links'],
        lexical(cut['units']),
        {'profile': subtrees.MARKUP, 'score': None, 'by': 'format'},
        {'format': markup_format},
    )
