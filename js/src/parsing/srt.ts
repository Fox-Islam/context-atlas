import type { Block } from '../block.js';
import { Captions, captionTime, normaliseCaptions } from './captions.js';

const TIMING = /^[ \t]*(\d+):(\d{2}):(\d{2})[,.]\d{1,3}[ \t]*-->/;

const INDEX = /^[ \t]*\d+[ \t]*$/;

/**
 * Reads SubRip captions: one paragraph per utterance of each cue, with the speaker from a
 * leading `Name:` and the cue's start time in meta. A cue starts at each timing line, so a
 * missing index or a blank line inside a cue's text is read through
 * (spec/formats/transcripts.md).
 */
export function parseSrt(text: string): Block[] {
    const captions = new Captions();
    const blocks: Block[] = [];
    let time: string | null = null;
    let cue: string[] = [];

    for (const line of normaliseCaptions(text).split('\n')) {
        const timing = TIMING.exec(line);

        if (timing === null) {
            cue.push(line);
            continue;
        }
        if (cue.length > 0 && INDEX.test(cue[cue.length - 1]!)) {
            cue.pop();
        }
        if (time !== null) {
            blocks.push(...cueBlocks(captions, cue, time));
        }
        time = captionTime(timing[1]!, timing[2]!, timing[3]!);
        cue = [];
    }
    if (time !== null) {
        blocks.push(...cueBlocks(captions, cue, time));
    }

    return blocks;
}

/** The lines after a timing line, up to the next cue's index or timing line, without tags. */
function cueBlocks(captions: Captions, lines: readonly string[], time: string): Block[] {
    return captions.blocks(lines.map((line) => line.replace(/<[^>]*>|\{\\[^}]*\}/g, '')), time);
}
