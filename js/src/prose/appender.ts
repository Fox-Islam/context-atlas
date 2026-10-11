import type { Block } from '../block.js';
import type { Config } from '../config.js';
import { Document } from '../document/document.js';
import { Link } from '../document/link.js';
import { Unit } from '../document/unit.js';
import { ContextAtlasError } from '../errors.js';
import { buildLexical } from '../store/lexical.js';
import { ordered } from '../support/ordered.js';
import { phpString } from '../support/php.js';
import { TABULAR } from '../tabular/profile.js';
import { length, trim } from '../text.js';
import type { Jev } from '../typesafe/jev.js';
import { Conversation, type IndexedBlock } from './conversation.js';
import { checkProfile, ingestProse } from './ingest.js';
import { CONVERSATIONAL, MARKUP } from './profiles.js';
import { inferSpeakers } from './speakers.js';

/**
 * Adds blocks to the end of a prose document without rebuilding what it holds, so a growing
 * transcript costs Jev only for its new turns.
 *
 * When the document ends in a conversation and the new blocks open with speaker blocks holding at
 * least half the text before their first heading, those blocks continue that conversation: their
 * turns may reply to, settle or correct the existing messages. Every other new block is ingested
 * as prose on its own, its units and links renumbered to follow the document's.
 *
 * @throws {ContextAtlasError} on a markup or tabular document, or a profile that does not exist
 */
export async function appendProse(jev: Jev | null, config: Config, document: Document, given: readonly Block[], profile: string | null = null): Promise<Document> {
    if (document.profile === MARKUP || document.profile === TABULAR) {
        throw new ContextAtlasError(`A ${document.profile} document cannot be appended to; ingest it again.`);
    }
    checkProfile(profile);

    let blocks = profile === null || profile === CONVERSATIONAL ? inferSpeakers(given) : [...given];
    const offset = lastNumber(document);
    let blockOffset = nextBlock(document);
    const tail = tailOf(document);
    const lead = leadOf(blocks);
    let units: Unit[] = [];
    let links: Link[] = [];

    if (tail.length > 0 && spoken(blocks.slice(0, lead))) {
        const path = tail.at(-1)!.path;
        const section: IndexedBlock[] = [];

        blocks.slice(0, lead).forEach((block, i) => {
            if (trim(block.text) !== '') {
                section.push({ block, path, index: blockOffset + i });
            }
        });

        const conversation = new Conversation(jev, config.charsPerToken, config.conversationReplies, config.conversationOutcomes, config.conversationCorrections);
        ({ units, links } = await conversation.build(section, offset, tail));
        blockOffset += lead;
        blocks = blocks.slice(lead);
    }

    let data: Record<string, unknown> = { ...document.data };

    if (blocks.length > 0) {
        const added = await ingestProse(jev, config, blocks, document.id, profile);
        const shift = offset + units.length;
        units = [...units, ...added.units.map((unit) => moved(unit, shift, blockOffset))];
        links = [...links, ...added.links.map((link) => new Link(link.type, renumber(link.from, shift), renumber(link.to, shift)))];
        data = mergeData(data, added.data, blockOffset);
    }

    const all = [...document.units, ...units];

    return new Document(document.id, document.profile, all, [...document.links, ...links], buildLexical(all), document.detected, data);
}

/**
 * The message and output units of the conversation the document ends in: those after the last
 * unit of another kind or path, or none when the last unit is not a message or output.
 */
function tailOf(document: Document): Unit[] {
    const tail: Unit[] = [];

    for (let i = document.units.length - 1; i >= 0; i--) {
        const unit = document.units[i]!;
        const last = tail[0] ?? unit;

        if ((unit.kind !== 'message' && unit.kind !== 'output') || unit.path.length !== last.path.length || unit.path.some((title, n) => title !== last.path[n])) {
            break;
        }
        tail.unshift(unit);
    }

    return tail;
}

/** The number of blocks before the first heading. */
function leadOf(blocks: readonly Block[]): number {
    const index = blocks.findIndex((block) => block.isHeading());

    return index === -1 ? blocks.length : index;
}

/** Whether blocks naming a speaker hold at least half the text. */
function spoken(blocks: readonly Block[]): boolean {
    let total = 0;
    let said = 0;

    for (const block of blocks) {
        const size = length(trim(block.text));
        const speaker = block.meta['speaker'];

        total += size;
        if (typeof speaker === 'string' && speaker !== '') {
            said += size;
        }
    }

    return said > 0 && 2 * said >= total;
}

function lastNumber(document: Document): number {
    let last = 0;

    for (const unit of document.units) {
        const match = /(\d+)$/.exec(unit.id);

        if (match !== null) {
            last = Math.max(last, Number(match[1]));
        }
    }

    return last;
}

function nextBlock(document: Document): number {
    let next = 0;

    for (const unit of document.units) {
        for (const block of listOf(unit.meta['blocks'])) {
            next = Math.max(next, Math.trunc(Number(block)) + 1);
        }
    }
    for (const heading of listOf(document.data['outline'])) {
        const block = (heading as Record<string, unknown> | null)?.['block'];
        next = Math.max(next, (block === undefined || block === null ? -1 : Math.trunc(Number(block))) + 1);
    }

    return next;
}

function moved(unit: Unit, shift: number, blockOffset: number): Unit {
    const meta: Record<string, unknown> = { ...unit.meta };

    if (meta['turn'] !== undefined && meta['turn'] !== null) {
        meta['turn'] = renumber(phpString(meta['turn']), shift);
    }
    if (meta['blocks'] !== undefined && meta['blocks'] !== null) {
        meta['blocks'] = (meta['blocks'] as number[]).map((block) => block + blockOffset);
    }

    return new Unit(renumber(unit.id, shift), unit.kind, unit.text, unit.path, meta);
}

function renumber(id: string, shift: number): string {
    return id.replace(/^([a-z]+)(\d+)$/, (_, prefix: string, number: string) => `${prefix}${Number(number) + shift}`);
}

/** The added outline entries, their `block` first and shifted, after the document's; the added sections after its sections. */
function mergeData(data: Record<string, unknown>, added: Readonly<Record<string, unknown>>, blockOffset: number): Record<string, unknown> {
    const outline = listOf(added['outline']).map((heading) => {
        const entry = heading as Record<string, unknown>;
        const block = Math.trunc(Number(entry['block'])) + blockOffset;

        return Object.assign({ block }, entry, { block });
    });

    return {
        ...data,
        outline: [...listOf(data['outline']), ...outline],
        sections: ordered([...entriesOf(data['sections']), ...entriesOf(added['sections'])]),
    };
}

function listOf(value: unknown): unknown[] {
    if (Array.isArray(value)) {
        return value;
    }

    return typeof value === 'object' && value !== null ? Object.values(value) : [];
}

function entriesOf(value: unknown): [string, unknown][] {
    return typeof value === 'object' && value !== null && !Array.isArray(value) ? Object.entries(value) : [];
}
