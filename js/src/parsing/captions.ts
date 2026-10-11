import { Block } from '../block.js';
import { NOT_NAMES } from '../prose/speakers.js';
import { squash, trim, WHITESPACE } from '../text.js';

const NOT_SPEAKER_NAMES: ReadonlySet<string> = new Set(NOT_NAMES);

const NON_SPACE = `[^${WHITESPACE.slice(1, -1)}]`;

const NAME = "\\p{Lu}[\\p{L}\\p{M}'’.\\-]*(?: \\p{Lu}[\\p{L}\\p{M}'’.\\-]*){0,3}";

const SPEAKER = new RegExp(`^(${NAME})(?: \\(([^()]+)\\))?:${WHITESPACE}+(${NON_SPACE}[^\\n]*)$`, 'u');

const SPEAKER_LINE = new RegExp(`^(${NAME})(?: \\([^()]+\\))?:(?:${WHITESPACE}|$)`, 'u');

const DASH = new RegExp(`^-${WHITESPACE}+`, 'u');

interface Speaker {
    speaker: string;
    speaker_full?: string;
}

/**
 * What WebVTT and SRT share: a cue's lines split into utterances at `- ` lines and `Name:`
 * lines, the speaker read from a leading `Name:` or carried over from the last named cue, and
 * the cue start written as hh:mm:ss. One instance reads one file (spec/formats/transcripts.md).
 */
export class Captions {
    /** The speaker unnamed utterances take. */
    private current: Speaker | null = null;

    /** A cue's text lines (tags removed) as utterances: a `- ` line starts one, as does a `Name:` line after the first. */
    blocks(lines: readonly string[], time: string): Block[] {
        const groups: string[][] = [];
        const dashes: boolean[] = [];

        for (const raw of lines) {
            let line = trim(raw);

            if (line === '') {
                continue;
            }

            const dash = DASH.test(line);
            line = dash ? trim(line.replace(DASH, '')) : line;

            const named = SPEAKER_LINE.exec(line);

            if (groups.length === 0 || dash || (named !== null && isName(named[1]!))) {
                groups.push([]);
                dashes.push(dash);
            }
            groups[groups.length - 1]!.push(line);
        }

        return groups.flatMap((group, g) => this.utterance(squash(group.join(' ')), time, null, dashes[g]!) ?? []);
    }

    /**
     * One utterance with meta `{speaker?, speaker_full?, time}`. The speaker is the voice when
     * given, else a leading `Name:` or `Name (role):`, else the current speaker; a dash utterance
     * without a name has no speaker and clears the current one.
     */
    utterance(text: string, time: string, voice: string | null = null, dash = false): Block | null {
        let said = text;
        const match = SPEAKER.exec(text);

        if (voice !== null && voice !== '') {
            this.current = { speaker: voice };
        } else if (match !== null && isName(match[1]!)) {
            this.current = { speaker: match[1]! };
            if (match[2] !== undefined) {
                this.current.speaker_full = `${match[1]} (${match[2]})`;
            }
            said = match[3]!;
        } else if (dash) {
            this.current = null;
        }

        return said === '' ? null : Block.paragraph(said, { ...(this.current ?? {}), time });
    }
}

/** hh:mm:ss from a timestamp's parts, the hours padded to two digits. */
export function captionTime(hours: string | null, minutes: string, seconds: string): string {
    return `${(hours ?? '0').padStart(2, '0')}:${minutes}:${seconds}`;
}

/** Line endings as `\n`, a leading byte order mark removed. */
export function normaliseCaptions(text: string): string {
    const lines = text.replace(/\r\n?/g, '\n');

    return lines.startsWith('﻿') ? lines.slice(1) : lines;
}

/** False for a name in NOT_SPEAKER_NAMES or starting with a word in it, compared in lower case, and for a name with a digit. */
function isName(name: string): boolean {
    const lower = name.toLowerCase();

    return !NOT_SPEAKER_NAMES.has(lower) && !NOT_SPEAKER_NAMES.has(lower.split(' ')[0]!) && !/\p{Nd}/u.test(lower);
}
