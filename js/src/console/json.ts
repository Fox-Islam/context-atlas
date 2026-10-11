/** A number written with a fraction even when it is whole, as PHP's `JSON_PRESERVE_ZERO_FRACTION` writes a float. */
export class JsonFloat {
    constructor(readonly value: number) {}
}

/**
 * JSON as the PHP package prints it: Unicode and slashes unescaped, U+2028 and
 * U+2029 escaped, a float keeping its `.0`, and `pretty` indenting by four
 * spaces.
 */
export function encode(value: unknown, pretty: boolean): string {
    return write(value, pretty ? '\n' : '');
}

function write(value: unknown, newline: string): string {
    if (value instanceof JsonFloat) {
        return float(value.value);
    }
    if (typeof value === 'string') {
        return JSON.stringify(value).replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');
    }
    if (value === null || typeof value !== 'object') {
        return JSON.stringify(value) ?? 'null';
    }

    const inner = newline === '' ? '' : `${newline}    `;
    const items = Array.isArray(value)
        ? value.map((item) => write(item, inner))
        : Object.entries(value).map(([key, item]) => `${write(key, inner)}:${newline === '' ? '' : ' '}${write(item, inner)}`);
    const [open, close] = Array.isArray(value) ? ['[', ']'] : ['{', '}'];

    if (items.length === 0) {
        return open + close;
    }

    return open + inner + items.join(`,${inner}`) + newline + close;
}

/**
 * PHP's float form: shortest round-trip digits, always a fraction, and an
 * exponent below 1e-4 or from 1e17, where JavaScript switches at 1e-6 and 1e21.
 */
function float(value: number): string {
    if (!Number.isFinite(value)) {
        return '0';
    }

    const size = Math.abs(value);
    const digits = size !== 0 && (size < 1e-4 || size >= 1e17) ? value.toExponential() : String(value);
    const [mantissa = '', exponent] = digits.split('e');
    const withFraction = mantissa.includes('.') ? mantissa : `${mantissa}.0`;

    return exponent === undefined ? withFraction : `${withFraction}e${exponent}`;
}
