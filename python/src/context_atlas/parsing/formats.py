"""
The input formats and where each goes: document formats are read into blocks for the prose
profiles, data formats into a key tree for the markup profile, spreadsheets into tables.
"""
from __future__ import annotations

import importlib
from pathlib import PurePath

from ..blocks import Block
from ..errors import ContextAtlasError

DOCUMENT = [
    'markdown', 'mdx', 'text', 'html', 'rst', 'asciidoc', 'org', 'latex', 'ipynb', 'vtt', 'srt', 'chat', 'docx',
    'blocks',
]

DATA = ['yaml', 'json', 'jsonl', 'xml', 'toml']

SPREADSHEET = ['csv', 'xlsx']

EXTENSIONS = {
    'md': 'markdown',
    'markdown': 'markdown',
    'mdx': 'mdx',
    'txt': 'text',
    'text': 'text',
    'html': 'html',
    'htm': 'html',
    'xhtml': 'html',
    'rst': 'rst',
    'adoc': 'asciidoc',
    'asciidoc': 'asciidoc',
    'org': 'org',
    'tex': 'latex',
    'ipynb': 'ipynb',
    'vtt': 'vtt',
    'srt': 'srt',
    'docx': 'docx',
    'yaml': 'yaml',
    'yml': 'yaml',
    'json': 'json',
    'jsonl': 'jsonl',
    'ndjson': 'jsonl',
    'xml': 'xml',
    'toml': 'toml',
    'csv': 'csv',
    'xlsx': 'xlsx',
}

# The module in this package that reads each document format; each has `parse(text) -> list[Block]`.
_READERS = {
    'markdown': 'markdown',
    'mdx': 'mdx',
    'text': 'text',
    'html': 'html',
    'rst': 'rst',
    'asciidoc': 'asciidoc',
    'org': 'org',
    'latex': 'latex',
    'ipynb': 'notebook',
    'vtt': 'vtt',
    'srt': 'srt',
    'chat': 'chat',
    'blocks': 'block_list',
}


def of(path: str | PurePath) -> str | None:
    """The format a file's extension names, or None."""
    return EXTENSIONS.get(_extension(str(path)).lower())


def blocks(source: str | bytes, document_format: str) -> list[Block]:
    """
    A document format read into blocks. DOCX is binary: it is read from bytes, and text is the
    error PHP gives.

    :raises ContextAtlasError: for a format that is not a document format, or malformed input
    """
    if document_format == 'docx':
        if not isinstance(source, bytes):
            raise ContextAtlasError('DOCX is read from a file: use ingestFile.')
        return importlib.import_module(f'{__package__}.docx').parse(source)
    reader = _READERS.get(document_format)
    if reader is None:
        names = ', '.join(DOCUMENT)
        raise ContextAtlasError(f'"{document_format}" is not a document format; use one of: {names}.')

    return importlib.import_module(f'{__package__}.{reader}').parse(source)


def _extension(path: str) -> str:
    """PHP's `pathinfo($path, PATHINFO_EXTENSION)`: the text after the last `.` of the base name."""
    name = path.rstrip('/').rsplit('/', 1)[-1]

    return name.rsplit('.', 1)[1] if '.' in name else ''
