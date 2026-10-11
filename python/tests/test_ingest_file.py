from __future__ import annotations

from pathlib import Path

from context_atlas import Processor


def test_a_chat_export_saved_as_json_is_read_as_a_conversation(tmp_path: Path) -> None:
    path = tmp_path / 'export.json'
    path.write_text(
        '{"messages": [{"role": "user", "content": "Where is the staging config?"}, '
        '{"role": "assistant", "content": "In deploy/staging.yaml."}]}'
    )

    document = Processor().ingest_file(path, 'export')

    assert document.profile == 'conversational'
    assert [unit.kind for unit in document.units] == ['message', 'message']


def test_chat_lines_saved_as_jsonl_are_read_as_a_conversation(tmp_path: Path) -> None:
    path = tmp_path / 'session.jsonl'
    path.write_text(
        '{"role": "user", "content": "Run the tests"}\n{"role": "assistant", "content": "All 12 pass."}\n'
    )

    assert Processor().ingest_file(path, 'session').profile == 'conversational'


def test_json_the_chat_reader_does_not_recognise_stays_data(tmp_path: Path) -> None:
    path = tmp_path / 'config.json'
    path.write_text('{"service": "api", "replicas": 3, "data": [{"region": "eu-west-1"}]}')

    assert Processor().ingest_file(path, 'config').profile == 'markup'


def test_a_single_message_stays_data(tmp_path: Path) -> None:
    path = tmp_path / 'one.json'
    path.write_text('{"messages": [{"role": "user", "content": "Hello"}]}')

    assert Processor().ingest_file(path, 'one').profile == 'markup'
