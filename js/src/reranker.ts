import { choice, noul, type ChoiceCriteria, type Questions, type SystemOneRequest } from '@typesafe-ai/sdk';
import { Config } from './config.js';
import { Passage, RankedPassage } from './retrieval/passage.js';
import { head, slug, trim } from './text.js';
import { send, type Answers, type Jev } from './typesafe/jev.js';

const PREFIX = 'c_';

const BEST = 'best';

const LABEL_LENGTH = 60;

const SUBJECT = 'the passages the search found';

const INTENT = 'the question being asked';

/**
 * Reorders search hits by whether they answer the question asked.
 * Nearest-neighbour search finds passages written like the query, which is
 * close to but not the same as passages that answer it, so over-fetch from the
 * search, pass the hits here and keep the first few.
 *
 * Each passage gets one yes/no question, all in one request, and those scoring
 * at least `rankThreshold` come back best first. With `crossCheck`, the same
 * request asks for the single best passage and moves it to the front, which
 * corrects scores that all hedge.
 */
export class Reranker {
    constructor(
        private readonly jev: Jev,
        private readonly config: Config = new Config(),
    ) {}

    /** Best first and at most `limit`; null, never a partial list, when Jev does not answer, there are no passages or the query is blank. */
    async rank(passages: readonly Passage[], query: string, limit: number | null = null): Promise<RankedPassage[] | null> {
        if (passages.length === 0 || trim(query) === '') {
            return null;
        }

        const labelled = new Map<string, Passage>();
        passages.forEach((passage, index) => labelled.set(label(passage, index), passage));

        const answers = await send(this.jev, this.request(labelled, query));

        if (answers === null) {
            return null;
        }

        const ranked = this.scores(answers, [...labelled.keys()]).map(([name, score]) => new RankedPassage(labelled.get(name)!, score));

        return limit === null ? ranked : ranked.slice(0, Math.max(0, limit));
    }

    private request(labelled: ReadonlyMap<string, Passage>, query: string): SystemOneRequest {
        const shown: Record<string, string> = {};
        const questions: Questions = {};

        for (const [name, passage] of labelled) {
            shown[name] = head(passage.text, this.config.passageChars);
        }

        for (const name of labelled.keys()) {
            questions[PREFIX + name] = noul(`Does \`${name}\` answer the question?`, {
                true: `\`${name}\` states the answer, or a part of it.`,
                false: `\`${name}\` is on the same subject but does not answer the question, or only asserts that it does.`,
            });
        }

        if (this.config.crossCheck) {
            const criteria: ChoiceCriteria = Object.fromEntries([...labelled.keys()].map((name) => [name, null]));
            questions[BEST] = choice('Which single one answers the question most directly?', criteria);
        }

        return { state: { [SUBJECT]: shown, [INTENT]: query }, questions };
    }

    /** Label and score pairs, best first, ties in passage order. */
    private scores(answers: Answers, labels: readonly string[]): [string, number][] {
        const all = new Map<string, number>();

        for (const name of labels) {
            const score = answers.noul(PREFIX + name);

            if (score !== null) {
                all.set(name, score);
            }
        }

        const scores = [...all].filter(([, score]) => score >= this.config.rankThreshold).sort(([, a], [, b]) => b - a);
        const pick = answers.choice(BEST);

        if (pick === null || !labels.includes(pick) || (scores.length === 0 && !this.config.crossCheckRescue)) {
            return scores;
        }

        return [[pick, all.get(pick) ?? 0], ...scores.filter(([name]) => name !== pick)];
    }
}

/** A readable name from the heading path, with the position on the end so two passages under one heading stay apart. */
function label(passage: Passage, index: number): string {
    const name = passage.headingPath.length === 0 ? `passage ${index + 1}` : passage.headingPath.join(' > ');
    const named = slug(name, LABEL_LENGTH);

    return `${named === '' ? 'untitled' : named}-${index}`;
}
