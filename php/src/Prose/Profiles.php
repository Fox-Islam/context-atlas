<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Prose;

/**
 * The prose profiles: how Jev is told what each is when detecting, which links ingestion builds,
 * and which scopes retrieval can choose.
 */
final class Profiles
{
    public const string GUIDANCE = 'guidance';

    public const string NORMATIVE = 'normative';

    public const string NARRATIVE = 'narrative';

    public const string PROCEDURAL = 'procedural';

    public const string REFERENCE = 'reference';

    public const string CONVERSATIONAL = 'conversational';

    public const string MARKUP = 'markup';

    public const array DESCRIPTIONS = [
        self::GUIDANCE => 'explains how something works or what to do, in sections that each stand on their own, such as help articles, guides and FAQs',
        self::NORMATIVE => 'sets binding rules, such as terms and conditions, licences, policies, contracts, regulations or standards, where defined terms, exceptions and cross-references apply across sections',
        self::NARRATIVE => 'tells what happened or reports findings in running prose, such as articles, histories, biographies, reports and case studies',
        self::PROCEDURAL => 'tells the reader how to do a task in steps, such as installation guides, manuals, runbooks and tutorials',
        self::REFERENCE => 'lists many entries of the same kind to look up, such as API references, glossaries, catalogues, specification tables and parts lists',
        self::CONVERSATIONAL => 'records people speaking or writing to each other in turns, such as transcripts, meeting minutes, debates, chat logs and discussion threads',
        self::MARKUP => 'structured data in YAML, JSON, JSONL, XML or TOML, such as API descriptions, configuration files and CI workflows',
    ];

    /** @var array<string, list<string>> link types `Links` builds for each profile; a conversation builds its own in `Conversation` */
    public const array LINKS = [
        self::GUIDANCE => ['needs'],
        self::NORMATIVE => ['needs', 'defined_by', 'refers_to'],
        self::NARRATIVE => ['needs'],
        self::PROCEDURAL => ['needs', 'refers_to'],
        self::REFERENCE => [],
        self::CONVERSATIONAL => [],
        self::MARKUP => [],
    ];

    /** @var array<string, array<string, string>> scope options retrieval offers Jev */
    public const array SCOPES = [
        self::GUIDANCE => [
            'one passage' => 'a single fact or instruction stated in one place',
            'several places' => 'facts from different parts of the documents put together, such as every place a requirement or role is mentioned',
        ],
        self::NORMATIVE => [
            'one passage' => 'a single fact or rule stated in one place',
            'a whole section' => 'everything under one heading, such as a full list, all the steps or all the exceptions',
            'several places' => 'facts from different parts of the documents put together',
        ],
        self::NARRATIVE => [
            'one passage' => 'a single fact stated in one place',
            'several places' => 'facts from different parts of the text put together, such as every event of a kind, everything that happened in a period, or a cause and its consequence told apart',
        ],
        self::PROCEDURAL => [
            'one passage' => 'a single fact, setting, command or fix stated in one place',
            'all the steps' => 'the full sequence of steps for a task, from the first to the last',
            'the next or previous step' => 'what to do right after, or right before, a step the question names',
            'several places' => 'facts from different parts of the document put together, such as a step and a requirement or warning stated elsewhere',
        ],
        self::REFERENCE => [
            'one entry' => 'what one entry says',
            'named entries' => 'two or more entries the question names, for example to compare them',
            'all entries with a property' => 'every entry that has a feature the question names, such as a parameter, a version or a value',
        ],
        self::CONVERSATIONAL => [
            'one message' => 'the answer is stated in a single message',
            'exchange' => 'the question asks how someone responded to a message, or what a reply was answering, so it needs a message and the reply to it',
            'one person' => 'the question asks what one particular person said, asked for or proposed, which may be spread over several of their messages',
            'outcome' => 'the question asks what was decided, agreed or concluded',
            'correction' => 'the question asks whether a claim or figure held up, or what the corrected or final position is',
            'overview' => 'the question needs points made in many messages, by different people',
        ],
        self::MARKUP => [],
    ];

    /** @var array<string, int> candidates ranked in the first request */
    public const array POOL = [
        self::GUIDANCE => 9,
        self::NORMATIVE => 9,
        self::NARRATIVE => 18,
        self::PROCEDURAL => 12,
        self::REFERENCE => 9,
        self::CONVERSATIONAL => 9,
        self::MARKUP => 9,
    ];

    /**
     * The profiles Jev chooses from when detecting. A section is conversational when its blocks
     * name speakers, which only the converter knows, and markup is read from a data format's
     * text, not blocks, so neither is offered.
     *
     * @return array<string, string>
     */
    public static function detectable(): array
    {
        return array_diff_key(self::DESCRIPTIONS, [self::CONVERSATIONAL => true, self::MARKUP => true]);
    }

    public static function exists(string $profile): bool
    {
        return isset(self::DESCRIPTIONS[$profile]);
    }
}
