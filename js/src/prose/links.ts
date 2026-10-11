import { noul, type NoulQuestion, type Questions, type SystemOneRequest } from '@typesafe-ai/sdk';
import { Link } from '../document/link.js';
import type { Unit } from '../document/unit.js';
import { terms as lexicalTerms } from '../store/lexical.js';
import { end, head, length, trim, WHITESPACE } from '../text.js';
import { send, type Jev } from '../typesafe/jev.js';

const PAIR_BATCH = 30;

const DISTINCTIVE = 0.2;

/**
 * Only the keywords match in any case, so `[A-Z]` stays a capital letter. JavaScript has no
 * scoped case-insensitivity, so each keyword letter is a class of its cases; with the `u` flag
 * PCRE also folds `ſ` to `s`.
 */
function anyCase(words: string, unicode: boolean): string {
    return words.replace(/[a-z]/g, (letter) => `[${letter}${letter.toUpperCase()}${unicode && letter === 's' ? 'ſ' : ''}]`);
}

const ASCII_SPACE = '[ \\t\\n\\v\\f\\r]';

/** PCRE without the `u` flag: `\s` is ASCII whitespace and `\b` an ASCII word boundary. */
const REFERENCE = new RegExp(`\\b(?:${anyCase('sections?|clauses?|articles?|regulations?|rules?|paragraphs?|schedules?|parts?', false)})${ASCII_SPACE}+((?:(?:\\d+[A-Z]?|[A-Z]\\b)(?:\\.\\d+)*(?:\\([a-z0-9]+\\))*(?:${ASCII_SPACE}*(?:,|and|or|to)${ASCII_SPACE}*)?)+)`, 'g');

const REFERENCED_NUMBER = /(?:\d+[A-Z]?|\b[A-Z]\b)(?:\.\d+)*(?:\([a-z0-9]+\))*/g;

const SPACE_CLASS = WHITESPACE.slice(1, -1);

/** PCRE with the `u` flag: `\s` is Unicode whitespace and `\d` any decimal digit. */
const OWN_NUMBER = new RegExp(`^(?:${anyCase('(?:section|clause|article|regulation|rule|paragraph|schedule|part)', true)}${WHITESPACE}+)?(\\p{Nd}+[A-Z]?(?:\\.\\p{Nd}+)*|[A-Z](?:\\.\\p{Nd}+)*(?=\\.${WHITESPACE}))(?=[${SPACE_CLASS}.:)—–-]|$)`, 'u');

const ANCHOR = /#(\p{Lu}[^#.,;:()\n]{2,60})/gu;

const PREFIX = new RegExp(`^\\[[^\\]\\n]*\\]${WHITESPACE}*`, 'u');

type Pairs = Map<string, readonly [Unit, Unit]>;

/** A passage's text without its `[heading > path]` prefix. */
export function body(text: string): string {
    return text.replace(PREFIX, '');
}

/**
 * Builds the typed links between passages that retrieval follows. A failed request leaves its
 * links out; the document is usable without them.
 *
 * - `needs`: a passage that depends on the one before it, such as a worked example after its
 *   rule. Asked of every consecutive pair.
 * - `defined_by`: a passage that uses a term another passage defines. Jev marks definition
 *   passages, then is asked only about passages sharing a distinctive term with one: asking
 *   about every pair links nearly every passage to every definition.
 * - `refers_to`: an explicit cross-reference such as "Section 3.1", parsed without Jev.
 */
export class LinkBuilder {
    constructor(private readonly jev: Jev | null) {}

    /** `units` in document order. */
    async needs(units: readonly Unit[]): Promise<Link[]> {
        const pairs: Pairs = new Map();

        for (let i = 1; i < units.length; i++) {
            pairs.set(`n${i - 1}`, [units[i - 1]!, units[i]!]);
        }

        const yes = await this.ask(
            pairs,
            (key) => noul(`In pair ${key}, does the second passage depend on the first to be understood?`, {
                true: 'it refers back to the first, continues its list or example, or answers it, so it makes little sense read alone',
                false: 'it can be understood without the first',
            }),
            (a, b) => ({ end_of_first: end(a.text, 300), start_of_second: head(body(b.text), 300) }),
        );

        return yes.map((key) => new Link('needs', pairs.get(key)![1].id, pairs.get(key)![0].id));
    }

    async definitions(units: readonly Unit[]): Promise<Link[]> {
        const definitions = await this.definitionUnits(units);

        if (definitions.length === 0) {
            return [];
        }

        const pairs = sharingTerms(units, definitions);
        const yes = await this.ask(
            pairs,
            (key) => noul(`In pair ${key}, does the passage use a term whose meaning the definitions state?`, {
                true: 'a term in the passage is one the definitions give the meaning of',
                false: 'none of the defined terms appear in the passage',
            }),
            (a, b) => ({ passage: head(a.text, 500), definitions: head(b.text, 700) }),
            25,
        );

        return yes.map((key) => new Link('defined_by', pairs.get(key)![0].id, pairs.get(key)![1].id));
    }

    private async definitionUnits(units: readonly Unit[]): Promise<Unit[]> {
        const pairs: Pairs = new Map(units.map((unit, i) => [`d${i}`, [unit, unit]]));
        const yes = await this.ask(
            pairs,
            (key) => noul(`Does \`${key}\` define what a term means, for use elsewhere in the document?`, {
                true: `\`${key}\` states the meaning of one or more terms`,
                false: `\`${key}\` uses terms without defining them`,
            }),
            (a) => head(a.text, 500),
            PAIR_BATCH,
            'passages',
        );

        return yes.map((key) => pairs.get(key)![0]);
    }

    /** One noul per pair, in batches; the keys answered 0.5 or more. */
    private async ask(
        pairs: Pairs,
        question: (key: string) => NoulQuestion,
        show: (a: Unit, b: Unit) => Record<string, string> | string,
        batch = PAIR_BATCH,
        state = 'pairs',
    ): Promise<string[]> {
        if (this.jev === null || pairs.size === 0) {
            return [];
        }

        const entries = [...pairs];
        const yes: string[] = [];

        for (let offset = 0; offset < entries.length; offset += Math.max(1, batch)) {
            const chunk = entries.slice(offset, offset + Math.max(1, batch));
            const questions: Questions = {};
            const shown: Record<string, unknown> = {};

            for (const [key, [a, b]] of chunk) {
                shown[key] = show(a, b);
                questions[key] = question(key);
            }

            const answers = await send(this.jev, { state: { [state]: shown } as SystemOneRequest['state'], questions });

            if (answers === null) {
                continue;
            }
            for (const [key] of chunk) {
                if ((answers.noul(key) ?? 0) >= 0.5) {
                    yes.push(key);
                }
            }
        }

        return yes;
    }
}

/**
 * Explicit references to numbered sections ("Section 3.1", "Section Q"), resolved against the
 * numbers that open each passage's headings or text, then wiki-style `#Heading` anchors. A
 * reference to "34(9)" falls back to "34" when no passage is numbered "34(9)".
 */
export function references(units: readonly Unit[]): Link[] {
    const numbered = new Map<string, string>();

    for (const unit of units) {
        for (const number of numbersOf(unit)) {
            if (!numbered.has(number)) {
                numbered.set(number, unit.id);
            }
        }
    }

    const anchors = anchorsOf(units);
    const links: Link[] = [];

    for (const unit of units) {
        const targets = new Set<string>();

        for (const match of body(unit.text).matchAll(REFERENCE)) {
            for (const [number] of match[1]!.matchAll(REFERENCED_NUMBER)) {
                const target = numbered.get(number) ?? numbered.get(number.replace(/\(.*$/, '')) ?? null;

                if (target !== null && target !== unit.id) {
                    targets.add(target);
                }
            }
        }
        for (const match of body(unit.text).matchAll(ANCHOR)) {
            for (const target of anchorFor(trim(match[1]!).toLowerCase(), anchors)) {
                if (target !== unit.id) {
                    targets.add(target);
                }
            }
        }
        for (const target of targets) {
            links.push(new Link('refers_to', unit.id, target));
        }
    }

    return links;
}

/** The units of the longest heading the anchor text starts with, followed by its end or a space; the first such heading on a tie. */
function anchorFor(text: string, anchors: ReadonlyMap<string, string[]>): string[] {
    let best: string | null = null;

    for (const heading of anchors.keys()) {
        const fits = text.startsWith(heading) && (text.length === heading.length || text[heading.length] === ' ');

        if (fits && (best === null || length(heading) > length(best))) {
            best = heading;
        }
    }

    return best === null ? [] : anchors.get(best)!;
}

/** Each last heading, lowercased, mapped to the first two passages under it, for wiki-style `#Heading` references. */
function anchorsOf(units: readonly Unit[]): Map<string, string[]> {
    const anchors = new Map<string, string[]>();

    for (const unit of units) {
        const heading = trim(unit.path.at(-1) ?? '').toLowerCase();
        const under = anchors.get(heading) ?? [];

        if (heading !== '' && under.length < 2) {
            under.push(unit.id);
            anchors.set(heading, under);
        }
    }

    return anchors;
}

/** The section numbers a passage carries: from each heading in its path, then from the start of its own text. */
function numbersOf(unit: Unit): string[] {
    const numbers: string[] = [];

    for (const line of [...unit.path, head(body(unit.text), 40)]) {
        const match = OWN_NUMBER.exec(trim(line));

        if (match !== null && !numbers.includes(match[1]!)) {
            numbers.push(match[1]!);
        }
    }

    return numbers;
}

/**
 * Passage and definition pairs that share a distinctive term: one found in at most a fifth of
 * the passages (and at least two). Each passage pairs with each definition at its first shared
 * distinctive term.
 */
function sharingTerms(units: readonly Unit[], definitions: readonly Unit[]): Pairs {
    const frequency = new Map<string, number>();

    for (const unit of units) {
        for (const term of termsOf(unit.text)) {
            frequency.set(term, (frequency.get(term) ?? 0) + 1);
        }
    }

    const limit = Math.max(2, Math.floor(DISTINCTIVE * units.length));
    const pairs: Pairs = new Map();

    for (const unit of units) {
        const own = new Set(termsOf(unit.text));

        for (const definition of definitions) {
            if (definition.id === unit.id) {
                continue;
            }
            if (termsOf(definition.text).some((term) => own.has(term) && (frequency.get(term) ?? 0) <= limit)) {
                pairs.set(`u${pairs.size}`, [unit, definition]);
            }
        }
    }

    return pairs;
}

/** Distinct terms of four characters or more from the text without its prefix; shorter ones are rarely the defined term. */
function termsOf(text: string): string[] {
    return [...new Set(lexicalTerms(body(text)).filter((term) => length(term) >= 4))];
}
