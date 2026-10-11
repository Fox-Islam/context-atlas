"""
The prose profiles: how Jev is told what each is when detecting, which links ingestion builds,
and which scopes retrieval can offer. These strings are sent to Jev word for word.
"""
from __future__ import annotations

GUIDANCE = 'guidance'

NORMATIVE = 'normative'

NARRATIVE = 'narrative'

PROCEDURAL = 'procedural'

REFERENCE = 'reference'

CONVERSATIONAL = 'conversational'

MARKUP = 'markup'

DESCRIPTIONS = {
    GUIDANCE: (
        'explains how something works or what to do, in sections that each stand on their own, such as help '
        'articles, guides and FAQs'
    ),
    NORMATIVE: (
        'sets binding rules, such as terms and conditions, licences, policies, contracts, regulations or standards, '
        'where defined terms, exceptions and cross-references apply across sections'
    ),
    NARRATIVE: (
        'tells what happened or reports findings in running prose, such as articles, histories, biographies, '
        'reports and case studies'
    ),
    PROCEDURAL: (
        'tells the reader how to do a task in steps, such as installation guides, manuals, runbooks and tutorials'
    ),
    REFERENCE: (
        'lists many entries of the same kind to look up, such as API references, glossaries, catalogues, '
        'specification tables and parts lists'
    ),
    CONVERSATIONAL: (
        'records people speaking or writing to each other in turns, such as transcripts, meeting minutes, debates, '
        'chat logs and discussion threads'
    ),
    MARKUP: (
        'structured data in YAML, JSON, JSONL, XML or TOML, such as API descriptions, configuration files '
        'and CI workflows'
    ),
}

LINKS = {
    GUIDANCE: ['needs'],
    NORMATIVE: ['needs', 'defined_by', 'refers_to'],
    NARRATIVE: ['needs'],
    PROCEDURAL: ['needs', 'refers_to'],
    REFERENCE: [],
    CONVERSATIONAL: [],
    MARKUP: [],
}

SCOPES: dict[str, dict[str, str]] = {
    GUIDANCE: {
        'one passage': 'a single fact or instruction stated in one place',
        'several places': (
            'facts from different parts of the documents put together, such as every place a requirement or role is '
            'mentioned'
        ),
    },
    NORMATIVE: {
        'one passage': 'a single fact or rule stated in one place',
        'a whole section': 'everything under one heading, such as a full list, all the steps or all the exceptions',
        'several places': 'facts from different parts of the documents put together',
    },
    NARRATIVE: {
        'one passage': 'a single fact stated in one place',
        'several places': (
            'facts from different parts of the text put together, such as every event of a kind, everything that '
            'happened in a period, or a cause and its consequence told apart'
        ),
    },
    PROCEDURAL: {
        'one passage': 'a single fact, setting, command or fix stated in one place',
        'all the steps': 'the full sequence of steps for a task, from the first to the last',
        'the next or previous step': 'what to do right after, or right before, a step the question names',
        'several places': (
            'facts from different parts of the document put together, such as a step and a requirement or warning '
            'stated elsewhere'
        ),
    },
    REFERENCE: {
        'one entry': 'what one entry says',
        'named entries': 'two or more entries the question names, for example to compare them',
        'all entries with a property': (
            'every entry that has a feature the question names, such as a parameter, a version or a value'
        ),
    },
    CONVERSATIONAL: {
        'one message': 'the answer is stated in a single message',
        'exchange': (
            'the question asks how someone responded to a message, or what a reply was answering, so it needs a '
            'message and the reply to it'
        ),
        'one person': (
            'the question asks what one particular person said, asked for or proposed, which may be spread over '
            'several of their messages'
        ),
        'outcome': 'the question asks what was decided, agreed or concluded',
        'correction': 'the question asks whether a claim or figure held up, or what the corrected or final position is',
        'overview': 'the question needs points made in many messages, by different people',
    },
    MARKUP: {},
}

# Candidates ranked in the first retrieval request.
POOL = {GUIDANCE: 9, NORMATIVE: 9, NARRATIVE: 18, PROCEDURAL: 12, REFERENCE: 9, CONVERSATIONAL: 9, MARKUP: 9}


def exists(profile: str) -> bool:
    return profile in DESCRIPTIONS


def detectable() -> dict[str, str]:
    """
    The profiles Jev chooses from when detecting. A section is conversational when its blocks
    name speakers, which only the converter knows, and markup is read from YAML or JSON text, so
    neither is offered.
    """
    return {name: text for name, text in DESCRIPTIONS.items() if name not in (CONVERSATIONAL, MARKUP)}
