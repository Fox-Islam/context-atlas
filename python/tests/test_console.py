from __future__ import annotations

import io
import json
from pathlib import Path
from typing import Any

from support import FakeJev

from context_atlas.console import USAGE, Application, encode

ANSWERED = {
    'model': 'jev-latest',
    'answers': {
        'c_shipping-0': {'type': 'noul', 'noul': 0.1},
        'c_returns-1': {'type': 'noul', 'noul': 0.9},
        'best': {'type': 'choice', 'choice': 'returns-1', 'confidence': 0.8, 'probabilities': {}},
    },
    'usage': {'input_tokens': 10, 'output_tokens': 2},
}


def run(argv: list[str], fake: FakeJev | None = None, stdin: str = '') -> tuple[int, str, str]:
    stdout, stderr = io.StringIO(), io.StringIO()
    jev = (fake or FakeJev([])).client
    code = Application(jev, stdout, stderr, io.StringIO(stdin)).run(argv)

    return code, stdout.getvalue(), stderr.getvalue()


def test_chunk_reads_markdown_and_prints_json(tmp_path: Path) -> None:
    (tmp_path / 'doc.md').write_text('# Fees\n\nInvoices go out monthly.\n', encoding='utf-8')

    code, out, err = run(['chunk', str(tmp_path / 'doc.md'), '--no-jev'])

    assert code == 0
    assert err == ''
    assert json.loads(out)['chunks'][0]['heading_path'] == ['Fees']


def test_chunk_reads_a_json_file_as_blocks(tmp_path: Path) -> None:
    (tmp_path / 'blocks.json').write_text(
        '[{"type":"heading","text":"A","level":2},{"type":"paragraph","text":"Body."}]', encoding='utf-8',
    )

    code, out, _ = run(['chunk', str(tmp_path / 'blocks.json'), '--no-jev'])

    assert code == 0
    assert json.loads(out)['outline'][0]['level'] == 2


def test_chunk_reads_stdin_and_notes_when_jev_was_not_used() -> None:
    code, out, err = run(['chunk', '-'], stdin='Just one short paragraph.')

    assert code == 0
    assert json.loads(out)['used_jev'] is False
    assert err.startswith('Jev was not used:')


def test_chunk_prints_compact_json_unless_pretty(tmp_path: Path) -> None:
    (tmp_path / 'doc.txt').write_text('Café / déjà vu.', encoding='utf-8')

    _, compact, _ = run(['chunk', str(tmp_path / 'doc.txt'), '--no-jev'])
    _, pretty, _ = run(['chunk', str(tmp_path / 'doc.txt'), '--no-jev', '--pretty'])

    assert compact.startswith('{"chunks":[{"text":"Café / déjà vu.","blocks":[0]')
    assert compact.endswith('"used_jev":false}\n')
    assert pretty.startswith('{\n    "chunks": [\n        {\n            "text": "Café / déjà vu.",')


def test_rank_takes_the_output_of_chunk(tmp_path: Path) -> None:
    chunks = {
        'chunks': [
            {'text': 'Delivery takes three days.', 'heading_path': ['Shipping']},
            {'text': 'Returns are accepted for 30 days.', 'heading_path': ['Returns']},
        ],
    }
    (tmp_path / 'chunks.json').write_text(json.dumps(chunks), encoding='utf-8')
    fake = FakeJev([ANSWERED])

    code, out, _ = run(['rank', 'How long can I return things?', str(tmp_path / 'chunks.json'), '--limit=1'], fake)

    assert code == 0
    assert json.loads(out) == [{'id': '1', 'score': 0.9, 'text': 'Returns are accepted for 30 days.'}]
    assert list(fake.requests[0]['questions']) == ['c_shipping-0', 'c_returns-1', 'best']


def test_rank_keeps_the_ids_passages_give(tmp_path: Path) -> None:
    passages = [{'id': 'ship', 'text': 'Delivery takes three days.', 'heading_path': ['Shipping']},
                {'text': 'Returns are accepted for 30 days.', 'heading_path': ['Returns']}]
    (tmp_path / 'p.json').write_text(json.dumps(passages), encoding='utf-8')

    _, out, _ = run(['rank', 'q', str(tmp_path / 'p.json')], FakeJev([ANSWERED]))

    assert [item['id'] for item in json.loads(out)] == ['1']


def test_rank_exits_2_when_jev_does_not_answer(tmp_path: Path) -> None:
    (tmp_path / 'p.json').write_text('[{"id":"a","text":"x"}]', encoding='utf-8')

    code, out, err = run(['rank', 'q', str(tmp_path / 'p.json')], FakeJev([None]))

    assert code == 2
    assert out == ''
    assert err == 'Jev did not answer. Check TYPESAFE_API_KEY.\n'


def test_bad_input_exits_1_with_one_line(tmp_path: Path) -> None:
    (tmp_path / 'bad.json').write_text('{"not":"a list"}', encoding='utf-8')

    code, out, err = run(['chunk', str(tmp_path / 'bad.json')])

    assert code == 1
    assert out == ''
    assert err == 'The blocks must be a JSON list.\n'


def test_unreadable_file_and_unknown_config_key_exit_1(tmp_path: Path) -> None:
    (tmp_path / 'doc.md').write_text('Text.', encoding='utf-8')
    (tmp_path / 'config.json').write_text('{"max_token": 5}', encoding='utf-8')

    assert run(['chunk', str(tmp_path / 'missing.md')])[0:3:2] == (1, f'Cannot read {tmp_path / "missing.md"}.\n')
    assert run(['chunk', str(tmp_path / 'doc.md'), f'--config={tmp_path / "config.json"}'])[0:3:2] == (
        1,
        'Unknown config key "max_token".\n',
    )


def test_unknown_command_prints_the_usage_and_exits_1() -> None:
    code, out, err = run(['bogus'])

    assert code == 1
    assert out == ''
    assert err == f'Unknown command "bogus".\n\n{USAGE}\n'


def test_help_and_version() -> None:
    assert run(['--help']) == (0, f'{USAGE}\n', '')
    assert run(['help']) == (0, f'{USAGE}\n', '')
    assert run(['chunk', '--version']) == (0, '1.0.0\n', '')


def test_encode_writes_floats_and_line_separators_as_php_does() -> None:
    value: Any = [1e-05, 0.9, 1.0, 1e16, 1e17, 'a\u2028b/']

    assert encode(value) == '[1.0e-5,0.9,1.0,10000000000000000.0,1.0e+17,"a\\u2028b/"]'


def test_ingest_prints_a_stored_document(tmp_path: Path) -> None:
    (tmp_path / 'fees.md').write_text('# Fees\n\nInvoices go out monthly.\n', encoding='utf-8')

    code, out, _ = run(['ingest', str(tmp_path / 'fees.md'), '--no-jev'])
    document = json.loads(out)

    assert code == 0
    assert (document['format'], document['id'], document['profile']) == (2, 'fees.md', 'guidance')


def test_ingest_saves_into_a_store_and_retrieve_reads_it(tmp_path: Path) -> None:
    (tmp_path / 'fees.md').write_text(
        '# Fees\n\nInvoices go out monthly and are due within fourteen days.\n', encoding='utf-8'
    )
    store = str(tmp_path / 'store')

    code, out, _ = run(['ingest', str(tmp_path / 'fees.md'), '--no-jev', '--id=fees', f'--store={store}'])
    summary = json.loads(out)
    assert code == 0
    assert (summary['id'], summary['units'], summary['store']) == ('fees', 1, store)

    answers = {
        'c_p0': {'type': 'noul', 'noul': 0.9},
        'best': {'type': 'choice', 'choice': 'p0', 'confidence': 0.9, 'probabilities': {}},
        'scope': {'type': 'choice', 'choice': 'one passage', 'confidence': 0.9, 'probabilities': {}},
    }
    fake = FakeJev([{'model': 'jev-latest', 'answers': answers, 'usage': {'input_tokens': 1, 'output_tokens': 1}}])
    code, out, _ = run(['retrieve', 'When are invoices due?', f'--store={store}', '--documents=fees'], fake)
    context = json.loads(out)

    assert code == 0
    assert context['abstained'] is False
    assert context['sources'] == [{'document': 'fees', 'unit': 'p1'}]


def test_reading_stdin_needs_a_format_and_an_id() -> None:
    assert run(['ingest', '-', '--format=markdown'])[0:3:2] == (1, 'Reading stdin needs --id.\n')
    assert run(['ingest', '-', '--id=x'])[0:3:2] == (1, 'Reading stdin needs --format.\n')
    assert run(['ingest'])[0:3:2] == (1, 'ingest needs a FILE, or - with --format and --id.\n')


def test_retrieve_exits_2_when_jev_does_not_answer_and_1_without_a_store(tmp_path: Path) -> None:
    (tmp_path / 'fees.md').write_text('# Fees\n\nInvoices go out monthly.\n', encoding='utf-8')
    store = str(tmp_path / 'store')
    run(['ingest', str(tmp_path / 'fees.md'), '--no-jev', f'--store={store}'])

    code, _, err = run(['retrieve', 'invoices', f'--store={store}'], FakeJev([None]))
    assert code == 2
    assert 'Jev did not answer' in err

    code, _, err = run(['retrieve', 'invoices', f'--store={tmp_path / "missing"}'])
    assert code == 1
    assert err == f'{tmp_path / "missing"} is not a directory.\n'
    assert run(['retrieve', 'invoices'])[0:3:2] == (1, 'retrieve needs --store=DIR.\n')
