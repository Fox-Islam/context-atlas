import { choice, noul, type ChoiceCriteria, type Questions } from '@typesafe-ai/sdk';
import type { Document } from '../document/document.js';
import type { Unit } from '../document/unit.js';
import { Config } from '../config.js';
import { byteCompare, phpArray, phpString } from '../support/php.js';
import { head, length, slug, tokens, trim } from '../text.js';
import { send, type Jev } from '../typesafe/jev.js';
import { body } from './links.js';
import { ConversationRetriever } from './conversationRetriever.js';
import { CONVERSATIONAL, GUIDANCE, MARKUP, NARRATIVE, NORMATIVE, POOL, PROCEDURAL, profileExists, REFERENCE, SCOPES } from './profiles.js';

const QUESTION = 'the question being asked';

const KEEP = 0.4;

const KEEP_SEVERAL = 0.5;

const MIN_STEP_TOKENS = 25;

const PASSAGE_CHARS = 2400;

export interface Candidate {
    document: Document;
    unit: Unit;
}

export interface Retrieved {
    units: Candidate[];
    trace: Record<string, unknown>;
}

interface Ranked {
    kept: Candidate[];
    keptLabels: string[];
    scope: string;
    scores: Record<string, number>;
    labels: Map<string, Candidate>;
    anchor: Candidate | null;
    direction: 'after' | 'before';
}

/**
 * Gathers passages for a question: one request ranks the candidates and, for profiles with
 * scopes, asks how much the question needs; then the links of the kept passages are followed,
 * each type with its own question. A definition gets a question of its own plus a pick of the
 * one the answer turns on: asked whether a definition is needed, Jev scores the right one about
 * 0.3.
 */
export class ProseRetriever {
    /** Narrative passages are read whole: the larger of 2400 characters and four per token of `maxTokens`. */
    private readonly wholeChunk: number;

    private passageChars = PASSAGE_CHARS;

    private readonly charsPerToken: number;

    constructor(
        private readonly jev: Jev,
        config: Config = new Config(),
    ) {
        this.wholeChunk = Math.max(PASSAGE_CHARS, 4 * config.maxTokens);
        this.charsPerToken = config.charsPerToken;
    }

    /** `candidates` best first; null when Jev did not answer. */
    async retrieve(query: string, found: readonly Candidate[]): Promise<Retrieved | null> {
        const candidates = diverse(found);
        const profiles = profilesOf(candidates);

        if (profiles.length === 1) {
            return this.retrieveAs(profiles[0]!, query, candidates);
        }

        const units: Candidate[] = [];
        const traces: Record<string, unknown> = {};

        for (const profile of profiles) {
            const result = await this.retrieveAs(profile, query, candidates.filter((candidate) => profileOfUnit(candidate) === profile));

            if (result !== null) {
                units.push(...result.units);
                traces[profile] = result.trace;
            }
        }

        return Object.keys(traces).length === 0 ? null : { units: inDocumentOrder(unique(units)), trace: { profiles: traces } };
    }

    /** Gathers as one profile, from candidates best first; null when Jev did not answer. */
    private async retrieveAs(profile: string, query: string, candidates: readonly Candidate[]): Promise<Retrieved | null> {
        if (profile === REFERENCE) {
            return this.reference(query, candidates);
        }
        if (profile === CONVERSATIONAL) {
            return new ConversationRetriever(this.jev, this.charsPerToken).retrieve(query, candidates);
        }

        const size = POOL[profile] ?? 9;
        this.passageChars = profile === NARRATIVE ? this.wholeChunk : PASSAGE_CHARS;
        const ranked = await this.rank(query, candidates.slice(0, size), SCOPES[profile] ?? {}, profile === PROCEDURAL);

        if (ranked === null) {
            return null;
        }
        if (ranked.kept.length === 0) {
            return { units: [], trace: { profile, scores: ranked.scores } };
        }

        let gathered = profile === NARRATIVE ? narrative(ranked) : profile === PROCEDURAL ? await this.procedural(query, ranked) : sections(ranked);

        if (ranked.scope === 'several places' && (profile === NORMATIVE || profile === GUIDANCE) && await this.complete(query, gathered) === false) {
            gathered = [...gathered, ...await this.more(query, candidates.slice(size, size * 2))];
        }

        gathered = await this.expand(query, unique(gathered));

        if (profile === MARKUP) {
            gathered = await this.references(query, gathered);
        }

        return { units: inDocumentOrder(gathered), trace: { profile, scope: ranked.scope, scores: ranked.scores } };
    }

    /**
     * Reference entries: the named entries the question mentions join the ranking, then the
     * shape decides between one entry, the named entries Jev confirms, and every entry with a
     * field value the question names.
     */
    private async reference(query: string, candidates: readonly Candidate[]): Promise<Retrieved | null> {
        const pool = candidates.slice(0, POOL[REFERENCE]);
        const named = namedEntries(query, candidates);
        const namedKeys = new Set(named.map(key));
        const poolKeys = new Set(pool.map(key));
        const labels = new Map<string, Candidate>();
        const isNamed = new Map<string, boolean>();

        [...pool, ...named.filter((c) => !poolKeys.has(key(c)))].forEach((candidate, i) => {
            const label = entryLabel(candidate, i);
            labels.set(label, candidate);
            isNamed.set(label, namedKeys.has(key(candidate)));
        });

        const shown: Record<string, string> = {};
        const questions: Questions = {};

        for (const [label, candidate] of labels) {
            shown[label] = head(candidate.unit.text, PASSAGE_CHARS);
            questions[`c_${label}`] = answersQuestion(label);
            if (isNamed.get(label) === true) {
                questions[`n_${label}`] = noul(`Is \`${label}\` one of the entries the question names or asks about?`, {
                    true: `the question refers to the entry \`${label}\` describes, by its name or unmistakably`,
                    false: 'the question is about a different entry that merely has a similar name',
                });
            }
        }
        questions['best'] = choice('Which single one answers the question most directly?', Object.fromEntries([...labels.keys()].map((label) => [label, null])));
        questions['scope'] = choice('What does a full answer to the question need?', { ...SCOPES[REFERENCE] });

        const answers = await send(this.jev, { state: { [QUESTION]: query, 'the entries the search found': shown }, questions });

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
        const shape = picked !== null && Object.hasOwn(SCOPES[REFERENCE]!, picked) ? picked : 'one entry';
        const keptUnits = kept.map((label) => labels.get(label)!);
        const trace: Record<string, unknown> = { profile: REFERENCE, scope: shape, scores };
        let gathered: Candidate[];

        if (shape === 'named entries') {
            gathered = [
                ...[...isNamed].filter(([label, isIt]) => isIt && (answers.noul(`n_${label}`) ?? 0) >= 0.5).map(([label]) => labels.get(label)!),
                ...keptUnits.slice(0, 3),
            ];
        } else if (shape === 'all entries with a property') {
            gathered = await this.entriesWithProperty(query, candidates, trace);

            if (gathered.length === 0) {
                gathered = keptUnits.slice(0, 3);
            }
        } else {
            gathered = keptUnits.slice(0, 3);
        }

        return { units: inDocumentOrder(unique(gathered)), trace };
    }

    /**
     * Every entry holding a field value the question names, picked by Jev from the values found;
     * without one, the candidates Jev says are entries the question asks for. A picked filter is
     * added to `trace`.
     */
    private async entriesWithProperty(query: string, candidates: readonly Candidate[], trace: Record<string, unknown>): Promise<Candidate[]> {
        const question = query.replaceAll('∶', ':').toLowerCase();
        const options = new Map<string, { field: string; group: string; value: string; entries: Candidate[] }>();

        for (const document of documentsOf(candidates)) {
            for (const unit of document.units) {
                for (const [field, values] of fieldEntries(unit.meta['fields'])) {
                    for (const raw of values) {
                        const value = trim(raw);
                        const needle = value.replaceAll('∶', ':').toLowerCase();

                        if (length(needle) < 2 || !new RegExp(`(?<![\\p{L}\\p{N}_])${escapeRegExp(needle)}(?![\\p{L}\\p{N}_])`, 'u').test(question)) {
                            continue;
                        }

                        const group = unit.meta['row'] === true ? phpString(unit.meta['group'] ?? '') : '';
                        const optionKey = `${field}\0${group}\0${value}`;
                        const option = options.get(optionKey) ?? { field, group, value, entries: [] };
                        option.entries.push({ document, unit });
                        options.set(optionKey, option);
                    }
                }
            }
        }

        if (options.size > 0) {
            const chosen = await this.pickFilter(query, [...options.values()]);

            if (chosen !== null) {
                trace['filter'] = { field: chosen.field, group: chosen.group, value: chosen.value };

                return unique(chosen.entries).slice(0, 30);
            }
        }

        return this.entriesMeant(query, candidates.slice(0, 24));
    }

    private async pickFilter<T extends { field: string; group: string; value: string; entries: Candidate[] }>(query: string, options: readonly T[]): Promise<T | null> {
        const criteria: ChoiceCriteria = {};

        options.forEach((option, i) => {
            const count = unique(option.entries).length;
            criteria[`f${i}`] = option.group === ''
                ? `entries whose \`${option.field}\` is ${option.value} (${count} entries)`
                : `rows of \`${option.group}\` whose \`${option.field}\` is ${option.value} (${count} entries)`;
        });
        criteria['none'] = 'none of these selects the entries the question asks for';

        const answers = await send(this.jev, {
            state: { [QUESTION]: query },
            questions: { filter: choice('Which filter selects exactly the entries the question asks for? Pick `none` if none of them does.', criteria) },
        });
        const pick = answers === null ? null : answers.choice('filter');

        return pick !== null && /^f\d+$/.test(pick) && Number(pick.slice(1)) < options.length ? options[Number(pick.slice(1))]! : null;
    }

    /** The candidates, in batches of 12, that Jev says are entries the question asks for. */
    private async entriesMeant(query: string, candidates: readonly Candidate[]): Promise<Candidate[]> {
        const out: Candidate[] = [];

        for (let offset = 0; offset < candidates.length; offset += 12) {
            const labels = new Map(candidates.slice(offset, offset + 12).map((candidate, i) => [entryLabel(candidate, i), candidate]));
            const shown: Record<string, string> = {};
            const questions: Questions = {};

            for (const [label, candidate] of labels) {
                shown[label] = head(candidate.unit.text, PASSAGE_CHARS);
                questions[`c_${label}`] = noul(`Is \`${label}\` one of the entries the question asks for?`, {
                    true: `\`${label}\` has what the question asks about`,
                    false: `\`${label}\` lacks it, or is not the kind of entry the question asks about`,
                });
            }

            const answers = await send(this.jev, { state: { [QUESTION]: query, 'the entries': shown }, questions });

            if (answers === null) {
                continue;
            }
            for (const [label, candidate] of labels) {
                if ((answers.noul(`c_${label}`) ?? 0) >= 0.5) {
                    out.push(candidate);
                }
            }
        }

        return out;
    }

    /**
     * Follows `ref` links from the gathered markup units in both directions, two hops at most;
     * each hop asks of up to 25 linked units whether the answer needs them.
     */
    private async references(query: string, gathered: Candidate[]): Promise<Candidate[]> {
        let frontier = gathered;

        for (let hop = 0; hop < 2 && frontier.length > 0; hop++) {
            const have = new Set(gathered.map(key));
            const targets = new Map<string, { target: Candidate; from: Unit; uses: boolean }>();

            for (const candidate of frontier) {
                for (const link of candidate.document.links) {
                    const other = link.type !== 'ref' ? null : link.from === candidate.unit.id ? link.to : link.to === candidate.unit.id ? link.from : null;
                    const unit = other === null ? null : candidate.document.unit(other);

                    if (unit === null) {
                        continue;
                    }

                    const target = { document: candidate.document, unit };

                    if (!have.has(key(target)) && !targets.has(key(target))) {
                        targets.set(key(target), { target, from: candidate.unit, uses: other === link.from });
                    }
                }
            }
            frontier = await this.referenced(query, gathered, [...targets.values()].slice(0, 25));
            gathered = [...gathered, ...frontier];
        }

        return gathered;
    }

    private async referenced(query: string, gathered: readonly Candidate[], targets: readonly { target: Candidate; from: Unit; uses: boolean }[]): Promise<Candidate[]> {
        if (targets.length === 0) {
            return [];
        }

        const labels = new Map(targets.map((target, i) => [`linked-${i}`, target]));
        const shown: Record<string, string> = {};
        const questions: Questions = {};

        for (const [label, target] of labels) {
            const from = target.from.path.length === 0 ? '(top level)' : target.from.path.join(' > ');
            shown[label] = head(target.target.unit.text, 1200);
            questions[`c_${label}`] = target.uses
                ? noul(`\`${label}\` points to \`${from}\` (it uses it by reference). Is \`${label}\` the place the question asks about, such as the endpoint or service that uses that definition?`, {
                    true: `the answer names or needs \`${label}\`, the user of the definition`,
                    false: `\`${label}\` is another user of the definition that the question is not about`,
                })
                : noul(`\`${from}\` takes part of its content from \`${label}\` by reference. Does the answer turn on what \`${label}\` defines, such as which fields are required, their types, default values or settings?`, {
                    true: `a required field, type, default or setting the answer needs is defined in \`${label}\``,
                    false: `the answer does not need anything defined in \`${label}\``,
                });
        }

        const answers = await send(this.jev, {
            state: {
                [QUESTION]: query,
                'the parts found so far': gathered.map((c) => head(c.unit.text, 1600)),
                'linked parts': shown,
            },
            questions,
        });

        if (answers === null) {
            return [];
        }

        return [...labels].filter(([label]) => (answers.noul(`c_${label}`) ?? 0) >= KEEP).map(([, target]) => target.target);
    }

    /**
     * Procedures: the first passages, plus for all the steps the whole section of the best one,
     * and for the step after or before a named one the step next to the passage Jev names as
     * that step and the neighbours of the best two that Jev confirms.
     */
    private async procedural(query: string, ranked: Ranked): Promise<Candidate[]> {
        const gathered = ranked.kept.slice(0, ranked.scope === 'several places' ? 6 : 3);

        if (ranked.scope === 'all the steps') {
            gathered.push(...section(ranked.kept[0]!));
        }

        if (ranked.scope === 'the next or previous step') {
            const next = ranked.anchor === null ? null : neighbour(ranked.anchor, ranked.direction === 'before' ? -1 : 1);

            if (next !== null) {
                gathered.push(next);
            }

            const around = ranked.kept.slice(0, 2).flatMap((candidate) => [-2, -1, 1, 2].flatMap((offset) => neighbour(candidate, offset) ?? []));
            gathered.push(...await this.confirmSteps(query, unique(around)));
        }

        return gathered;
    }

    private async confirmSteps(query: string, candidates: readonly Candidate[]): Promise<Candidate[]> {
        if (candidates.length === 0) {
            return [];
        }

        const labels = new Map(candidates.map((candidate, i) => [`step-${i}`, candidate]));
        const shown: Record<string, string> = {};
        const questions: Questions = {};

        for (const [label, candidate] of labels) {
            shown[label] = head(candidate.unit.text, this.passageChars);
            questions[`c_${label}`] = noul(`Is \`${label}\` the step the question asks for, the one done right after or right before the step it names?`, {
                true: `\`${label}\` describes the step the question asks for`,
                false: `\`${label}\` describes a different step, or no step`,
            });
        }

        const answers = await send(this.jev, { state: { [QUESTION]: query, 'the passages around the steps found': shown }, questions });

        if (answers === null) {
            return [];
        }

        return [...labels].filter(([label]) => (answers.noul(`c_${label}`) ?? 0) >= 0.5).map(([, candidate]) => candidate);
    }

    private async rank(query: string, pool: readonly Candidate[], scopes: Readonly<Record<string, string>>, steps = false): Promise<Ranked | null> {
        const labels = new Map(pool.map((candidate, i) => [`p${i}`, candidate]));
        const shown: Record<string, string> = {};
        const questions: Questions = {};

        for (const [label, candidate] of labels) {
            shown[label] = head(candidate.unit.text, this.passageChars);
            questions[`c_${label}`] = answersQuestion(label);
        }
        questions['best'] = choice('Which single one answers the question most directly?', Object.fromEntries([...labels.keys()].map((label) => [label, null])));
        if (Object.keys(scopes).length > 0) {
            questions['scope'] = choice('What does a full answer to the question need?', { ...scopes });
        }
        if (steps) {
            questions['anchor'] = choice('If the question asks what to do after or before a step it names, which passage describes that named step? Pick `none` if the question does not name a step.', Object.fromEntries([...labels.keys(), 'none'].map((label) => [label, null])));
            questions['direction'] = choice('Does the question ask for the step after or the step before the one it names?', {
                after: 'the question asks for the step done right after the one it names',
                before: 'the question asks for the step done right before the one it names',
            });
        }

        const answers = await send(this.jev, { state: { [QUESTION]: query, 'the passages the search found': shown }, questions });

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

        const scope = answers.choice('scope');
        const anchor = answers.choice('anchor');

        return {
            kept: kept.map((label) => labels.get(label)!),
            keptLabels: kept,
            scope: scope !== null && Object.hasOwn(scopes, scope) ? scope : 'one passage',
            scores,
            labels,
            anchor: anchor === null ? null : labels.get(anchor) ?? null,
            direction: answers.choice('direction') === 'before' ? 'before' : 'after',
        };
    }

    /** Null when the request failed. */
    private async complete(query: string, gathered: readonly Candidate[]): Promise<boolean | null> {
        const answers = await send(this.jev, {
            state: { [QUESTION]: query, 'the passages gathered': gathered.map((c) => head(c.unit.text, 1200)) },
            questions: {
                complete: noul('Do the passages gathered contain everything needed to answer the question in full?', {
                    true: 'every part of the question is answered by something in the passages',
                    false: 'some part of the question is not covered by any of the passages',
                }),
            },
        });
        const score = answers === null ? null : answers.noul('complete');

        return score === null ? null : score >= 0.5;
    }

    private async more(query: string, candidates: readonly Candidate[]): Promise<Candidate[]> {
        return candidates.length === 0 ? [] : (await this.rank(query, candidates, {}))?.kept ?? [];
    }

    /** Follows each gathered unit's links to units not yet gathered: needs always, references and definitions when Jev keeps them. */
    private async expand(query: string, gathered: readonly Candidate[]): Promise<Candidate[]> {
        const have = new Set(gathered.map(key));
        const byType: Record<string, Map<string, Candidate>> = { needs: new Map(), refers_to: new Map(), defined_by: new Map() };

        for (const candidate of gathered) {
            for (const link of candidate.document.links) {
                if (link.from !== candidate.unit.id) {
                    continue;
                }

                const target = candidate.document.unit(link.to);

                if (target === null || have.has(`${candidate.document.id}#${target.id}`) || !Object.hasOwn(byType, link.type)) {
                    continue;
                }

                const entry = { document: candidate.document, unit: target };
                byType[link.type]!.set(key(entry), entry);
            }
        }

        return unique([
            ...gathered,
            ...byType['needs']!.values(),
            ...await this.keep(query, gathered, [...byType['refers_to']!.values()], 'reference'),
            ...await this.keep(query, gathered, [...byType['defined_by']!.values()], 'definition'),
        ]);
    }

    private async keep(query: string, gathered: readonly Candidate[], candidates: readonly Candidate[], kind: 'reference' | 'definition'): Promise<Candidate[]> {
        if (candidates.length === 0) {
            return [];
        }

        const labels = new Map(candidates.map((candidate, i) => [`${kind}-${i}`, candidate]));
        const shown: Record<string, string> = {};
        const questions: Questions = {};

        for (const [label, candidate] of labels) {
            shown[label] = head(candidate.unit.text, 800);
            questions[`c_${label}`] = kind === 'definition'
                ? noul(`Does \`${label}\` give the meaning of a term that the answer to the question turns on?`, {
                    true: `whether the answer applies depends on what a term defined in \`${label}\` means`,
                    false: `\`${label}\` defines terms the answer does not depend on`,
                })
                : noul(`The gathered passages send the reader to \`${label}\`. Does the answer to the question need what \`${label}\` says?`, {
                    true: `\`${label}\` states a rule, condition or detail the answer depends on`,
                    false: `the answer is complete without what \`${label}\` says`,
                });
        }
        if (kind === 'definition') {
            const criteria: ChoiceCriteria = Object.fromEntries([...labels.keys(), 'none'].map((label) => [label, null]));
            questions['definition'] = choice('Which single definition does the answer depend on most? Pick `none` if the answer depends on none of them.', criteria);
        }

        const answers = await send(this.jev, {
            state: {
                [QUESTION]: query,
                'the passages gathered': gathered.map((c) => head(c.unit.text, 800)),
                [kind === 'definition' ? 'definitions' : 'referenced passages']: shown,
            },
            questions,
        });

        if (answers === null) {
            return [];
        }

        const pick = kind === 'definition' ? answers.choice('definition') : null;

        return [...labels].filter(([label]) => (answers.noul(`c_${label}`) ?? 0) >= 0.5 || pick === label).map(([, candidate]) => candidate);
    }
}

/**
 * Entry units of the candidates' documents whose name (or the name before a call's bracket),
 * three characters or more, the question contains as a whole word, case-insensitively; longest
 * names first, at most 6.
 */
function namedEntries(query: string, candidates: readonly Candidate[]): Candidate[] {
    const found = new Map<string, [number, Candidate]>();

    for (const document of documentsOf(candidates)) {
        for (const unit of document.units) {
            if (unit.kind !== 'entry') {
                continue;
            }

            const entry = { document, unit };

            for (const alias of aliases(phpString(unit.meta['name'] ?? ''))) {
                if (new RegExp(`(?<![\\p{L}\\p{N}_.])${escapeRegExp(alias)}(?![\\p{L}\\p{N}_(])`, 'iu').test(query)) {
                    found.set(key(entry), [length(alias), entry]);
                    break;
                }
            }
        }
    }

    return [...found.values()].sort(([a], [b]) => b - a).slice(0, 6).map(([, entry]) => entry);
}

function aliases(name: string): string[] {
    const names = [trim(name)];
    const call = /^([\p{L}\p{N}_.]+)\(/u.exec(name);

    if (call !== null) {
        names.push(call[1]!);
    }

    return [...new Set(names)].filter((alias) => length(alias) >= 3);
}

/** The slug (40 characters) of the entry's name, or of the unit's path (`passage` when empty), then its position. */
function entryLabel(candidate: Candidate, i: number): string {
    const name = candidate.unit.meta['name'];
    const named = slug(name === undefined || name === null ? candidate.unit.path.join(' ') : phpString(name), 40);

    return `${named === '' ? 'passage' : named}-${i}`;
}

/** The candidates' documents, each once, in candidate order. */
function documentsOf(candidates: readonly Candidate[]): Document[] {
    const byId = new Map<string, Document>();

    for (const candidate of candidates) {
        if (!byId.has(candidate.document.id)) {
            byId.set(candidate.document.id, candidate.document);
        }
    }

    return [...byId.values()];
}

/** A unit's fields, stored as a list of `{field, values}`, as name and values. */
function fieldEntries(fields: unknown): [string, string[]][] {
    return phpArray(fields ?? []).map(([, pair]) => {
        const record = Object.fromEntries(phpArray(pair));

        return [phpString(record['field']), phpArray(record['values'] ?? []).map(([, value]) => phpString(value))];
    });
}

/** Escapes the characters that are syntax in a `u`-flag pattern; escaping anything else is an error there. */
function escapeRegExp(text: string): string {
    return text.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&');
}

function sections(ranked: Ranked): Candidate[] {
    if (ranked.scope === 'a whole section') {
        return section(ranked.kept[0]!);
    }

    return ranked.kept.slice(0, ranked.scope === 'several places' ? 6 : 3);
}

/** Narrative answers spread across distant paragraphs: for several places every passage scoring 0.5 or more, up to 8, or the best 3 when fewer than 2 do. */
function narrative(ranked: Ranked): Candidate[] {
    if (ranked.scope !== 'several places') {
        return ranked.kept.slice(0, 3);
    }

    const strong = ranked.keptLabels.filter((label) => ranked.scores[label]! >= KEEP_SEVERAL).map((label) => ranked.labels.get(label)!);

    return strong.length >= 2 ? strong.slice(0, 8) : ranked.kept.slice(0, 3);
}

/** The content passage `offset` steps away in its document; a content passage has at least 25 tokens without its prefix, and the candidate itself always counts. */
function neighbour(candidate: Candidate, offset: number): Candidate | null {
    const units = candidate.document.units.filter((unit) => unit.kind === 'passage'
        && (unit.id === candidate.unit.id || tokens(body(unit.text), 4) >= MIN_STEP_TOKENS));
    const index = units.findIndex((unit) => unit.id === candidate.unit.id);
    const target = index === -1 ? undefined : units[index + offset];

    return target === undefined ? null : { document: candidate.document, unit: target };
}

/** Each document's first three candidates, in order, then the rest in order, so one document cannot fill the pool. */
function diverse(candidates: readonly Candidate[]): Candidate[] {
    const seen = new Map<string, number>();
    const first: Candidate[] = [];
    const later: Candidate[] = [];

    for (const candidate of candidates) {
        const count = (seen.get(candidate.document.id) ?? 0) + 1;
        seen.set(candidate.document.id, count);
        (count <= 3 ? first : later).push(candidate);
    }

    return [...first, ...later];
}

/** The profiles of the first five candidates, most common first, ties to the first seen; guidance when there are none. */
function profilesOf(candidates: readonly Candidate[]): string[] {
    const votes = new Map<string, number>();

    for (const candidate of candidates.slice(0, 5)) {
        const profile = profileOfUnit(candidate);
        votes.set(profile, (votes.get(profile) ?? 0) + 1);
    }

    const profiles = [...votes].sort(([, a], [, b]) => b - a).map(([profile]) => profile);

    return profiles.length === 0 ? [GUIDANCE] : profiles;
}

/** A unit's `meta.profile`, an unknown one read as guidance. */
function profileOfUnit(candidate: Candidate): string {
    const named = String(candidate.unit.meta['profile'] ?? GUIDANCE);

    return profileExists(named) ? named : GUIDANCE;
}

export function answersQuestion(label: string): ReturnType<typeof noul> {
    return noul(`Does \`${label}\` answer the question?`, {
        true: `\`${label}\` states the answer, or a part of it.`,
        false: `\`${label}\` is on the same subject but does not answer the question, or only asserts that it does.`,
    });
}

/** Every passage of the candidate's document under the same headings. */
function section(candidate: Candidate): Candidate[] {
    const path = candidate.unit.path.join('\0');

    return candidate.document.units
        .filter((unit) => unit.kind === 'passage' && unit.path.length === candidate.unit.path.length && unit.path.join('\0') === path)
        .map((unit) => ({ document: candidate.document, unit }));
}

export function key(candidate: Candidate): string {
    return `${candidate.document.id}#${candidate.unit.id}`;
}

export function unique(candidates: readonly Candidate[]): Candidate[] {
    const out = new Map<string, Candidate>();

    for (const candidate of candidates) {
        if (!out.has(key(candidate))) {
            out.set(key(candidate), candidate);
        }
    }

    return [...out.values()];
}

/** By document id as byte strings, then position in the document. */
export function inDocumentOrder(candidates: readonly Candidate[]): Candidate[] {
    const position = (candidate: Candidate): number => {
        let found = -1;

        candidate.document.units.forEach((unit, i) => {
            if (unit.id === candidate.unit.id) {
                found = i;
            }
        });

        return found;
    };

    return [...candidates].sort((a, b) => byteCompare(a.document.id, b.document.id) || position(a) - position(b));
}
