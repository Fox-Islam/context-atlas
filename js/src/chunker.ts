import type { Block } from './block.js';
import { Chunk, Chunked, type OutlineEntry } from './chunk.js';
import { breadcrumbs, type Crumb } from './chunking/breadcrumbs.js';
import { CutPlanner } from './chunking/cutPlanner.js';
import { Packer } from './chunking/packer.js';
import { SeamScorer } from './chunking/seamScorer.js';
import { NO_SHIFTS, TopicShifts, type Shifts } from './chunking/topicShifts.js';
import { Config } from './config.js';
import { squash, tokens as tokensOf, trim } from './text.js';
import type { Jev } from './typesafe/jev.js';

/**
 * Splits a document's blocks into chunks at headings, at size limits and,
 * given Jev, where the topic changes inside a section. Without Jev, or when
 * Jev does not answer, the chunks follow headings and sizes only.
 *
 * ```ts
 * const chunked = await new Chunker(new TypeSafeClient()).chunk(parseMarkdown(markdown));
 * ```
 */
export class Chunker {
    constructor(
        private readonly jev: Jev | null = null,
        private readonly config: Config = new Config(),
    ) {}

    /**
     * A chunk's `blocks` and an outline entry's `block` index into `blocks`, counting the empty
     * blocks left out. With `quietSpeech`, Jev is asked nothing about stretches mostly made of
     * blocks that name a speaker.
     */
    async chunk(blocks: readonly Block[], quietSpeech = false): Promise<Chunked> {
        const origin: number[] = [];
        const kept: Block[] = [];

        blocks.forEach((block, index) => {
            if (trim(block.text) !== '') {
                origin.push(index);
                kept.push(block);
            }
        });

        const tokens = kept.map((block) => tokensOf(trim(block.text), this.config.charsPerToken));
        const crumbs = breadcrumbs(kept);
        const shifts = await this.shifts(kept, tokens, crumbs, quietSpeech);
        const chunks = new Packer(kept, tokens, crumbs, shifts ?? NO_SHIFTS, this.config).pack();

        return new Chunked(
            chunks.map((chunk) => new Chunk(
                chunk.text,
                chunk.blocks.map((index) => origin[index]!),
                chunk.headingPath,
                chunk.headingLevel,
                chunk.section,
                chunk.tokens,
                chunk.overlap,
                chunk.topicStart,
            )),
            outline(kept, origin),
            shifts !== null,
        );
    }

    private async shifts(blocks: readonly Block[], tokens: readonly number[], crumbs: readonly Crumb[], quietSpeech: boolean): Promise<Shifts | null> {
        if (this.jev === null) {
            return null;
        }

        const finder = new TopicShifts(new SeamScorer(this.jev, this.config), new CutPlanner(this.config), this.config);

        return finder.find(blocks, tokens, crumbs, quietSpeech);
    }
}

function outline(blocks: readonly Block[], origin: readonly number[]): OutlineEntry[] {
    const entries: OutlineEntry[] = [];

    blocks.forEach((block, index) => {
        if (block.isHeading()) {
            entries.push({ block: origin[index]!, title: squash(block.text), level: block.level ?? 0 });
        }
    });

    return entries;
}
