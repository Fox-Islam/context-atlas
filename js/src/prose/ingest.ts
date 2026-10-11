import { Block } from '../block.js';
import type { Chunk } from '../chunk.js';
import { Chunker } from '../chunker.js';
import type { Config } from '../config.js';
import { Document, type Detected as DocumentDetected } from '../document/document.js';
import type { Link } from '../document/link.js';
import { Unit } from '../document/unit.js';
import { ContextAtlasError } from '../errors.js';
import { buildLexical } from '../store/lexical.js';
import { ordered } from '../support/ordered.js';
import type { Jev } from '../typesafe/jev.js';
import { length, trim } from '../text.js';
import { inferSpeakers } from './speakers.js';
import { detectProfiles, sectionDepth, sectionKey, sectionsOf } from './detector.js';
import { LinkBuilder, references } from './links.js';
import { Conversation, type IndexedBlock } from './conversation.js';
import { buildEntries } from './entries.js';
import { CONVERSATIONAL, DESCRIPTIONS, GUIDANCE, LINKS, MARKUP, profileExists, REFERENCE } from './profiles.js';
import { breadcrumbs, type Crumb } from '../chunking/breadcrumbs.js';

/**
 * Ingests prose: chunks it, gives each passage its section's profile, and builds the links those
 * profiles use. Without Jev every passage is guidance with no links.
 *
 * @throws {ContextAtlasError} on a profile that does not exist
 */
export async function ingestProse(jev: Jev | null, config: Config, given: readonly Block[], id: string, profile: string | null = null): Promise<Document> {
    checkProfile(profile);

    const blocks = profile === null || profile === CONVERSATIONAL ? inferSpeakers(given) : [...given];
    const chunked = await new Chunker(jev, config).chunk(blocks.map(foldAdmonition), profile === null || profile === CONVERSATIONAL);
    const depth = sectionDepth(chunked.chunks);
    const crumbs = breadcrumbs(blocks);
    const [bySection, detected] = await profiles(jev, chunked.chunks, profile, speaking(blocks, crumbs, depth));
    const units: Unit[] = [];
    const conversations: Link[] = [];
    const built = new Set<string>();

    for (const [i, chunk] of chunked.chunks.entries()) {
        const key = sectionKey(chunk.headingPath, depth);
        const section = bySection.get(key)!;

        if (section !== REFERENCE && section !== CONVERSATIONAL) {
            units.push(new Unit(`p${i + 1}`, 'passage', chunk.text, chunk.headingPath, { blocks: [...chunk.blocks], profile: section }));
        } else if (!built.has(key)) {
            built.add(key);
            const blocksOfSection = sectionBlocks(blocks, crumbs, key, depth);

            if (section === REFERENCE) {
                units.push(...entryUnits(blocksOfSection, units.length));
            } else {
                const conversation = await new Conversation(jev, config.charsPerToken, config.conversationReplies, config.conversationOutcomes, config.conversationCorrections).build(blocksOfSection, units.length);
                units.push(...conversation.units);
                conversations.push(...conversation.links);
            }
        }
    }

    return new Document(id, detected.profile, units, [...await links(jev, units), ...conversations], buildLexical(units), detected, {
        outline: chunked.outline.map((entry) => ({ ...entry })),
        sections: ordered(bySection),
    });
}

/** @throws {ContextAtlasError} on a profile that is not a prose profile */
export function checkProfile(profile: string | null): void {
    if (profile !== null && (!profileExists(profile) || profile === MARKUP)) {
        throw new ContextAtlasError(`Unknown prose profile "${profile}"; use one of: ${Object.keys(DESCRIPTIONS).filter((name) => name !== MARKUP).join(', ')}.`);
    }
}

/**
 * Each section's profile. A caller's profile applies to every section; otherwise Jev detects
 * them, unless every section names its speakers, and a section that names its speakers is
 * conversational whatever Jev said.
 */
async function profiles(jev: Jev | null, chunks: readonly Chunk[], profile: string | null, speakingKeys: ReadonlySet<string>): Promise<[Map<string, string>, DocumentDetected]> {
    const titles = [...sectionsOf(chunks).keys()];

    if (profile !== null) {
        return [new Map(titles.map((title) => [title, profile])), { profile, score: null, by: 'caller' }];
    }

    let sections = new Map(titles.map((title) => [title, GUIDANCE]));
    let scores = new Map<string, number>();
    const detected = jev !== null && titles.some((title) => !speakingKeys.has(title)) ? await detectProfiles(jev, chunks) : null;

    if (detected !== null) {
        sections = new Map(detected.sections);
        scores = new Map(detected.scores);
    }
    for (const key of speakingKeys) {
        if (sections.has(key)) {
            sections.set(key, CONVERSATIONAL);
            scores.delete(key);
        }
    }

    const depth = sectionDepth(chunks);
    const weights = new Map<string, number>();

    for (const chunk of chunks) {
        const section = sections.get(sectionKey(chunk.headingPath, depth))!;
        weights.set(section, (weights.get(section) ?? 0) + chunk.tokens);
    }

    const main = [...weights].sort(([, a], [, b]) => b - a)[0]?.[0] ?? '';
    const mainScores = [...scores].filter(([title]) => sections.get(title) === main).map(([, score]) => score);
    const by = main === CONVERSATIONAL ? 'speakers' : detected !== null ? 'jev' : 'default';

    return [sections, { profile: main, score: mainScores.length === 0 ? null : Math.max(...mainScores), by }];
}

/** The keys of sections whose speaker blocks hold at least half of their text (code points, trimmed), from the blocks as read. */
function speaking(blocks: readonly Block[], crumbs: readonly Crumb[], depth: number): Set<string> {
    const spoken = new Map<string, number>();
    const total = new Map<string, number>();

    blocks.forEach((block, i) => {
        if (block.isHeading()) {
            return;
        }

        const key = sectionKey(crumbs[i]!.path, depth);
        const size = length(trim(block.text));
        const speaker = block.meta['speaker'];
        total.set(key, (total.get(key) ?? 0) + size);

        if (typeof speaker === 'string' && speaker !== '') {
            spoken.set(key, (spoken.get(key) ?? 0) + size);
        }
    });

    return new Set([...spoken].filter(([key, size]) => size > 0 && 2 * size >= total.get(key)!).map(([key]) => key));
}

/**
 * A section's non-empty blocks with their heading paths and document indexes. Membership and
 * paths come from the blocks before admonitions are folded.
 */
function sectionBlocks(blocks: readonly Block[], crumbs: readonly Crumb[], key: string, depth: number): IndexedBlock[] {
    const section: IndexedBlock[] = [];

    blocks.forEach((block, i) => {
        if (sectionKey(crumbs[i]!.path, depth) === key && trim(block.text) !== '') {
            section.push({ block, path: crumbs[i]!.path, index: i });
        }
    });

    return section;
}

/** The entries of one reference section, numbered on from the units before them. */
function entryUnits(section: readonly IndexedBlock[], offset: number): Unit[] {
    return buildEntries(section).map((entry, n) => new Unit(
        `e${offset + n + 1}`,
        'entry',
        (entry.kind === 'row' || entry.path.length === 0 ? '' : `[${entry.path.join(' > ')}]\n\n`) + entry.text,
        entry.path,
        {
            name: entry.name,
            row: entry.kind === 'row',
            group: entry.group ?? null,
            fields: entry.fields.map(([field, values]) => ({ field, values })),
            blocks: entry.blocks.map((b) => section[b]!.index),
            profile: REFERENCE,
        },
    ));
}

/** A heading such as "Note" or "Warning" titles an aside, not a section: it becomes a paragraph, so the aside stays in its section. */
function foldAdmonition(block: Block): Block {
    return block.isHeading() && /^(note|tip|warning|caution|important|hint|info)[ \t\n\v\f\r]*:?$/i.test(trim(block.text))
        ? Block.paragraph(block.text, { ...block.meta })
        : block;
}

/** Each link type over the units of the profiles that build it. */
async function links(jev: Jev | null, units: readonly Unit[]): Promise<Link[]> {
    const builder = new LinkBuilder(jev);
    const byType = new Map<string, Unit[]>();

    for (const unit of units) {
        for (const type of LINKS[String(unit.meta['profile'])] ?? []) {
            byType.set(type, [...(byType.get(type) ?? []), unit]);
        }
    }

    return [
        ...(byType.has('needs') ? await builder.needs(byType.get('needs')!) : []),
        ...(byType.has('defined_by') ? await builder.definitions(byType.get('defined_by')!) : []),
        ...(byType.has('refers_to') ? references(byType.get('refers_to')!) : []),
    ];
}
