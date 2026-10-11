import { BlockType, type Block } from '../block.js';
import type { Config } from '../config.js';
import { sentences, tokens as tokensOf, trim } from '../text.js';
import type { Crumb } from './breadcrumbs.js';
import type { CutPlanner, Unit } from './cutPlanner.js';
import type { Pair, SeamScorer } from './seamScorer.js';

/** Indexes of the blocks that open a topic, and of the sentences that do inside long paragraphs. */
export interface Shifts {
    blocks: ReadonlySet<number>;
    sentences: ReadonlyMap<number, ReadonlySet<number>>;
}

export const NO_SHIFTS: Shifts = { blocks: new Set(), sentences: new Map() };

/**
 * Finds where a long stretch of text changes topic. Headings already split
 * sections, so the stretches asked about are the body blocks of a section
 * holding more than `minTokens` and the sentences of a paragraph longer than
 * `splitLongParagraphAt`. Block seams are keyed `b{index}` and sentence seams
 * `b{index}s{sentence}`, after the unit that opens the seam's second side.
 */
export class TopicShifts {
    constructor(
        private readonly scorer: SeamScorer,
        private readonly planner: CutPlanner,
        private readonly config: Config,
    ) {}

    /** Null when there was no seam to ask about or Jev did not answer. */
    async find(blocks: readonly Block[], tokens: readonly number[], crumbs: readonly Crumb[], quietSpeech = false): Promise<Shifts | null> {
        const found = [...this.sectionStretches(blocks, tokens, crumbs), ...this.sentenceStretches(blocks, tokens)];
        const stretches = quietSpeech ? found.filter((units) => !spoken(blocks, units)) : found;
        const pairs = new Map<string, Pair>();

        for (const units of stretches) {
            for (let i = 1; i < units.length; i++) {
                pairs.set(units[i]!.key, [units[i - 1]!.text, units[i]!.text]);
            }
        }

        if (pairs.size === 0) {
            return null;
        }

        const scores = await this.scorer.score(pairs);

        if (scores === null) {
            return null;
        }

        const shiftedBlocks = new Set<number>();
        const shiftedSentences = new Map<number, Set<number>>();

        for (const units of stretches) {
            for (const key of this.planner.cuts(units, scores)) {
                const match = /^b(\d+)(?:s(\d+))?$/.exec(key);

                if (match === null) {
                    continue;
                }

                const block = Number(match[1]);

                if (match[2] === undefined) {
                    shiftedBlocks.add(block);
                } else {
                    const marked = shiftedSentences.get(block) ?? new Set<number>();
                    marked.add(Number(match[2]));
                    shiftedSentences.set(block, marked);
                }
            }
        }

        return { blocks: shiftedBlocks, sentences: shiftedSentences };
    }

    private sectionStretches(blocks: readonly Block[], tokens: readonly number[], crumbs: readonly Crumb[]): Unit[][] {
        const bySection = new Map<number, Unit[]>();

        blocks.forEach((block, index) => {
            if (block.isHeading()) {
                return;
            }

            const section = crumbs[index]!.section;
            const units = bySection.get(section) ?? [];
            units.push({ key: `b${index}`, text: block.text, tokens: tokens[index]! });
            bySection.set(section, units);
        });

        return [...bySection.values()].filter(
            (units) => units.length > 1 && units.reduce((sum, unit) => sum + unit.tokens, 0) > this.config.minTokens,
        );
    }

    private sentenceStretches(blocks: readonly Block[], tokens: readonly number[]): Unit[][] {
        const stretches: Unit[][] = [];

        blocks.forEach((block, index) => {
            if (block.type !== BlockType.Paragraph || tokens[index]! <= this.config.splitLongParagraphAt) {
                return;
            }

            const units = sentences(block.text).map((sentence, i) => ({
                key: `b${index}s${i}`,
                text: sentence,
                tokens: tokensOf(sentence, this.config.charsPerToken),
            }));

            if (units.length > 1) {
                stretches.push(units);
            }
        });

        return stretches;
    }
}

/**
 * Whether the blocks of a stretch that name a speaker hold at least half its text: the stretch is
 * part of a conversation, which is not chunked.
 */
function spoken(blocks: readonly Block[], units: readonly Unit[]): boolean {
    let total = 0;
    let said = 0;

    for (const unit of units) {
        const block = blocks[Number.parseInt(unit.key.slice(1), 10)]!;
        const length = Array.from(trim(unit.text)).length;
        const speaker = block.meta['speaker'];

        total += length;
        if (typeof speaker === 'string' && speaker !== '') {
            said += length;
        }
    }

    return said > 0 && 2 * said >= total;
}
