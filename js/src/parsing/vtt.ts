import type { Block } from '../block.js';
import { ContextAtlasError } from '../errors.js';
import { squash, trim } from '../text.js';
import { Captions, captionTime, normaliseCaptions } from './captions.js';

const TIMING = /^[ \t]*(?:(\d+):)?(\d{2}):(\d{2})\.\d{3}[ \t]+-->[ \t]+/;

const VOICE = /<v(?:\.[^ \t\n\f.>]+)*(?:[ \t\n\f]+([^>]*))?>|<\/v>/g;

const ENTITY = /&(amp|lt|gt|nbsp|lrm|rlm|quot|apos|#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6});/g;

const ENTITIES: Readonly<Record<string, string>> = { amp: '&', lt: '<', gt: '>', nbsp: ' ', lrm: '', rlm: '', quot: '"', apos: "'" };

/**
 * Reads WebVTT captions: one paragraph per utterance of each cue, with the speaker from a
 * `<v Name>` voice span or a leading `Name:` and the cue's start time in meta. NOTE, STYLE and
 * REGION blocks are dropped (spec/formats/transcripts.md).
 *
 * @throws {ContextAtlasError} when the file does not start with a `WEBVTT` line
 */
export function parseVtt(text: string): Block[] {
    const lines = normaliseCaptions(text).split('\n');

    if (!/^WEBVTT(?:[ \t][^\n]*)?$/.test(lines[0]!)) {
        throw new ContextAtlasError('A WebVTT file must start with a "WEBVTT" line.');
    }

    const captions = new Captions();

    return groupsOf(lines).slice(1).flatMap((group) => cue(captions, group));
}

/** Runs of lines between blank lines, also split before a timing line that cannot belong to the run before it. */
function groupsOf(lines: readonly string[]): string[][] {
    const groups: string[][] = [];
    let group: string[] = [];

    for (const line of lines) {
        if (trim(line) !== '') {
            if (line.includes('-->') && startsCue(group, groups.length === 0)) {
                groups.push(group);
                group = [];
            }
            group.push(line);
        } else if (group.length > 0) {
            groups.push(group);
            group = [];
        }
    }
    if (group.length > 0) {
        groups.push(group);
    }

    return groups;
}

/** Whether a line holding `-->` ends the open run: always in the header, otherwise unless the run is a single line without `-->` (a cue identifier). */
function startsCue(group: readonly string[], header: boolean): boolean {
    return group.length > 0 && (header || group.length > 1 || group[0]!.includes('-->'));
}

function cue(captions: Captions, group: readonly string[]): Block[] {
    if (/^(?:NOTE|STYLE|REGION)(?:[ \t]|$)/.test(group[0]!)) {
        return [];
    }

    const at = group[0]!.includes('-->') ? 0 : (group[1] ?? '').includes('-->') ? 1 : null;
    const timing = at === null ? null : TIMING.exec(group[at]!);

    if (at === null || timing === null) {
        return [];
    }

    const time = captionTime(timing[1] ?? null, timing[2]!, timing[3]!);
    const blocks: Block[] = [];

    for (const [speaker, raw] of voices(group.slice(at + 1).join('\n'))) {
        const lines = decode(raw.replace(/<[^>]*>/g, '')).split('\n');

        if (speaker === null) {
            blocks.push(...captions.blocks(lines, time));
        } else {
            const block = captions.utterance(squash(lines.join(' ')), time, speaker);

            if (block !== null) {
                blocks.push(block);
            }
        }
    }

    return blocks;
}

/** Cue text split at `<v>` voice spans: text before the first and after a `</v>` has no voice. */
function voices(text: string): [string | null, string][] {
    const segments: [string | null, string][] = [];
    let speaker: string | null = null;
    let at = 0;

    for (const match of text.matchAll(VOICE)) {
        segments.push([speaker, text.slice(at, match.index)]);
        at = match.index + match[0].length;
        speaker = match[0] === '</v>' ? null : squash(decode(match[1] ?? ''));
        speaker = speaker === '' ? null : speaker;
    }
    segments.push([speaker, text.slice(at)]);

    return segments;
}

function decode(text: string): string {
    return text.replace(ENTITY, (_, name: string) => {
        if (name[0] !== '#') {
            return ENTITIES[name]!;
        }

        const code = name[1] === 'x' || name[1] === 'X' ? Number.parseInt(name.slice(2), 16) : Number.parseInt(name.slice(1), 10);
        const valid = code > 0 && code <= 0x10ffff && (code < 0xd800 || code > 0xdfff);

        return valid ? String.fromCodePoint(code) : '�';
    });
}
