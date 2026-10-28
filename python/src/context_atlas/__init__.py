"""
Split documents into passages at headings, size limits and, using Jev, where the topic
changes; rank search hits by whether they answer a query; and ingest spreadsheets and prose into
stored documents that retrieval answers questions from.
"""
from .blocks import Block, BlockType
from .chunk import Chunk, Chunked
from .chunker import Chunker
from .client import client_from_env
from .config import Config
from .context import Context
from .document import Document, Link, Unit
from .errors import ContextAtlasError
from .index import Collection, FileStore, Source
from .jev import Jev, JevHook, JevRequest
from .parsing import parse_blocks, parse_markdown, parse_text
from .passage import Passage, RankedPassage
from .processor import Processor
from .reranker import Reranker

__version__ = '1.0.0'

__all__ = [
    'Block',
    'BlockType',
    'Chunk',
    'Chunked',
    'Chunker',
    'Collection',
    'Config',
    'Context',
    'ContextAtlasError',
    'Document',
    'FileStore',
    'Jev',
    'JevHook',
    'JevRequest',
    'Link',
    'Passage',
    'Processor',
    'RankedPassage',
    'Reranker',
    'Source',
    'Unit',
    'client_from_env',
    'parse_blocks',
    'parse_markdown',
    'parse_text',
]
