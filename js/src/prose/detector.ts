import { choice, type Questions } from '@typesafe-ai/sdk';
import type { Chunk } from '../chunk.js';
import { ordered } from '../support/ordered.js';
import { head } from '../text.js';
import { send, type Jev } from '../typesafe/jev.js';
import { body } from './links.js';
import { detectable, GUIDANCE } from './profiles.js';

const OPENING = 600;

export interface Section {
    title: string;
    opening: string;
    headings: string[];
}

export interface Detected {
    /** Section key to profile, in order of first appearance. */
    sections: Map<string, string>;
    scores: Map<string, number>;
}

/**
 * The first heading depth (from 0) at which the chunks' heading paths take two or more different
 * values, or 0 when they never do; a document under a single title splits below it.
 */
export function sectionDepth(chunks: readonly Chunk[]): number {
    const deepest = Math.max(0, ...chunks.map((chunk) => chunk.headingPath.length));

    for (let depth = 0; depth < deepest; depth++) {
        const values = new Set(chunks.flatMap((chunk) => (depth < chunk.headingPath.length ? [chunk.headingPath[depth]!] : [])));

        if (values.size >= 2) {
            return depth;
        }
    }

    return 0;
}

/** A chunk's section key: its headings down to the section depth, joined by ` > `. */
export function sectionKey(path: readonly string[], depth: number): string {
    return path.slice(0, depth + 1).join(' > ');
}

/**
 * Sections by key, in order of first appearance, each described by its title (the last heading
 * of its key), its opening words and up to 20 distinct deeper headings.
 */
export function sectionsOf(chunks: readonly Chunk[]): Map<string, Section> {
    const depth = sectionDepth(chunks);
    const sections = new Map<string, Section>();

    for (const chunk of chunks) {
        const key = sectionKey(chunk.headingPath, depth);
        const last = chunk.headingPath.slice(0, depth + 1).at(-1);
        const section = sections.get(key) ?? { title: last ?? '', opening: head(body(chunk.text), OPENING), headings: [] };
        sections.set(key, section);

        for (const heading of chunk.headingPath.slice(depth + 1)) {
            if (!section.headings.includes(heading) && section.headings.length < 20) {
                section.headings.push(heading);
            }
        }
    }

    return sections;
}

/**
 * Chooses a profile per top-level section in one Jev request, so a document that appends an FAQ
 * to its terms gets the normative profile for the terms and guidance for the FAQ. Null when Jev
 * did not answer.
 */
export async function detectProfiles(jev: Jev, chunks: readonly Chunk[]): Promise<Detected | null> {
    const sections = sectionsOf(chunks);
    const labels = new Map([...sections.keys()].map((title, i) => [`s${i + 1}`, title]));
    const questions: Questions = {};

    for (const label of labels.keys()) {
        questions[label] = choice(`What kind of text is section \`${label}\`?`, detectable());
    }

    const answers = await send(jev, {
        state: { sections: ordered([...labels].map(([label, title]) => [label, { ...sections.get(title)! }])) },
        questions,
    });

    if (answers === null) {
        return null;
    }

    const detected: Detected = { sections: new Map(), scores: new Map() };

    for (const [label, title] of labels) {
        const picked = answers.choice(label);
        detected.sections.set(title, picked !== null && Object.hasOwn(detectable(), picked) ? picked : GUIDANCE);
        detected.scores.set(title, answers.choiceConfidence(label));
    }

    return detected;
}
