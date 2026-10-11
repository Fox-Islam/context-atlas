import { ContextAtlasError } from '../errors.js';
import type { Outline, OutlineEntry } from './outline.js';

const LONE_SURROGATE = /[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/;

/**
 * The key tree of a JSON file, printed in one fixed layout so that minified and pretty-printed
 * files cut the same way: two-space indents, `"key": value`, an empty object or array as `{}`
 * or `[]`. Strings and numbers are printed as written in the source, so no language reformats
 * a number.
 *
 * @throws {ContextAtlasError} on invalid JSON, naming the UTF-8 byte offset
 */
export function readJson(text: string): Outline {
    return new JsonReader(text).read();
}

class JsonReader {
    private at = 0;

    private readonly lines: string[] = [];

    private readonly entries: OutlineEntry[] = [];

    constructor(private readonly text: string) {}

    read(): Outline {
        this.space();
        this.value([], 0, '');
        this.space();
        if (this.at !== this.text.length) {
            this.fail();
        }

        return { lines: this.lines, entries: this.entries, anchors: new Map() };
    }

    /** Prints one value on a new line after `lead` and records its entry. */
    private value(path: string[], depth: number, lead: string): void {
        const entry: OutlineEntry = { path, start: this.lines.length, end: 0, value: null, alias: null };
        this.entries.push(entry);

        const pad = '  '.repeat(depth);
        const char = this.text[this.at] ?? '';

        if (char === '{' || char === '[') {
            const close = char === '{' ? '}' : ']';
            this.at++;
            this.space();
            if ((this.text[this.at] ?? '') === close) {
                this.at++;
                this.lines.push(pad + lead + char + close);
            } else {
                this.lines.push(pad + lead + char);
                this.members(path, depth + 1, char === '{');
                this.lines.push(pad + close);
            }
        } else {
            const raw = this.scalar();
            this.lines.push(pad + lead + raw);
            entry.value = raw.startsWith('"') ? decodeString(raw) : raw;
        }

        entry.end = this.lines.length;
    }

    private members(path: string[], depth: number, object: boolean): void {
        const close = object ? '}' : ']';

        for (let i = 0; ; i++) {
            this.space();

            let lead = '';
            let segment = `[${i}]`;

            if (object) {
                const key = this.scalar();

                if (!key.startsWith('"')) {
                    this.fail();
                }
                segment = decodeString(key);
                lead = `${key}: `;
                this.space();
                this.expect(':');
                this.space();
            }
            this.value([...path, segment], depth, lead);
            this.space();
            if ((this.text[this.at] ?? '') !== ',') {
                this.expect(close);

                return;
            }
            this.lines.push(`${this.lines.pop()!},`);
            this.at++;
        }
    }

    /** The raw text of a string, number, `true`, `false` or `null`, scanned character by character. */
    private scalar(): string {
        const start = this.at;
        const char = this.text[start] ?? '';
        let end: number | null;

        if (char === '"') {
            end = this.stringEnd(start);
        } else if (char === 't' || char === 'f' || char === 'n') {
            const word = char === 't' ? 'true' : char === 'f' ? 'false' : 'null';
            end = this.text.startsWith(word, start) ? start + word.length : null;
        } else {
            end = this.numberEnd(start);
        }
        if (end === null) {
            this.fail();
        }
        this.at = end;

        return this.text.slice(start, end);
    }

    /** The offset after the closing quote of the string opening at `at`, or null when it is not an RFC 8259 string. */
    private stringEnd(at: number): number | null {
        let i = at + 1;

        while (i < this.text.length) {
            const char = this.text[i]!;

            if (char === '"') {
                return i + 1;
            }
            if (char.charCodeAt(0) < 0x20) {
                return null;
            }
            if (char !== '\\') {
                i++;
                continue;
            }

            const next = this.text[i + 1] ?? '';

            if (next === 'u') {
                if (!/^[0-9a-fA-F]{4}$/.test(this.text.slice(i + 2, i + 6))) {
                    return null;
                }
                i += 6;
            } else if (next !== '' && '"\\/bfnrt'.includes(next)) {
                i += 2;
            } else {
                return null;
            }
        }

        return null;
    }

    /** The offset after the number starting at `at`, taking the fraction and exponent only when digits follow, or null when none starts there. */
    private numberEnd(at: number): number | null {
        const digits = (from: number): number => {
            let n = 0;

            while (/[0-9]/.test(this.text[from + n] ?? '')) {
                n++;
            }

            return n;
        };
        let i = at + (this.text[at] === '-' ? 1 : 0);
        const first = this.text[i] ?? '';

        if (first === '0') {
            i++;
        } else if (first >= '1' && first <= '9') {
            i += digits(i);
        } else {
            return null;
        }
        if (this.text[i] === '.' && digits(i + 1) > 0) {
            i += 1 + digits(i + 1);
        }
        if (this.text[i] === 'e' || this.text[i] === 'E') {
            const sign = this.text[i + 1] === '+' || this.text[i + 1] === '-' ? 1 : 0;
            const count = digits(i + 1 + sign);

            if (count > 0) {
                i += 1 + sign + count;
            }
        }

        return i;
    }

    private space(): void {
        while (this.at < this.text.length && ' \t\r\n'.includes(this.text[this.at]!)) {
            this.at++;
        }
    }

    private expect(char: string): void {
        if ((this.text[this.at] ?? '') !== char) {
            this.fail();
        }
        this.at++;
    }

    private fail(): never {
        throw new ContextAtlasError(`Invalid JSON at byte ${Buffer.byteLength(this.text.slice(0, this.at), 'utf8')}.`);
    }
}

/** A string's decoded text; PHP's `json_decode` refuses an unpaired surrogate escape, which reads as empty. */
function decodeString(raw: string): string {
    const decoded = JSON.parse(raw) as string;

    return LONE_SURROGATE.test(decoded) ? '' : decoded;
}
