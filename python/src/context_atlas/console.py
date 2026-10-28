"""
The command line: `chunk`, `rank`, `ingest` and `retrieve`. Each prints JSON on stdout and any
notice on stderr, so the output can be piped. spec/behaviour.md states the commands under
"Command line".
"""
from __future__ import annotations

import json
import math
import re
import sys
from collections.abc import Callable
from decimal import Decimal
from pathlib import Path
from typing import Any, TextIO

from . import __version__ as VERSION
from .chunker import Chunker
from .client import client_from_env
from .config import Config
from .errors import ContextAtlasError
from .index import FileStore
from .jev import Jev, JevRequest
from .parsing import formats, parse_blocks, parse_markdown, parse_text
from .passage import Passage
from .processor import Processor
from .reranker import Reranker

USAGE = """Usage:
  context-atlas chunk [FILE|-] [--format=markdown|text|blocks] [--no-jev] [--config=FILE] [--pretty]
  context-atlas rank QUERY [FILE|-] [--limit=N] [--config=FILE] [--pretty]
  context-atlas ingest FILE|- [--id=ID] [--format=FORMAT] [--profile=PROFILE] [--title=TITLE]
                        [--store=DIR] [--no-jev] [--config=FILE] [--pretty]
  context-atlas append FILE|- --id=ID --store=DIR [--format=FORMAT] [--profile=PROFILE]
                        [--no-jev] [--config=FILE] [--pretty]
  context-atlas retrieve QUERY --store=DIR [--documents=ID,...] [--config=FILE] [--pretty]

chunk  Splits a document into passages. Reads Markdown by default; a .txt file is
       read as plain text and a .json file as a list of blocks.
rank   Orders passages by whether they answer QUERY. FILE is a JSON list of
       {"id", "text", "heading_path"?}, or the output of `chunk`.
ingest Reads a document in any supported format (by its extension, or --format;
       stdin needs --format and --id) and prints it as a stored document, or,
       with --store, saves it in that directory and prints a summary.
append Adds a document's new text (by its extension, or --format) to the end of the
       prose document --id in --store, asking Jev only about the new blocks, and
       prints a summary.
retrieve
       Gathers the context that answers QUERY from the documents in --store,
       searched by exact terms; --documents limits it to those ids.

Jev is reached with TYPESAFE_API_KEY, or OPENROUTER_API_KEY with
TYPESAFE_PROVIDER=openrouter. Without it, `chunk` falls back to headings and
sizes, `ingest` gives every section the guidance profile, and `rank` and
`retrieve` fail.

Exit codes: 0 done, 1 bad input, 2 Jev did not answer."""

NOT_USED = (
    'Jev was not used: the document had no long sections, or Jev did not answer. '
    'The chunks follow headings and sizes only.'
)

NO_ANSWER = 'Jev did not answer. Check TYPESAFE_API_KEY.'

# The path System One is answered on by each provider. The SDK knows only TypeSafe's, so an
# OpenRouter client rewrites it in the transport; the bodies are the same on both.
_LEADING_INT = re.compile(r'[ \t\n\r\v\f]*[+-]?[0-9]+')


class Args:
    """`command positional... --flag --option=value`, in any order after the command."""

    def __init__(self, argv: list[str]) -> None:
        self.positional: list[str] = []
        self._options: dict[str, str | bool] = {}

        for arg in argv:
            if arg in ('-h', '--help'):
                self._options['help'] = True
            elif arg.startswith('--'):
                name, separator, value = arg[2:].partition('=')
                self._options[name] = value if separator else True
            else:
                self.positional.append(arg)

        self.command = None if 'help' in self._options or not self.positional else self.positional.pop(0)

    def has(self, name: str) -> bool:
        return name in self._options

    def option(self, name: str) -> str | None:
        value = self._options.get(name)

        return value if isinstance(value, str) else None


class Application:
    """`client` is the Jev to use in place of one built from the environment, for a test that calls nothing."""

    def __init__(
        self,
        client: Jev | None = None,
        stdout: TextIO | None = None,
        stderr: TextIO | None = None,
        stdin: TextIO | None = None,
    ) -> None:
        self._client = client
        self._stdout = stdout or sys.stdout
        self._stderr = stderr or sys.stderr
        self._stdin = stdin or sys.stdin

    def run(self, argv: list[str]) -> int:
        try:
            args = Args(argv)
            if args.has('version'):
                return self._write(VERSION)
            if args.command == 'chunk':
                return self._chunk(args)
            if args.command == 'rank':
                return self._rank(args)
            if args.command == 'ingest':
                return self._ingest(args)
            if args.command == 'append':
                return self._append(args)
            if args.command == 'retrieve':
                return self._retrieve(args)
            return self._usage(args.command)
        except ContextAtlasError as error:
            self._stderr.write(f'{error}\n')
            return 1

    def _chunk(self, args: Args) -> int:
        path = args.positional[0] if args.positional else '-'
        source = self._read(path)
        parsers: dict[str, Callable[[str], Any]] = {
            'markdown': parse_markdown,
            'text': parse_text,
            'blocks': parse_blocks,
        }
        parser = parsers.get(args.option('format') or _format_of(path))

        if parser is None:
            raise ContextAtlasError('--format must be markdown, text or blocks.')

        blocks = parser(source)
        jev = None if args.has('no-jev') else self._jev()
        chunked = Chunker(jev, self._config(args)).chunk(blocks)

        if jev is not None and not chunked.used_jev:
            self._stderr.write(f'{NOT_USED}\n')

        return self._json(chunked.to_dict(), args)

    def _rank(self, args: Args) -> int:
        if not args.positional:
            raise ContextAtlasError('rank needs a QUERY.')

        query = args.positional[0]
        passages = _passages(self._decode(self._read(args.positional[1] if len(args.positional) > 1 else '-')))
        limit = args.option('limit')
        reranker = Reranker(self._jev(), self._config(args))
        ranked = reranker.rank(passages, query, None if limit is None else max(1, _php_int(limit)))

        if ranked is None:
            self._stderr.write(f'{NO_ANSWER}\n')
            return 2

        by_id = {passage.id: passage.text for passage in passages}

        return self._json([{**item.to_dict(), 'text': by_id.get(item.passage.id, '')} for item in ranked], args)

    def _ingest(self, args: Args) -> int:
        if not args.positional:
            raise ContextAtlasError('ingest needs a FILE, or - with --format and --id.')
        path = args.positional[0]
        given = args.option('format')
        document_id = args.option('id')
        if document_id is None:
            if path == '-':
                raise ContextAtlasError('Reading stdin needs --id.')
            document_id = _base_name(path)
        processor = Processor(None if args.has('no-jev') else self._jev(), self._config(args))
        profile, title = args.option('profile'), args.option('title')

        if given is None and path == '-':
            raise ContextAtlasError('Reading stdin needs --format.')
        if given is None or (given in ('xlsx', 'docx') and formats.of(path) == given):
            document = processor.ingest_file(path, document_id, profile, title)
        else:
            source = self._stdin.read() if path == '-' else self._read_bytes(path)
            document = processor.ingest(source, document_id, given, profile, title)

        store = args.option('store')
        if store is None:
            return self._json(document.to_dict(), args)
        FileStore(store).put(document)
        summary = {
            'id': document.id,
            'profile': document.profile,
            'detected': document.detected,
            'units': len(document.units),
            'links': len(document.links),
            'store': store,
        }

        return self._json(summary, args)

    def _append(self, args: Args) -> int:
        document_id, store = args.option('id'), args.option('store')
        if not args.positional or document_id is None or store is None:
            raise ContextAtlasError('append needs a FILE or -, --id and --store.')
        path = args.positional[0]
        given = args.option('format') or (None if path == '-' else formats.of(path))
        if given is None:
            raise ContextAtlasError(
                'Reading stdin needs --format.' if path == '-' else f'Cannot tell the format of {path}: pass --format.'
            )
        files = FileStore(store)
        stored = files.document(document_id)
        if stored is None:
            raise ContextAtlasError(f'No document "{document_id}" in {store}.')

        new_blocks = formats.blocks(self._read(path), given)
        processor = Processor(None if args.has('no-jev') else self._jev(), self._config(args))
        document = processor.append(stored, new_blocks, args.option('profile'))
        files.put(document)
        summary = {
            'id': document.id,
            'profile': document.profile,
            'detected': document.detected,
            'units': len(document.units),
            'links': len(document.links),
            'store': store,
        }

        return self._json(summary, args)

    def _retrieve(self, args: Args) -> int:
        if not args.positional:
            raise ContextAtlasError('retrieve needs a QUERY.')
        store = args.option('store')
        if store is None:
            raise ContextAtlasError('retrieve needs --store=DIR.')
        if not Path(store).is_dir():
            raise ContextAtlasError(f'{store} is not a directory.')
        listed = args.option('documents')
        trimmed = [] if listed is None else [part.strip(' \t\n\r\x00\x0b') for part in listed.split(',')]
        ids = None if listed is None else [found for found in trimmed if found != '']

        context = Processor(self._jev(), self._config(args)).retrieve(args.positional[0], FileStore(store), None, ids)
        if context is None:
            self._stderr.write(f'{NO_ANSWER}\n')
            return 2

        return self._json(context.to_dict(), args)

    def _read_bytes(self, path: str) -> bytes:
        try:
            return Path(path).read_bytes()
        except OSError as error:
            raise ContextAtlasError(f'Cannot read {path}.') from error

    def _jev(self) -> Jev:
        return self._client if self._client is not None else _client_from_environment()

    def _config(self, args: Args) -> Config:
        path = args.option('config')

        if path is None:
            return Config()

        values = self._decode(self._read(path))

        if isinstance(values, list):
            values = {str(index): value for index, value in enumerate(values)}

        return Config.from_dict(values if isinstance(values, dict) else {})

    def _read(self, path: str) -> str:
        if path == '-':
            return self._stdin.read()

        try:
            return Path(path).read_text(encoding='utf-8')
        except (OSError, UnicodeDecodeError) as error:
            raise ContextAtlasError(f'Cannot read {path}.') from error

    @staticmethod
    def _decode(source: str) -> Any:
        try:
            return json.loads(source)
        except ValueError as error:
            raise ContextAtlasError('Not valid JSON.') from error

    def _json(self, value: Any, args: Args) -> int:
        return self._write(encode(value, args.has('pretty')))

    def _usage(self, command: str | None) -> int:
        if command is not None and command != 'help':
            self._stderr.write(f'Unknown command "{command}".\n\n{USAGE}\n')
            return 1

        return self._write(USAGE)

    def _write(self, output: str) -> int:
        self._stdout.write(f'{output}\n')

        return 0


def main(argv: list[str] | None = None) -> int:
    return Application().run(sys.argv[1:] if argv is None else argv)


def encode(value: Any, pretty: bool = False, depth: int = 0) -> str:
    """
    JSON as PHP's `json_encode` writes it with unescaped Unicode and slashes and zero fractions
    kept: floats in PHP's notation (`1.0e-5`, not `1e-05`), and U+2028 and U+2029 escaped.
    """
    if isinstance(value, dict):
        items = [(json.dumps(str(key), ensure_ascii=False), item) for key, item in value.items()]
        return _container('{', '}', [f'{key}:{" " if pretty else ""}{encode(item, pretty, depth + 1)}'
                                     for key, item in items], pretty, depth)
    if isinstance(value, (list, tuple)):
        return _container('[', ']', [encode(item, pretty, depth + 1) for item in value], pretty, depth)
    if isinstance(value, float) and math.isfinite(value):
        return _php_float(value)
    if isinstance(value, str):
        return json.dumps(value, ensure_ascii=False).replace('\u2028', '\\u2028').replace('\u2029', '\\u2029')

    return json.dumps(value)


def _container(opening: str, closing: str, members: list[str], pretty: bool, depth: int) -> str:
    if not members:
        return opening + closing
    if not pretty:
        return opening + ','.join(members) + closing

    inner = '\n' + '    ' * (depth + 1)

    return opening + inner + (',' + inner).join(members) + '\n' + '    ' * depth + closing


def _php_float(value: float) -> str:
    """The shortest round-tripping digits, in exponent form only below 1e-4 or from 1e17, like PHP."""
    sign, digits, exponent = Decimal(repr(value)).normalize().as_tuple()
    figures = ''.join(map(str, digits)) or '0'
    point = len(figures) + int(exponent)
    prefix = '-' if sign else ''

    if value == 0:
        return prefix + '0.0'
    if -4 < point <= 17:
        whole = figures[:point] if point > 0 else '0'
        fraction = figures[point:] if point > 0 else '0' * -point + figures
        return f'{prefix}{whole}{"0" * (point - len(figures))}.{fraction or "0"}'

    mantissa = figures[0] + '.' + (figures[1:] or '0')

    return f'{prefix}{mantissa}e{"+" if point > 0 else "-"}{abs(point - 1)}'


def _passages(value: Any) -> list[Passage]:
    """
    A passage list, or the `chunks` of a chunk result; an empty object is neither. A passage
    without an `id` key takes its position; one whose `id` is null gets the empty string, as in PHP.
    """
    items = value['chunks'] if isinstance(value, dict) and isinstance(value.get('chunks'), list) else value

    if not isinstance(items, list):
        raise ContextAtlasError('The passages must be a JSON list, or the output of `chunk`.')

    passages: list[Passage] = []

    for index, item in enumerate(items):
        fields = item if isinstance(item, dict) else {}
        passages.append(Passage.from_dict({'id': str(index), **fields}))

    return passages


def _base_name(path: str) -> str:
    """PHP's `basename()`: the last path segment, trailing slashes ignored."""
    return path.rstrip('/').rsplit('/', 1)[-1]


def _format_of(path: str) -> str:
    name = path.rstrip('/').rsplit('/', 1)[-1]
    extension = name.rsplit('.', 1)[1].lower() if '.' in name else ''

    return {'txt': 'text', 'text': 'text', 'json': 'blocks'}.get(extension, 'markdown')


def _php_int(value: str) -> int:
    """PHP's `(int)` of a string: its leading integer, or 0."""
    match = _LEADING_INT.match(value)

    return int(match.group(0)) if match else 0


def _client_from_environment() -> Jev:
    """
    The Python SDK refuses to build a client without a key, where the PHP SDK fails on send; both
    come out as Jev not answering, so `chunk` falls back and `rank` exits 2.
    """
    try:
        return client_from_env()
    except Exception:
        return _unanswered


def _unanswered(_: JevRequest) -> None:
    return None
