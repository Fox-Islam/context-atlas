/**
 * PHP behaviour the PHP package relies on: casts, comparisons, natural order and
 * trimming. Values read, compare, sort and print the same in both packages.
 */

/** PHP's `(string)` on a decoded JSON value. */
export function phpString(value: unknown): string {
    if (typeof value === 'string') {
        return value;
    }
    if (typeof value === 'number') {
        return String(value);
    }
    if (typeof value === 'boolean') {
        return value ? '1' : '';
    }
    if (value === null || value === undefined) {
        return '';
    }

    return 'Array';
}

/** PHP's `(array)` on a decoded JSON value, as key-value entries. */
export function phpArray(value: unknown): [string, unknown][] {
    if (Array.isArray(value)) {
        return value.map((item, index) => [String(index), item]);
    }
    if (typeof value === 'object' && value !== null) {
        return Object.entries(value);
    }

    return [['0', value]];
}

const PHP_TRIM = /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g;

/** PHP's `trim()` with its default characters: space, tab, line feed, carriage return, NUL and vertical tab. */
export function phpTrim(text: string): string {
    return text.replace(PHP_TRIM, '');
}

const NUMERIC = /^[ \t\n\r\v\f]*[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?[ \t\n\r\v\f]*$/;

const LEADING_NUMBER = /^[ \t\n\r\v\f]*[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/;

/** PHP's `is_numeric()` for a string or a number. */
export function isNumeric(value: unknown): boolean {
    return typeof value === 'number' || (typeof value === 'string' && NUMERIC.test(value));
}

/** PHP's `(float)` on a string: its leading number, or 0. */
export function phpFloat(text: string): number {
    const match = LEADING_NUMBER.exec(text);

    return match === null ? 0 : Number(match[0]);
}

/**
 * PHP's `(string)` on a float, which writes 14 significant digits and switches to `1.0E+25`
 * form when the decimal point would fall more than 14 digits in or 4 zeros out.
 */
export function phpFloatString(value: number): string {
    if (value === 0) {
        return Object.is(value, -0) ? '-0' : '0';
    }
    if (!Number.isFinite(value)) {
        return Number.isNaN(value) ? 'NAN' : value > 0 ? 'INF' : '-INF';
    }

    const [mantissa = '', exponent = '0'] = Math.abs(value).toExponential(13).split('e');
    const digits = mantissa.replace('.', '').replace(/0+$/, '') || '0';
    const decpt = Number(exponent) + 1;
    const sign = value < 0 ? '-' : '';

    if (decpt < -3 || decpt > 14) {
        const power = decpt - 1;

        return `${sign}${digits[0]}.${digits.length > 1 ? digits.slice(1) : '0'}E${power < 0 ? '-' : '+'}${Math.abs(power)}`;
    }
    if (decpt <= 0) {
        return `${sign}0.${'0'.repeat(-decpt)}${digits}`;
    }
    if (digits.length <= decpt) {
        return sign + digits + '0'.repeat(decpt - digits.length);
    }

    return `${sign}${digits.slice(0, decpt)}.${digits.slice(decpt)}`;
}

/** PHP's `(string)` on a cell value: a float as PHP writes it, null as empty. */
export function phpValueString(value: unknown): string {
    return typeof value === 'number' ? phpFloatString(value) : phpString(value);
}

/**
 * PHP 8's `<=>` on two cell values (floats and strings): numerically when both are numbers
 * or numeric strings, otherwise as byte strings, a float written as PHP writes it.
 */
export function phpCompare(a: number | string, b: number | string): number {
    if (isNumeric(a) && isNumeric(b)) {
        const x = typeof a === 'number' ? a : phpFloat(a);
        const y = typeof b === 'number' ? b : phpFloat(b);

        return x < y ? -1 : x > y ? 1 : 0;
    }

    return byteCompare(typeof a === 'number' ? phpFloatString(a) : a, typeof b === 'number' ? phpFloatString(b) : b);
}

/** `strcmp`: the order of the UTF-8 bytes, which is the order of the code points. */
export function byteCompare(a: string, b: string): number {
    const x = Array.from(a, (c) => c.codePointAt(0)!);
    const y = Array.from(b, (c) => c.codePointAt(0)!);

    for (let i = 0; i < Math.min(x.length, y.length); i++) {
        if (x[i] !== y[i]) {
            return x[i]! < y[i]! ? -1 : 1;
        }
    }

    return x.length === y.length ? 0 : x.length < y.length ? -1 : 1;
}

/** PHP's `min()` over values compared with `<=>`: the first of the lowest. */
export function phpMin<T extends number | string>(values: readonly T[]): T | undefined {
    return values.reduce<T | undefined>((best, value) => (best === undefined || phpCompare(best, value) > 0 ? value : best), undefined);
}

/** PHP's `max()` over values compared with `<=>`: the first of the highest. */
export function phpMax<T extends number | string>(values: readonly T[]): T | undefined {
    return values.reduce<T | undefined>((best, value) => (best === undefined || phpCompare(best, value) < 0 ? value : best), undefined);
}

const isDigit = (byte: number | undefined): boolean => byte !== undefined && byte >= 0x30 && byte <= 0x39;

const isSpace = (byte: number | undefined): boolean => byte !== undefined && (byte === 0x20 || (byte >= 0x09 && byte <= 0x0d));

/** PHP's `strnatcmp()`, over UTF-8 bytes: runs of digits compare by value, a run with a leading zero digit by digit. */
export function strnatcmp(left: string, right: string): number {
    const a = Buffer.from(left, 'utf8');
    const b = Buffer.from(right, 'utf8');

    if (a.length === 0 || b.length === 0) {
        return a.length === b.length ? 0 : a.length > b.length ? 1 : -1;
    }

    let i = 0;
    let j = 0;
    let leading = true;

    for (;;) {
        let ca = a[i] ?? 0;
        let cb = b[j] ?? 0;

        while (leading && ca === 0x30 && i + 1 < a.length && isDigit(a[i + 1])) {
            ca = a[++i] ?? 0;
        }
        while (leading && cb === 0x30 && j + 1 < b.length && isDigit(b[j + 1])) {
            cb = b[++j] ?? 0;
        }
        leading = false;

        while (isSpace(ca)) {
            ca = a[++i] ?? 0;
        }
        while (isSpace(cb)) {
            cb = b[++j] ?? 0;
        }

        if (isDigit(ca) && isDigit(cb)) {
            const fractional = ca === 0x30 || cb === 0x30;
            const [result, nextI, nextJ] = fractional ? compareLeft(a, i, b, j) : compareRight(a, i, b, j);
            i = nextI;
            j = nextJ;

            if (result !== 0) {
                return result;
            }
            if (i === a.length && j === b.length) {
                return 0;
            }
            if (i === a.length) {
                return -1;
            }
            if (j === b.length) {
                return 1;
            }
            ca = a[i] ?? 0;
            cb = b[j] ?? 0;
        }

        if (ca < cb) {
            return -1;
        }
        if (ca > cb) {
            return 1;
        }

        i++;
        j++;

        if (i >= a.length && j >= b.length) {
            return 0;
        }
        if (i >= a.length) {
            return -1;
        }
        if (j >= b.length) {
            return 1;
        }
    }
}

function compareRight(a: Buffer, i: number, b: Buffer, j: number): [number, number, number] {
    let bias = 0;

    for (;; i++, j++) {
        const endA = i === a.length || !isDigit(a[i]);
        const endB = j === b.length || !isDigit(b[j]);

        if (endA && endB) {
            return [bias, i, j];
        }
        if (endA) {
            return [-1, i, j];
        }
        if (endB) {
            return [1, i, j];
        }
        if (a[i]! < b[j]! && bias === 0) {
            bias = -1;
        } else if (a[i]! > b[j]! && bias === 0) {
            bias = 1;
        }
    }
}

function compareLeft(a: Buffer, i: number, b: Buffer, j: number): [number, number, number] {
    for (;; i++, j++) {
        const endA = i === a.length || !isDigit(a[i]);
        const endB = j === b.length || !isDigit(b[j]);

        if (endA && endB) {
            return [0, i, j];
        }
        if (endA) {
            return [-1, i, j];
        }
        if (endB) {
            return [1, i, j];
        }
        if (a[i]! < b[j]!) {
            return [-1, i, j];
        }
        if (a[i]! > b[j]!) {
            return [1, i, j];
        }
    }
}

/** A stable sort, highest first, as PHP's `arsort` orders values. */
export function sortDescending<T>(items: readonly T[], score: (item: T) => number): T[] {
    return [...items].sort((x, y) => score(y) - score(x));
}
