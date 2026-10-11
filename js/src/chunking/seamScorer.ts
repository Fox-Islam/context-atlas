import { noul, type Questions, type SystemOneRequest } from '@typesafe-ai/sdk';
import type { Config } from '../config.js';
import { end, head } from '../text.js';
import { send, type Jev } from '../typesafe/jev.js';
import type { SeamScore } from './cutPlanner.js';

const CONTINUES = 'p_';

const REFERS_BACK = 'r_';

/** The two sides of a seam: the unit before it and the unit that opens it. */
export type Pair = readonly [first: string, second: string];

/**
 * Asks Jev, for each seam between neighbouring units, whether the second
 * continues the first one's subject and whether it depends on the first to be
 * understood. A worked example after the rule it illustrates can read as a
 * change of subject while being useless alone, so the two are asked apart.
 * Only the end of the first side and the start of the second are sent.
 */
export class SeamScorer {
    constructor(
        private readonly jev: Jev,
        private readonly config: Config,
    ) {}

    /** Scores by seam key, or null when any batch went unanswered. */
    async score(pairs: ReadonlyMap<string, Pair>): Promise<Map<string, SeamScore> | null> {
        const asked = [...pairs].slice(0, this.config.maxPairs);
        const scores = new Map<string, SeamScore>();

        for (let offset = 0; offset < asked.length; offset += this.config.batchSize) {
            const batch = asked.slice(offset, offset + this.config.batchSize);
            const answers = await send(this.jev, this.request(batch));

            if (answers === null) {
                return null;
            }

            for (const [key] of batch) {
                const continues = answers.noul(CONTINUES + key);

                if (continues !== null) {
                    scores.set(key, { continues, refersBack: answers.noul(REFERS_BACK + key) ?? 0 });
                }
            }
        }

        return scores;
    }

    private request(batch: readonly (readonly [string, Pair])[]): SystemOneRequest {
        const seams: Record<string, { end_of_first: string; start_of_second: string }> = {};
        const questions: Questions = {};

        for (const [key, [first, second]] of batch) {
            seams[key] = {
                end_of_first: end(first, this.config.snippetChars),
                start_of_second: head(second, this.config.snippetChars),
            };
        }

        for (const key of Object.keys(seams)) {
            questions[CONTINUES + key] = noul(
                `In seam ${key}, is the second passage about the same specific subject as the first, rather than just the same document?`,
                {
                    true: 'the same specific subject: a reader looking for one would want the other',
                    false: 'a different subject, even though both belong to the same document',
                },
            );
            questions[REFERS_BACK + key] = noul(
                `In seam ${key}, does the second passage depend on the first to be understood?`,
                {
                    true: 'it refers back to the first, continues its list or example, or answers it, so it makes little sense read alone',
                    false: 'it can be understood without the first',
                },
            );
        }

        return { state: { seams }, questions };
    }
}
