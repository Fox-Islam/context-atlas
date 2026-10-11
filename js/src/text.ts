/**
 * The text rules spec/behaviour.md states for every implementation. Lengths
 * are Unicode code points, never UTF-16 units.
 *
 * Whitespace is the set spec/behaviour.md lists, which is what PHP's PCRE2
 * matches with `\s` and the `u` flag. JavaScript's own `\s` adds U+FEFF and
 * leaves out U+0085 and U+180E, so it is not used.
 */
export const WHITESPACE = '[\\t-\\r \\u0085\\u00a0\\u1680\\u180e\\u2000-\\u200a\\u2028\\u2029\\u202f\\u205f\\u3000]';

const SENTENCE_END = `(?<=[.?!…])${WHITESPACE}+`;

const SENTENCE_SPLIT = new RegExp(`${SENTENCE_END}(?=\\p{Lu})`, 'u');

const TAIL_SENTENCE = new RegExp(`${SENTENCE_END}(?=[\\p{Lu}\\p{Lt}])`, 'u');

const SPACE_RUN = new RegExp(`${WHITESPACE}+`, 'u');

const SPACE_RUNS = new RegExp(`${WHITESPACE}+`, 'gu');

const EDGES = new RegExp(`^${WHITESPACE}+|${WHITESPACE}+$`, 'gu');

const MARKS = /\p{Mn}+/gu;

const WIDE = /[\p{scx=Han}\p{scx=Hiragana}\p{scx=Katakana}\p{scx=Hangul}]/gu;

const WIDE_ONE = /^[\p{scx=Han}\p{scx=Hiragana}\p{scx=Katakana}\p{scx=Hangul}]$/u;

export function length(text: string): number {
    let count = 0;

    for (const _ of text) {
        count++;
    }

    return count;
}

/**
 * Han, Hiragana, Katakana and Hangul characters count one token each; the rest `charsPerToken`
 * code points to a token. The scripts are matched by Script_Extensions, as PCRE2 matches
 * `\p{Han}`, so marks shared between them, such as `ー` and `、`, count as wide.
 */
export function tokens(text: string, charsPerToken: number): number {
    const wide = text.match(WIDE)?.length ?? 0;

    return Math.ceil((length(text) - wide) / Math.max(1, charsPerToken) + wide);
}

/** Whether a code point counts as a whole token. */
export function isWide(char: string): boolean {
    return WIDE_ONE.test(char);
}

export function trim(text: string): string {
    return text.replace(EDGES, '');
}

export function squash(text: string): string {
    return trim(text).replace(SPACE_RUNS, ' ');
}

export function sentences(text: string): string[] {
    return trim(text).split(SENTENCE_SPLIT);
}

/**
 * The last `count` tokens of a text, opened at the first sentence that starts
 * inside them, else after the first run of whitespace, so an overlap never
 * opens mid-word.
 */
export function tail(text: string, count: number, charsPerToken: number): string {
    const chars = Math.max(0, count * charsPerToken);

    if (length(text) <= chars) {
        return text;
    }

    const slice = last(text, chars);
    const match = TAIL_SENTENCE.exec(slice) ?? SPACE_RUN.exec(slice);

    return match === null ? slice : slice.slice(match.index + match[0].length);
}

export function head(text: string, chars: number): string {
    return Array.from(trim(text)).slice(0, Math.max(0, chars)).join('');
}

export function end(text: string, chars: number): string {
    const trimmed = trim(text);

    return length(trimmed) <= chars ? trimmed : last(trimmed, chars);
}

/**
 * Lowercase ASCII letters and digits joined by hyphens, accents dropped, cut
 * to `chars` code points.
 */
export function slug(text: string, chars: number): string {
    const plain = text.normalize('NFKD').replace(MARKS, '').toLowerCase();
    const joined = plain.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

    return Array.from(joined).slice(0, chars).join('').replace(/-+$/, '');
}

/** The last `chars` code points, or the whole text when `chars` is not positive. */
function last(text: string, chars: number): string {
    if (chars <= 0) {
        return text;
    }

    const points = Array.from(text);

    return points.slice(Math.max(0, points.length - chars)).join('');
}
