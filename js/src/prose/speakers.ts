import { Block, BlockType } from '../block.js';
import { breadcrumbs } from '../chunking/breadcrumbs.js';
import { length, trim, WHITESPACE } from '../text.js';

/** Words that label text instead of naming a speaker ("Note", "Example", "Step 2"). */
export const NOT_NAMES: readonly string[] = ['note', 'notes', 'tip', 'warning', 'caution', 'important', 'example', 'examples', 'answer', 'question', 'summary', 'result', 'results', 'source', 'sources', 'see', 'definition', 'update', 'edit', 'info', 'hint', 'step', 'action', 'actions', 'decision', 'resolved', 'agreed', 'outcome', 'status', 'date', 'time', 'location', 'attendees', 'present', 'apologies', 'subject', 're', 'to', 'from', 'cc', 'title', 'author', 'version', 'q', 'a', 'remarque', 'nota', 'hinweis', 'anmerkung', 'beispiel', 'exemple', 'ejemplo', 'esempio', 'réponse', 'antwort', 'respuesta', 'risposta', 'résumé', 'zusammenfassung', 'resumen', 'quelle', 'fuente', 'fonte', 'attention', 'achtung', 'atención', 'attenzione', '注', '例', '注意', '備考'];

const MIN_PARAGRAPHS = 4;

const MIN_SHARE = 0.6;

const NON_SPACE = `[^${WHITESPACE.slice(1, -1)}]`;

const JOINERS = 'of|for|the|and|de|du|des|del|della|di|da|do|dos|das|la|le|les|van|von|der|den|zu|y|e';

const NAME_WORD = "[\\p{L}\\p{M}'’.-]*";

const TIME = `(?:\\[?(\\p{Nd}{1,2}:\\p{Nd}{2}(?::\\p{Nd}{2})?)\\]?${WHITESPACE}+)?`;

const ROLE = `(?:${WHITESPACE}*[(（]([^()（）\\n]{1,80})[)）])?`;

/**
 * A capitalised name (Latin, Greek, Cyrillic...): `Name:`, `Name (role):`, `Name :`, `Name：`,
 * `M. Name. – `. PCRE with the `u` and `s` flags: Unicode `\s` and `\d`, and `.` matching a line
 * feed.
 */
const CASED = new RegExp(
    `^${TIME}(\\p{Lu}${NAME_WORD}(?:${WHITESPACE}+(?:(?:${JOINERS})${WHITESPACE}+)*\\p{Lu}${NAME_WORD}){0,3}(?:${WHITESPACE}+(?:${JOINERS})${WHITESPACE}+\\p{Ll}${NAME_WORD})?)`
    + `${ROLE}(?:${WHITESPACE}*:${WHITESPACE}+|${WHITESPACE}*：${WHITESPACE}*|\\.${WHITESPACE}+[–—-]${WHITESPACE}+)(${NON_SPACE}[\\s\\S]*)$`,
    'u',
);

/** A name in a script without letter case, before `:` or `：`, or after a `○` marker before a space. */
const UNCASED = new RegExp(
    `^${TIME}(?:[○◯◎●]${WHITESPACE}*)?(\\p{Lo}{1,16})${ROLE}(?:${WHITESPACE}*[:：]${WHITESPACE}*|(?<=\\p{Lo}|[)）])[\\u3000 ]+)(${NON_SPACE}[\\s\\S]*)$`,
    'u',
);

const UNCASED_COLON = new RegExp(
    `^(?:\\[?\\p{Nd}{1,2}:\\p{Nd}{2}(?::\\p{Nd}{2})?\\]?${WHITESPACE}+)?(?:[○◯◎●]${WHITESPACE}*)?\\p{Lo}{1,16}(?:${WHITESPACE}*[(（][^()（）\\n]{1,80}[)）])?${WHITESPACE}*[:：]`,
    'u',
);

const UNCASED_MARKER = new RegExp(`^(?:\\[?\\p{Nd}{1,2}:\\p{Nd}{2}(?::\\p{Nd}{2})?\\]?${WHITESPACE}+)?[○◯◎●]`, 'u');

interface Prefix {
    speaker: string;
    full: string;
    time: string | null;
    text: string;
}

/**
 * Finds conversations in documents whose reader cannot know who spoke: minutes, transcripts and
 * chat logs written as `Name: text`. Within a run of blocks under one heading, when enough
 * paragraphs open with a speaker, each paragraph gets `meta.speaker`, so the section becomes
 * conversational.
 *
 * A prefix is an optional time (`[10:02]` or `10:02`), then either a capitalised name of one to
 * four words (joiners such as `of`, `de` or `van` between them, and one trailing lowercase word
 * after a joiner) with an optional `(role)` and `:`, ` :`, `：` or `. – `; or a name of up to 16
 * letters in a script without case before `:` or `：`, or after a `○` marker before a space. A run
 * qualifies with at least 4 paragraphs, 60% of them prefixed, and 2 different names. A paragraph
 * without a prefix continues the speaker before it.
 */
export function inferSpeakers(blocks: readonly Block[]): Block[] {
    const crumbs = breadcrumbs(blocks);
    const runs: { key: string; blocks: number[] }[] = [];
    let current: { key: string; blocks: number[] } | null = null;

    blocks.forEach((block, i) => {
        if (block.isHeading()) {
            current = null;

            return;
        }

        const key = crumbs[i]!.path.join('\x1f');

        if (current === null || current.key !== key) {
            current = { key, blocks: [] };
            runs.push(current);
        }
        current.blocks.push(i);
    });

    const out = [...blocks];

    for (const run of runs) {
        for (const [i, block] of inRun(blocks, run.blocks)) {
            out[i] = block;
        }
    }

    return out;
}

function inRun(blocks: readonly Block[], indices: readonly number[]): Map<number, Block> {
    const paragraphs = indices.filter((i) => blocks[i]!.type === BlockType.Paragraph && trim(blocks[i]!.text) !== '');

    if (paragraphs.some((i) => blocks[i]!.meta['speaker'] !== undefined && blocks[i]!.meta['speaker'] !== null)) {
        return new Map();
    }

    const prefixes = new Map<number, Prefix>();

    for (const i of paragraphs) {
        const prefix = prefixOf(blocks[i]!.text);

        if (prefix !== null) {
            prefixes.set(i, prefix);
        }
    }

    const names = new Set([...prefixes.values()].map((prefix) => prefix.speaker));

    if (paragraphs.length < MIN_PARAGRAPHS || prefixes.size < MIN_SHARE * paragraphs.length || names.size < 2) {
        return new Map();
    }

    const out = new Map<number, Block>();
    let last: Prefix | null = null;

    for (const i of paragraphs) {
        const block = blocks[i]!;
        const prefix = prefixes.get(i) ?? null;

        if (prefix !== null) {
            last = prefix;
            out.set(i, Block.paragraph(prefix.text, {
                ...block.meta,
                speaker: prefix.speaker,
                ...(prefix.full !== prefix.speaker ? { speaker_full: prefix.full } : {}),
                ...(prefix.time !== null ? { time: prefix.time } : {}),
            }));
        } else if (last !== null) {
            out.set(i, Block.paragraph(block.text, {
                ...block.meta,
                speaker: last.speaker,
                ...(last.full !== last.speaker ? { speaker_full: last.full } : {}),
            }));
        }
    }

    return out;
}

/**
 * A name in an uncased script needs a colon, or a `○`-style marker before it when only a space
 * follows it (as in Japanese Diet records), so ordinary sentences do not read as speakers.
 */
function uncased(text: string): RegExpExecArray | null {
    const match = UNCASED.exec(text);

    return match !== null && (UNCASED_COLON.test(text) || UNCASED_MARKER.test(text)) ? match : null;
}

function prefixOf(text: string): Prefix | null {
    const trimmed = trim(text);
    const match = CASED.exec(trimmed) ?? uncased(trimmed);

    if (match === null) {
        return null;
    }

    const name = match[2]!;
    const first = name.replace(new RegExp(`${WHITESPACE}+`, 'gu'), ' ').split(' ')[0]!.toLowerCase();

    if (NOT_NAMES.includes(name.toLowerCase()) || NOT_NAMES.includes(first) || length(name) < 2 || /\p{Nd}/u.test(name)) {
        return null;
    }

    const role = match[3] !== undefined && match[3] !== '' ? trim(match[3]) : null;

    return {
        speaker: name,
        full: role === null ? name : `${name} (${role})`,
        time: match[1] !== undefined && match[1] !== '' ? match[1] : null,
        text: trim(match[4]!),
    };
}
