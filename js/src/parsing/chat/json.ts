import { ContextAtlasError } from '../../errors.js';

const MAX_DEPTH = 512;

const DROPPED: readonly string[] = ['encrypted_content', 'signature'];

const SPECIAL = /["\\\x00-\x1f]/g;

const DIGITS = /[0-9]*/y;

/**
 * A decoded JSON object: its members in source order, a repeated key keeping its first place and
 * its last value.
 */
export class JsonMap {
    constructor(readonly members: ReadonlyMap<string, unknown>) {}

    get(key: string): unknown {
        return this.members.get(key) ?? null;
    }

    has(key: string): boolean {
        return this.members.has(key);
    }

    /** The member when it is a non-empty string, else null. */
    string(key: string): string | null {
        const value = this.members.get(key);

        return typeof value === 'string' && value !== '' ? value : null;
    }

    map(key: string): JsonMap | null {
        const value = this.members.get(key);

        return value instanceof JsonMap ? value : null;
    }

    list(key: string): unknown[] | null {
        const value = this.members.get(key);

        return Array.isArray(value) ? value : null;
    }
}

/** A JSON number kept as written, so re-serialising it never reformats it. */
export class JsonNumber {
    constructor(readonly raw: string) {}
}

/**
 * JSON for the chat reader, scanned by hand so a long string never meets a regex: objects decode
 * to JsonMap, numbers to JsonNumber (as written), strings to their text with an unpaired
 * surrogate escape as U+FFFD. Errors name the UTF-8 byte offset where reading stopped
 * (spec/formats/chat.md).
 *
 * @throws {ContextAtlasError} when the text is not one JSON value
 */
export function decodeChatJson(text: string): unknown {
    return new Scanner(text).document();
}

/** Whether the text is one JSON value. */
export function validChatJson(text: string): boolean {
    try {
        decodeChatJson(text);

        return true;
    } catch (error) {
        if (error instanceof ContextAtlasError) {
            return false;
        }
        throw error;
    }
}

/**
 * Compact JSON: no whitespace, members in order, numbers as written, members named
 * `encrypted_content` or `signature` left out, strings escaping only `"`, `\` and controls.
 */
export function encodeChatJson(value: unknown): string {
    if (value instanceof JsonMap) {
        const members: string[] = [];

        for (const [key, member] of value.members) {
            if (!DROPPED.includes(key)) {
                members.push(`${jsonString(key)}:${encodeChatJson(member)}`);
            }
        }

        return `{${members.join(',')}}`;
    }
    if (Array.isArray(value)) {
        return `[${value.map(encodeChatJson).join(',')}]`;
    }
    if (typeof value === 'string') {
        return jsonString(value);
    }
    if (value instanceof JsonNumber) {
        return value.raw;
    }

    return value === true ? 'true' : value === false ? 'false' : 'null';
}

function jsonString(text: string): string {
    return `"${text.replace(SPECIAL, (char) => {
        switch (char) {
            case '"':
                return '\\"';
            case '\\':
                return '\\\\';
            case '\b':
                return '\\b';
            case '\f':
                return '\\f';
            case '\n':
                return '\\n';
            case '\r':
                return '\\r';
            case '\t':
                return '\\t';
            default:
                return `\\u${char.charCodeAt(0).toString(16).padStart(4, '0')}`;
        }
    })}"`;
}

const SIMPLE: Readonly<Record<string, string>> = { '"': '"', '\\': '\\', '/': '/', b: '\b', f: '\f', n: '\n', r: '\r', t: '\t' };

class Scanner {
    private at = 0;

    constructor(private readonly text: string) {}

    document(): unknown {
        this.space();
        const value = this.value(1);
        this.space();

        if (this.at !== this.text.length) {
            this.fail();
        }

        return value;
    }

    private char(at: number = this.at): string {
        return this.text[at] ?? '';
    }

    private value(depth: number): unknown {
        const char = this.char();

        if (char === '{' || char === '[') {
            if (depth > MAX_DEPTH) {
                this.fail();
            }

            return char === '{' ? this.object(depth) : this.array(depth);
        }
        if (char === '"') {
            return this.stringValue();
        }

        return this.literal();
    }

    private object(depth: number): JsonMap {
        this.at++;
        this.space();
        const members = new Map<string, unknown>();

        if (this.char() === '}') {
            this.at++;

            return new JsonMap(members);
        }

        for (;;) {
            this.space();
            if (this.char() !== '"') {
                this.fail();
            }

            const key = this.stringValue();
            this.space();
            this.expect(':');
            this.space();
            members.set(key, this.value(depth + 1));
            this.space();

            if (this.char() !== ',') {
                this.expect('}');

                return new JsonMap(members);
            }
            this.at++;
        }
    }

    private array(depth: number): unknown[] {
        this.at++;
        this.space();
        const items: unknown[] = [];

        if (this.char() === ']') {
            this.at++;

            return items;
        }

        for (;;) {
            this.space();
            items.push(this.value(depth + 1));
            this.space();

            if (this.char() !== ',') {
                this.expect(']');

                return items;
            }
            this.at++;
        }
    }

    private literal(): unknown {
        const start = this.at;

        for (const [word, value] of [['true', true], ['false', false], ['null', null]] as const) {
            if (this.text.startsWith(word, start)) {
                this.at += word.length;

                return value;
            }
        }

        const end = this.numberEnd(start);

        if (end === null) {
            this.fail();
        }
        this.at = end;

        return new JsonNumber(this.text.slice(start, end));
    }

    /** The decoded string opening at the cursor; the cursor moves past its closing quote. */
    private stringValue(): string {
        const start = this.at;
        let i = start + 1;
        let out = '';

        while (i < this.text.length) {
            SPECIAL.lastIndex = i;
            const found = SPECIAL.exec(this.text);
            const stop = found === null ? this.text.length : found.index;
            out += this.text.slice(i, stop);
            i = stop;

            const char = this.char(i);

            if (char === '"') {
                this.at = i + 1;

                return out;
            }
            if (char !== '\\') {
                break;
            }

            const escape = this.escape(i);

            if (escape === null) {
                break;
            }
            out += escape[0];
            i = escape[1];
        }

        this.at = start;
        this.fail();
    }

    /** The text of the escape at `at` and the offset after it, or null when it is not one. */
    private escape(at: number): [string, number] | null {
        const next = this.char(at + 1);

        if (Object.hasOwn(SIMPLE, next)) {
            return [SIMPLE[next]!, at + 2];
        }

        const code = this.hex(at);

        if (code === null) {
            return null;
        }
        if (code >= 0xd800 && code <= 0xdbff) {
            const low = this.hex(at + 6);

            if (low !== null && low >= 0xdc00 && low <= 0xdfff) {
                return [String.fromCharCode(code, low), at + 12];
            }
        }

        return [code >= 0xd800 && code <= 0xdfff ? '�' : String.fromCharCode(code), at + 6];
    }

    /** The code unit of the `\uXXXX` escape at `at`, or null when there is none. */
    private hex(at: number): number | null {
        const digits = this.text.slice(at + 2, at + 6);

        if (this.char(at) !== '\\' || this.char(at + 1) !== 'u' || !/^[0-9a-fA-F]{4}$/.test(digits)) {
            return null;
        }

        return Number.parseInt(digits, 16);
    }

    /** The offset after the number starting at `at` (the JsonOutline grammar), or null. */
    private numberEnd(at: number): number | null {
        let i = at + (this.char(at) === '-' ? 1 : 0);
        const first = this.char(i);

        if (first === '0') {
            i++;
        } else if (first >= '1' && first <= '9') {
            i += this.digits(i);
        } else {
            return null;
        }
        if (this.char(i) === '.' && this.digits(i + 1) > 0) {
            i += 1 + this.digits(i + 1);
        }
        if (this.char(i) === 'e' || this.char(i) === 'E') {
            const sign = this.char(i + 1) === '+' || this.char(i + 1) === '-' ? 1 : 0;
            const digits = this.digits(i + 1 + sign);

            if (digits > 0) {
                i += 1 + sign + digits;
            }
        }

        return i;
    }

    private digits(at: number): number {
        DIGITS.lastIndex = at;

        return DIGITS.exec(this.text)?.[0].length ?? 0;
    }

    private space(): void {
        while (this.at < this.text.length && ' \t\r\n'.includes(this.text[this.at]!)) {
            this.at++;
        }
    }

    private expect(char: string): void {
        if (this.char() !== char) {
            this.fail();
        }
        this.at++;
    }

    private fail(): never {
        throw new ContextAtlasError(`Invalid JSON at byte ${Buffer.byteLength(this.text.slice(0, this.at), 'utf8')}.`);
    }
}
