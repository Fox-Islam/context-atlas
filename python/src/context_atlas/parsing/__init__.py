"""Readers from each document format into blocks, and the format list and dispatcher in `formats`."""
from .block_list import parse as parse_blocks
from .formats import blocks
from .markdown import parse as parse_markdown
from .text import parse as parse_text

__all__ = ['blocks', 'parse_blocks', 'parse_markdown', 'parse_text']
