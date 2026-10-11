from __future__ import annotations

import pytest

from context_atlas import Block, ContextAtlasError, Processor, Unit, parse_markdown
from context_atlas.prose.links import body, references


def unit(unit_id: str, text: str, path: list[str] | None = None) -> Unit:
    return Unit(unit_id, 'passage', text, path or [])


def test_body_drops_only_a_leading_heading_prefix() -> None:
    assert body('[Terms > Fees]\n\nPay monthly. [not a prefix]') == 'Pay monthly. [not a prefix]'
    assert body('No prefix [here]') == 'No prefix [here]'


def test_references_resolve_numbers_in_first_referenced_order() -> None:
    units = [
        unit('p1', '[Section 3 \u2013 Fees]\n\nFees are due monthly.', ['Section 3 \u2013 Fees']),
        unit('p2', '34.\u2014(1) A licence holder must report.'),
        unit('p3', 'See SECTIONS 34(9) and 3, and clause 3 again; section 7 does not exist.'),
        unit('p4', '3.1 Refunds follow section 3.1 and section 3.'),
    ]

    assert [(link.from_, link.to) for link in references(units)] == [('p3', 'p2'), ('p3', 'p1'), ('p4', 'p1')]


def test_without_jev_every_passage_is_guidance_and_links_need_jev() -> None:
    document = Processor().ingest_blocks(parse_markdown('# Terms\n\nSee section 2.\n\n# 2 Fees\n\nPay.'), 'doc')

    assert document.profile == 'guidance'
    assert document.detected == {'profile': 'guidance', 'score': None, 'by': 'default'}
    assert document.data['sections'] == {'Terms': 'guidance', '2 Fees': 'guidance'}
    assert document.links == []


def test_a_caller_profile_applies_to_every_section_and_parses_references_without_jev() -> None:
    blocks = parse_markdown('# Terms\n\nSee section 2.\n\n# 2 Fees\n\nPay.')
    document = Processor().ingest_blocks(blocks, 'doc', 'normative')

    assert document.detected == {'profile': 'normative', 'score': None, 'by': 'caller'}
    assert [unit.meta['profile'] for unit in document.units] == ['normative', 'normative']
    assert [link.to_dict() for link in document.links] == [{'type': 'refers_to', 'from': 'p1', 'to': 'p2'}]


def test_an_unknown_profile_is_refused() -> None:
    names = 'guidance, normative, narrative, procedural, reference, conversational'
    message = rf'Unknown prose profile "faq"; use one of: {names}\.'
    with pytest.raises(ContextAtlasError, match=message):
        Processor().ingest_blocks(parse_markdown('Text.'), 'doc', 'faq')


def test_append_refuses_an_unknown_profile_even_for_turns_that_continue_the_conversation() -> None:
    processor = Processor()
    turns = [Block.paragraph('Ship it Monday.', {'speaker': 'ann'}), Block.paragraph('Agreed.', {'speaker': 'bob'})]
    document = processor.ingest_blocks(turns, 'chat', 'conversational')

    with pytest.raises(ContextAtlasError, match=r'Unknown prose profile "faq"'):
        processor.append(document, [Block.paragraph('Tuesday instead.', {'speaker': 'ann'})], 'faq')


def test_append_refuses_a_markup_document() -> None:
    processor = Processor()
    document = processor.ingest_markup('a: 1', 'config', 'yaml')

    with pytest.raises(ContextAtlasError, match=r'^A markup document cannot be appended to; ingest it again\.$'):
        processor.append(document, [Block.paragraph('More.')])


def test_letter_sections_and_wiki_anchors_resolve() -> None:
    units = [
        unit('p1', '[Q. Indemnity]\n\nYou indemnify us.', ['Q. Indemnity']),
        unit('p2', '[Prepare]\n\nGather the tools.', ['Prepare']),
        unit('p3', 'As section Q says, and see #Prepare first, then section q.'),
    ]

    assert [(link.from_, link.to) for link in references(units)] == [('p3', 'p1'), ('p3', 'p2')]
