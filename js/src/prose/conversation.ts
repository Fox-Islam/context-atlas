import { choice, noul, type Questions, type SystemOneRequest } from '@typesafe-ai/sdk';
import { Link } from '../document/link.js';
import { Unit } from '../document/unit.js';
import { terms } from '../store/lexical.js';
import { phpString, strnatcmp } from '../support/php.js';
import { head, tokens, trim } from '../text.js';
import { send, type Jev } from '../typesafe/jev.js';
import type { SectionBlock } from './entries.js';
import { CONVERSATIONAL } from './profiles.js';

const PART_TOKENS = 400;

const REPLY_WINDOW = 6;

interface Turn {
    turn: string | null;
    speaker: string | null;
    speakerFull: string | null;
    time: string | null;
    replyTo: string | null;
    path: readonly string[];
    texts: string[];
    blocks: number[];
    attachment: boolean;
}

/** A block of a conversational section, with its index in the document's blocks. */
export interface IndexedBlock extends SectionBlock {
    index: number;
}

/**
 * Conversations: transcripts, minutes, chat logs, discussion pages. Blocks carry who spoke in
 * `meta.speaker`, and optionally `speaker_full`, `turn`, `time` and `reply_to` (the turn a
 * message answers).
 *
 * Each turn becomes message units of at most `PART_TOKENS` tokens, headed
 * `[section | speaker, time]`. Links: `reply_to` (from the source, else a Jev choice over the six
 * messages before), and, judged per message part because a long speech hides its outcome at the
 * end, `decides` (an outcome to what it settles) and `corrects` (a later message to the earlier
 * one it updates). Jev is asked only about message turns.
 *
 * A block with `meta.attachment: true` (a tool result, a file) is a turn of its own, cut by lines
 * into `output` units `a<n>`, each turn linked `output_of` the turn its `reply_to` names, else the
 * message before it.
 */
export class Conversation {
    constructor(
        private readonly jev: Jev | null,
        private readonly charsPerToken = 4,
        private readonly replyDetection = true,
        private readonly outcomeLinks = true,
        private readonly correctionLinks = true,
    ) {}

    /**
     * `prior` holds the message and output units of the conversation these blocks continue, in
     * order; the result holds the new units, and links from them.
     */
    async build(blocks: readonly IndexedBlock[], offset: number, prior: readonly Unit[] = []): Promise<{ units: Unit[]; links: Link[] }> {
        const { turns: earlierTurns, firstPart, bySource } = priorTurns(prior);
        const fresh = earlierTurns.length;
        const turns = [...earlierTurns, ...turnsOf(blocks)];
        let units: Unit[] = [];

        turns.forEach((turn, t) => {
            if (firstPart.has(t)) {
                return;
            }
            bySource.set(turn.turn ?? String(t), t);

            const attachment = turn.attachment;
            const parts = attachment ? split(turn.texts.join('\n'), this.charsPerToken) : partsOf(turn.texts, this.charsPerToken);

            parts.forEach((text, p) => {
                const id = `${attachment ? 'a' : 'm'}${offset + units.length + 1}`;

                if (!firstPart.has(t)) {
                    firstPart.set(t, id);
                }
                units.push(new Unit(id, attachment ? 'output' : 'message', `${header(turn)}${p > 0 ? ' (continued)' : ''}\n${text}`, turn.path, {
                    speaker: turn.speaker,
                    time: turn.time,
                    turn: firstPart.get(t)!,
                    blocks: [...turn.blocks],
                    profile: CONVERSATIONAL,
                    ...(attachment ? { attachment: true } : {}),
                    ...(turn.turn !== null ? { source_turn: turn.turn } : {}),
                }));
            });
        });

        const replies = await this.replies(turns, bySource, firstPart, [...prior, ...units], fresh);
        const isMessage = (unit: Unit): boolean => unit.kind === 'message';
        const earlier = prior.filter(isMessage);
        const [outcomes, decisions] = this.outcomeLinks ? await this.decisions(units.filter(isMessage), earlier) : [new Set<string>(), []];
        units = units.map((unit) => (outcomes.has(unit.id) ? new Unit(unit.id, unit.kind, unit.text, unit.path, { ...unit.meta, outcome: true }) : unit));
        const corrections = this.correctionLinks ? await this.corrections(units.filter(isMessage), earlier, replies.filter((link) => link.type === 'reply_to')) : [];

        return { units, links: [...replies, ...decisions, ...corrections] };
    }

    /** `fresh` is the number of turns at the start that already exist and get no new links. */
    private async replies(turns: readonly Turn[], bySource: ReadonlyMap<string, number>, firstPart: ReadonlyMap<number, string>, units: readonly Unit[], fresh: number): Promise<Link[]> {
        const links = new Map<number, Link>();
        const ask = new Map<number, number[]>();
        const messages: number[] = [];

        turns.forEach((turn, t) => {
            if (t < fresh) {
                if (!turn.attachment) {
                    messages.push(t);
                }

                return;
            }

            const parent = turn.replyTo !== null ? bySource.get(turn.replyTo) ?? null : null;

            if (parent !== null && parent < t) {
                links.set(t, new Link(turn.attachment ? 'output_of' : 'reply_to', firstPart.get(t)!, firstPart.get(parent)!));
            } else if (turn.attachment && messages.length > 0) {
                links.set(t, new Link('output_of', firstPart.get(t)!, firstPart.get(messages.at(-1)!)!));
            } else if (this.replyDetection && !turn.attachment && turn.replyTo === null && messages.length > 0 && turn.speaker !== null) {
                ask.set(t, messages.slice(-REPLY_WINDOW));
            }
            if (!turn.attachment) {
                messages.push(t);
            }
        });

        const byId = new Map(units.map((unit) => [unit.id, unit.text]));
        const text = new Map([...firstPart].map(([t, id]) => [t, byId.get(id) ?? '']));
        const asked = [...ask];

        for (let offset = 0; offset < asked.length && this.jev !== null; offset += 6) {
            const window = new Map<string, string>();
            const options = new Map<string, string[]>();

            for (const [t, earlier] of asked.slice(offset, offset + 6)) {
                for (const m of [...earlier, t]) {
                    window.set(`m${m}`, head(text.get(m) ?? '', 400));
                }
                options.set(`r${t}`, earlier.map((m) => `m${m}`));
            }
            const questions: Questions = {};

            for (const [key, choices] of options) {
                const self = `m${key.slice(1)}`;
                questions[key] = choice(
                    `Which earlier message is \`${self}\` a direct reply to? A reply answers, agrees or disagrees with, or takes up a point from that message. Pick \`none\` if \`${self}\` starts a new point rather than replying.`,
                    Object.fromEntries([...choices, 'none'].map((label) => [label, null])),
                );
            }

            const state = { 'messages in order': Object.fromEntries([...window].sort(([a], [b]) => strnatcmp(a, b))) };
            const answers = await send(this.jev, { state, questions });

            if (answers === null) {
                continue;
            }
            for (const [key, choices] of options) {
                const pick = answers.choice(key);

                if (pick !== null && choices.includes(pick)) {
                    const t = Number(key.slice(1));
                    links.set(t, new Link('reply_to', firstPart.get(t)!, firstPart.get(Number(pick.slice(1)))!));
                }
            }
        }

        return [...links].sort(([a], [b]) => a - b).map(([, link]) => link);
    }

    /** The outcome parts, and each outcome linked to the earlier message it settles. */
    private async decisions(units: readonly Unit[], prior: readonly Unit[]): Promise<[Set<string>, Link[]]> {
        const outcomes: Unit[] = [];

        for (let offset = 0; offset < units.length && this.jev !== null; offset += 20) {
            const batch = units.slice(offset, offset + 20);
            const questions: Questions = {};

            for (const unit of batch) {
                questions[unit.id] = noul(`Does \`${unit.id}\` record an outcome of the discussion?`, {
                    true: `\`${unit.id}\` states what was decided, agreed, accepted, rejected or concluded, such as a closing statement, a vote result or a resolution`,
                    false: `\`${unit.id}\` argues, asks, proposes or comments without recording an outcome`,
                });
            }

            const answers = await send(this.jev, {
                state: { messages: Object.fromEntries(batch.map((unit) => [unit.id, headAndEnd(unit.text, 600)])) },
                questions,
            });

            if (answers === null) {
                continue;
            }
            outcomes.push(...batch.filter((unit) => (answers.noul(unit.id) ?? 0) >= 0.5));
        }

        const links: Link[] = [];

        for (const outcome of outcomes) {
            const earlier = earlierThan([...prior, ...units], outcome);

            if (earlier.length === 0) {
                continue;
            }

            const firstOfSection = new Map<string, Unit>();

            for (const unit of earlier) {
                const section = unit.path.join(' > ');

                if (!firstOfSection.has(section)) {
                    firstOfSection.set(section, unit);
                }
            }

            const candidates = uniqueUnits([...firstOfSection.values(), ...similar(outcome, earlier, 5)]);
            const answers = this.jev === null ? null : await send(this.jev, {
                state: {
                    outcome: headAndEnd(outcome.text, 800),
                    'earlier messages': Object.fromEntries(candidates.map((unit) => [unit.id, head(unit.text, 400)])),
                } as SystemOneRequest['state'],
                questions: {
                    settles: choice(
                        'Which earlier message states the proposal, motion or question that the outcome settles? Pick `none` if none of them does.',
                        Object.fromEntries([...candidates.map((unit) => unit.id), 'none'].map((label) => [label, null])),
                    ),
                },
            });
            const pick = answers === null ? null : answers.choice('settles');

            if (pick !== null && candidates.some((unit) => unit.id === pick)) {
                links.push(new Link('decides', outcome.id, pick));
            }
        }

        return [new Set(outcomes.map((unit) => unit.id)), links];
    }

    /** Pairs of each part with its reply parent and its three most similar earlier parts; Jev keeps those where the later corrects the earlier. */
    private async corrections(units: readonly Unit[], prior: readonly Unit[], replies: readonly Link[]): Promise<Link[]> {
        const parent = new Map(replies.map((link) => [link.from, link.to]));
        const pairs: [Unit, Unit][] = [];

        for (const unit of units) {
            const earlier = earlierThan([...prior, ...units], unit);

            if (earlier.length === 0 || unit.meta['speaker'] === null || unit.meta['speaker'] === undefined) {
                continue;
            }

            const candidates = similar(unit, earlier, 3);
            const parentId = parent.get(unit.id);

            if (parentId !== undefined) {
                for (const e of earlier) {
                    if (e.id === parentId) {
                        candidates.unshift(e);
                    }
                }
            }
            for (const candidate of uniqueUnits(candidates)) {
                pairs.push([candidate, unit]);
            }
        }

        const links: Link[] = [];

        for (let offset = 0; offset < pairs.length && this.jev !== null; offset += 15) {
            const batch = pairs.slice(offset, offset + 15).map((pair, i) => [`k${offset + i}`, pair] as const);
            const questions: Questions = {};

            for (const [key] of batch) {
                questions[key] = noul(`In pair ${key}, does \`later\` correct or update something stated in \`earlier\`?`, {
                    true: '`later` says a fact, figure, claim or position in `earlier` is wrong, incomplete or out of date, gives a different figure for the same thing, or the earlier speaker takes it back',
                    false: '`later` agrees with, adds to, answers or is unrelated to `earlier` without changing what it states',
                });
            }

            const answers = await send(this.jev, {
                state: { pairs: Object.fromEntries(batch.map(([key, [earlier, later]]) => [key, { earlier: headAndEnd(earlier.text, 500), later: headAndEnd(later.text, 500) }])) },
                questions,
            });

            if (answers === null) {
                continue;
            }
            for (const [key, [earlier, later]] of batch) {
                if ((answers.noul(key) ?? 0) >= 0.5) {
                    links.push(new Link('corrects', later.id, earlier.id));
                }
            }
        }

        return links;
    }
}

/** A message's text without its `[section | speaker, time]` header line. */
export function messageBody(text: string): string {
    return text.replace(/^\[[^\]\n]*\]( \(continued\))?\n/u, '');
}

/** Exact-search term counts of a message's text without its header line. */
export function termCounts(text: string): Map<string, number> {
    const counts = new Map<string, number>();

    for (const term of terms(messageBody(text))) {
        counts.set(term, (counts.get(term) ?? 0) + 1);
    }

    return counts;
}

export function cosine(a: ReadonlyMap<string, number>, b: ReadonlyMap<string, number>): number {
    let dot = 0;

    for (const [term, count] of a) {
        dot += count * (b.get(term) ?? 0);
    }

    const squares = (counts: ReadonlyMap<string, number>): number => [...counts.values()].reduce((sum, c) => sum + c * c, 0);
    const norm = Math.sqrt(squares(a)) * Math.sqrt(squares(b));

    return norm > 0 ? dot / norm : 0;
}

/** The units most similar to this one by cosine of term counts, most similar first, ties in document order; those sharing no term are left out. */
export function similar(unit: Unit, units: readonly Unit[], limit: number): Unit[] {
    const vector = termCounts(unit.text);

    return units
        .map((other) => [other, cosine(vector, termCounts(other.text))] as const)
        .sort(([, a], [, b]) => b - a)
        .filter(([, score]) => score > 0)
        .slice(0, limit)
        .map(([other]) => other);
}

/** Consecutive blocks with the same heading path and the same turn (or, without turn numbers, the same speaker). */
function turnsOf(blocks: readonly IndexedBlock[]): Turn[] {
    const turns: Turn[] = [];

    for (const item of blocks) {
        if (item.block.isHeading()) {
            continue;
        }

        const meta = item.block.meta;
        const speaker = typeof meta['speaker'] === 'string' ? meta['speaker'] : null;
        const turn = isSet(meta['turn']) ? phpString(meta['turn']) : null;
        const attachment = meta['attachment'] === true;
        const last = turns.at(-1);
        const samePath = last !== undefined && last.path.length === item.path.length && last.path.every((title, i) => title === item.path[i]);

        if (last !== undefined && !attachment && !last.attachment && samePath && (turn !== null ? last.turn === turn : last.speaker === speaker)) {
            last.texts.push(trim(item.block.text));
            last.blocks.push(item.index);
            continue;
        }

        turns.push({
            turn,
            speaker,
            speakerFull: typeof meta['speaker_full'] === 'string' ? meta['speaker_full'] : null,
            time: isSet(meta['time']) ? phpString(meta['time']) : null,
            replyTo: isSet(meta['reply_to']) ? phpString(meta['reply_to']) : null,
            path: item.path,
            texts: [trim(item.block.text)],
            blocks: [item.index],
            attachment,
        });
    }

    return turns;
}

/** The turns of the units a conversation continues from, with their first parts and their source turn ids, so new turns can link back to them. */
function priorTurns(prior: readonly Unit[]): { turns: Turn[]; firstPart: Map<number, string>; bySource: Map<string, number> } {
    const turns: Turn[] = [];
    const firstPart = new Map<number, string>();
    const bySource = new Map<string, number>();

    for (const unit of prior) {
        if ((unit.meta['turn'] ?? unit.id) !== unit.id) {
            continue;
        }

        const t = turns.length;
        const source = isSet(unit.meta['source_turn']) ? phpString(unit.meta['source_turn']) : null;
        const speaker = unit.meta['speaker'];

        turns.push({
            turn: source,
            speaker: typeof speaker === 'string' ? speaker : null,
            speakerFull: null,
            time: null,
            replyTo: null,
            path: unit.path,
            texts: [],
            blocks: [],
            attachment: unit.kind === 'output',
        });
        firstPart.set(t, unit.id);
        bySource.set(source ?? String(t), t);
    }

    return { turns, firstPart, bySource };
}

/** An attachment's text cut into parts of at most `PART_TOKENS`: whole lines while they fit, and a line longer than that cut every `PART_TOKENS` × `charsPerToken` characters. */
function split(text: string, charsPerToken: number): string[] {
    const width = PART_TOKENS * Math.max(1, charsPerToken);
    const parts: string[] = [];
    let buffer: string[] = [];
    let size = 0;

    for (const line of text.split('\n')) {
        const points = Array.from(line);
        const pieces = points.length > width ? Array.from({ length: Math.ceil(points.length / width) }, (_, i) => points.slice(i * width, (i + 1) * width).join('')) : [line];

        for (const piece of pieces) {
            const count = tokens(piece, charsPerToken);

            if (buffer.length > 0 && size + count > PART_TOKENS) {
                parts.push(buffer.join('\n'));
                buffer = [];
                size = 0;
            }
            buffer.push(piece);
            size += count;
        }
    }
    parts.push(buffer.join('\n'));

    return parts;
}

/** PHP's `isset`: present and not null. */
function isSet(value: unknown): boolean {
    return value !== undefined && value !== null;
}

/** A turn's text in parts of at most `PART_TOKENS` tokens, split only between blocks. */
function partsOf(texts: readonly string[], charsPerToken: number): string[] {
    const parts: string[] = [];
    let buffer: string[] = [];
    let size = 0;

    for (const text of texts) {
        const count = tokens(text, charsPerToken);

        if (buffer.length > 0 && size + count > PART_TOKENS) {
            parts.push(buffer.join('\n'));
            buffer = [];
            size = 0;
        }
        buffer.push(text);
        size += count;
    }
    if (buffer.length > 0) {
        parts.push(buffer.join('\n'));
    }

    return parts;
}

/** `[section | speaker, time]`, naming the speaker in full when the source does. */
function header(turn: Turn): string {
    const who = (turn.speakerFull ?? turn.speaker ?? 'unattributed') + (turn.time !== null ? `, ${turn.time}` : '');

    return `[${turn.path.length > 0 ? `${turn.path.join(' > ')} | ` : ''}${who}]`;
}

/** A long message shown as its start and its end: outcomes and corrections often close it. */
function headAndEnd(text: string, chars: number): string {
    const points = Array.from(trim(text));
    const half = Math.floor(chars / 2);

    return points.length <= chars ? points.join('') : `${points.slice(0, half).join('')} … ${points.slice(-half).join('')}`;
}

function earlierThan(units: readonly Unit[], unit: Unit): Unit[] {
    const index = units.findIndex((other) => other.id === unit.id);

    return units.slice(0, index === -1 ? units.length : index);
}

/** Each unit once, at its first place. */
function uniqueUnits(units: readonly Unit[]): Unit[] {
    return units.filter((unit, i) => units.findIndex((other) => other.id === unit.id) === i);
}

