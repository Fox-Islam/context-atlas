import { choice, noul, type Questions } from '@typesafe-ai/sdk';
import type { Document } from '../document/document.js';
import { ordered } from '../support/ordered.js';
import { head, slug, tokens } from '../text.js';
import { send, type Jev } from '../typesafe/jev.js';
import { cosine, messageBody, termCounts } from './conversation.js';
import { CONVERSATIONAL, POOL, SCOPES } from './profiles.js';
import { answersQuestion, inDocumentOrder, key, unique, type Candidate, type Retrieved } from './retriever.js';

const QUESTION = 'the question being asked';

const KEEP = 0.4;

const MESSAGE_CHARS = 1600;

const CHECK_CHARS = 1200;

const SHORT_TOKENS = 60;

const NOBODY = 'nobody in particular';

/**
 * Gathers messages for a question about a conversation. One request ranks the candidates, asks
 * what shape a full answer has and whose words it is about; the shape decides which links are
 * followed:
 *
 * - every kept message shorter than 60 tokens brings the message it replies to, since "I agree"
 *   means nothing alone;
 * - every kept output (a tool result or other attachment) brings the message it is `output_of`;
 * - `exchange` adds the message replied to and the two replies closest to the question;
 * - `correction` adds what the message corrects and what corrects it;
 * - `outcome` adds what a kept message decides or is decided by, and the outcome closest to the
 *   question with what it settles;
 * - `one person` asks of up to 12 more of that speaker's messages whether they add to the answer;
 * - `overview` asks the same of the next 18 candidates.
 *
 * "Closest to the question" is term overlap with the question, so retrieval needs no vectors.
 */
export class ConversationRetriever {
    constructor(
        private readonly jev: Jev,
        private readonly charsPerToken = 4,
    ) {}

    /** `candidates` best first; null when Jev did not answer. */
    async retrieve(query: string, candidates: readonly Candidate[]): Promise<Retrieved | null> {
        const pool = candidates.slice(0, POOL[CONVERSATIONAL]);
        const labels = new Map([...pool, ...outcomesToAdd(query, pool)].map((candidate, i) => [`message-${i}`, candidate]));
        const speakers = speakersOf([...labels.values()]);
        const shown: Record<string, string> = {};
        const questions: Questions = {};

        for (const [label, candidate] of labels) {
            shown[label] = head(candidate.unit.text, MESSAGE_CHARS);
            questions[`c_${label}`] = answersQuestion(label);
        }
        questions['best'] = choice('Which single one answers the question most directly?', Object.fromEntries([...labels.keys()].map((label) => [label, null])));
        questions['scope'] = choice('What does a full answer to the question need?', { ...SCOPES[CONVERSATIONAL] });
        if (speakers.size > 0) {
            questions['speaker'] = choice('Whose words is the question about?', ordered([...speakers, [NOBODY, 'the question does not single out one person']]));
        }

        const answers = await send(this.jev, { state: { [QUESTION]: query, 'the messages the search found': shown }, questions });

        if (answers === null) {
            return null;
        }

        const scores: Record<string, number> = {};

        for (const label of labels.keys()) {
            scores[label] = answers.noul(`c_${label}`) ?? 0;
        }

        let kept = Object.entries(scores).filter(([, score]) => score >= KEEP).sort(([, a], [, b]) => b - a).map(([label]) => label);
        const best = answers.choice('best');

        if (best !== null && kept.includes(best)) {
            kept = [best, ...kept.filter((label) => label !== best)];
        }

        const picked = answers.choice('scope');
        const shape = picked !== null && Object.hasOwn(SCOPES[CONVERSATIONAL]!, picked) ? picked : 'one message';
        const named = answers.choice('speaker') ?? '';
        const speaker = speakers.has(named) ? named : NOBODY;
        const trace = { profile: CONVERSATIONAL, scope: shape, speaker, scores };

        if (kept.length === 0) {
            return { units: [], trace };
        }

        const keptUnits = kept.slice(0, shape === 'overview' ? 6 : 3).map((label) => labels.get(label)!);

        return { units: inDocumentOrder(unique(await this.expand(query, keptUnits, candidates, shape, speaker))), trace };
    }

    private async expand(query: string, kept: readonly Candidate[], candidates: readonly Candidate[], shape: string, speaker: string): Promise<Candidate[]> {
        const add: Candidate[] = [];

        for (const candidate of kept) {
            if (candidate.unit.kind === 'output') {
                add.push(...linkedFrom(candidate, 'output_of').map((owner) => closestPart(query, owner)));
                continue;
            }

            const parent = parentOf(candidate);

            if (parent !== null && (shape === 'exchange' || this.turnTokens(candidate) < SHORT_TOKENS)) {
                add.push(closestPart(query, parent));
            }
            if (shape === 'exchange') {
                add.push(...closest(query, repliesTo(candidate)).slice(0, 2).map((reply) => closestPart(query, reply)));
            } else if (shape === 'correction') {
                add.push(...linked(candidate, 'corrects'));
            } else if (shape === 'outcome') {
                add.push(...linked(candidate, 'decides'));
            }
        }

        if (shape === 'outcome') {
            add.push(...nearestOutcome(query, kept));
        }
        if (shape === 'one person' && speaker !== NOBODY) {
            add.push(...await this.check(query, closest(query, spokenBy(kept, speaker)).slice(0, 12)));
        }
        if (shape === 'overview') {
            const have = new Set(kept.map(key));
            add.push(...await this.check(query, candidates.slice(0, 18).filter((c) => !have.has(key(c)))));
        }

        return [...kept, ...add];
    }

    private turnTokens(candidate: Candidate): number {
        return turnOf(candidate).reduce((sum, c) => sum + tokens(messageBody(c.unit.text), this.charsPerToken), 0);
    }

    /** The messages Jev says add to the answer. */
    private async check(query: string, candidates: readonly Candidate[]): Promise<Candidate[]> {
        if (candidates.length === 0) {
            return [];
        }

        const labels = new Map(candidates.map((candidate, i) => [`message-${i}`, candidate]));
        const shown: Record<string, string> = {};
        const questions: Questions = {};

        for (const [label, candidate] of labels) {
            shown[label] = head(candidate.unit.text, CHECK_CHARS);
            questions[`c_${label}`] = noul(`Does \`${label}\` contain part of what the question asks for?`, {
                true: `\`${label}\` states something a full answer would include`,
                false: `\`${label}\` adds nothing the answer needs`,
            });
        }

        const answers = await send(this.jev, { state: { [QUESTION]: query, messages: shown }, questions });

        if (answers === null) {
            return [];
        }

        return [...labels].filter(([label]) => (answers.noul(`c_${label}`) ?? 0) >= 0.5).map(([, candidate]) => candidate);
    }
}

/** The two outcome parts of the pool's documents closest to the question, when the search missed them: a closing statement shares few words with "what was decided?". */
function outcomesToAdd(query: string, pool: readonly Candidate[]): Candidate[] {
    const have = new Set(pool.map(key));

    return closest(query, outcomesOf(pool)).filter((c) => !have.has(key(c))).slice(0, 2);
}

function outcomesOf(candidates: readonly Candidate[]): Candidate[] {
    return documentsOf(candidates).flatMap((document) => document.units.filter((unit) => unit.meta['outcome'] === true).map((unit) => ({ document, unit })));
}

/** The speakers of the candidates' documents by slug (`speaker` when it is empty or `0`), with the name the message header gives in full. */
function speakersOf(candidates: readonly Candidate[]): Map<string, string> {
    const speakers = new Map<string, string>();

    for (const document of documentsOf(candidates)) {
        for (const unit of document.units) {
            const speaker = unit.meta['speaker'];

            if (typeof speaker === 'string' && speaker !== '') {
                const id = speakerSlug(speaker);

                if (!speakers.has(id)) {
                    const full = /^\[(?:[^\]\n]* \| )?([^\]\n]*?)(?:, [^,\]\n]*)?\]/u.exec(unit.text)?.[1];
                    speakers.set(id, full === undefined || full === '' ? speaker : full);
                }
            }
        }
    }

    return speakers;
}

/** PHP's `Text::slug($name, 30) ?: 'speaker'`: an empty slug, or `0`, is `speaker`. */
function speakerSlug(name: string): string {
    const named = slug(name, 30);

    return named === '' || named === '0' ? 'speaker' : named;
}

/** The first part of the turn a message replies to. */
function parentOf(candidate: Candidate): Candidate | null {
    const first = turnOf(candidate)[0];

    if (first === undefined) {
        return null;
    }

    const link = candidate.document.links.find((each) => each.type === 'reply_to' && each.from === first.unit.id);

    if (link === undefined) {
        return null;
    }

    const unit = candidate.document.unit(link.to);

    return unit === null ? null : { document: candidate.document, unit };
}

/** The first parts of the turns that reply to this message's turn. */
function repliesTo(candidate: Candidate): Candidate[] {
    const first = turnOf(candidate)[0];

    if (first === undefined) {
        return [];
    }

    return candidate.document.links
        .filter((link) => link.type === 'reply_to' && link.to === first.unit.id)
        .flatMap((link) => {
            const unit = candidate.document.unit(link.from);

            return unit === null ? [] : [{ document: candidate.document, unit }];
        });
}

/** Units that the first part of this unit's turn links to with the given type. */
function linkedFrom(candidate: Candidate, type: string): Candidate[] {
    const first = turnOf(candidate)[0]?.unit.id ?? candidate.unit.id;

    return candidate.document.links.flatMap((link) => {
        const unit = link.type === type && link.from === first ? candidate.document.unit(link.to) : null;

        return unit === null ? [] : [{ document: candidate.document, unit }];
    });
}

/** Units linked to any part of this message's turn by the given type, in either direction. */
function linked(candidate: Candidate, type: string): Candidate[] {
    const parts = new Set(turnOf(candidate).map((c) => c.unit.id));

    return candidate.document.links.flatMap((link) => {
        const other = link.type !== type ? null : parts.has(link.from) ? link.to : parts.has(link.to) ? link.from : null;
        const unit = other === null ? null : candidate.document.unit(other);

        return unit === null ? [] : [{ document: candidate.document, unit }];
    });
}

/** Of the outcome messages in the kept messages' documents, the one closest to the question, with what it settles. */
function nearestOutcome(query: string, kept: readonly Candidate[]): Candidate[] {
    const top = closest(query, outcomesOf(kept))[0];

    if (top === undefined) {
        return [];
    }

    const settled = top.document.links
        .filter((link) => link.type === 'decides' && link.from === top.unit.id)
        .flatMap((link) => {
            const unit = top.document.unit(link.to);

            return unit === null ? [] : [{ document: top.document, unit }];
        });

    return [top, ...settled];
}

/** Every unit of the kept messages' documents by this speaker, except those kept. */
function spokenBy(kept: readonly Candidate[], speaker: string): Candidate[] {
    const have = new Set(kept.map(key));

    return documentsOf(kept).flatMap((document) => document.units
        .filter((unit) => {
            const name = unit.meta['speaker'];

            return typeof name === 'string' && speakerSlug(name) === speaker && !have.has(`${document.id}#${unit.id}`);
        })
        .map((unit) => ({ document, unit })));
}

/** The parts of the turn a message or output part belongs to, of its kind, in order. */
function turnOf(candidate: Candidate): Candidate[] {
    const turn = candidate.unit.meta['turn'];

    if (turn === undefined || turn === null) {
        return [];
    }

    return candidate.document.units
        .filter((unit) => unit.kind === candidate.unit.kind && unit.meta['turn'] === turn)
        .map((unit) => ({ document: candidate.document, unit }));
}

/** Of the parts of the turn this message part starts, the one closest to the question. */
function closestPart(query: string, candidate: Candidate): Candidate {
    return closest(query, turnOf(candidate))[0] ?? candidate;
}

/** By cosine of term counts with the question, closest first, ties in the order given. */
function closest(query: string, candidates: readonly Candidate[]): Candidate[] {
    const question = termCounts(query);

    return candidates
        .map((candidate) => [candidate, cosine(question, termCounts(candidate.unit.text))] as const)
        .sort(([, a], [, b]) => b - a)
        .map(([candidate]) => candidate);
}

function documentsOf(candidates: readonly Candidate[]): Document[] {
    const byId = new Map<string, Document>();

    for (const candidate of candidates) {
        if (!byId.has(candidate.document.id)) {
            byId.set(candidate.document.id, candidate.document);
        }
    }

    return [...byId.values()];
}
