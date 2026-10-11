import { phpString } from '../support/php.js';

/** A cleaned cell: a number, text (an ISO date included), or blank. */
export type Value = number | string | null;

/**
 * Whole numbers plain below 10,000, so years read as years, and with thousands separators from
 * 10,000; other numbers to at most four decimal places with trailing zeros dropped. `(none)`
 * for null, and text as written.
 */
export function formatNumber(value: Value): string {
    if (value === null) {
        return '(none)';
    }
    if (typeof value === 'string') {
        return value;
    }

    return written(value, Math.abs(value) >= 10000 ? ',' : '');
}

/**
 * The string a cell value is matched by in filters and grouping: a number without separators,
 * so an identifier such as 2000034784 matches as a question writes it.
 */
export function numberKey(value: unknown): string {
    return typeof value === 'number' ? written(value, '') : phpString(value);
}

function written(value: number, separator: string): string {
    if (Math.floor(value) === value && Math.abs(value) < 1e15) {
        return numberFormat(value, 0, separator);
    }

    return numberFormat(value, 4, separator).replace(/0+$/, '').replace(/\.$/, '');
}

/**
 * PHP 8.3's `number_format()`: rounded half away from zero after rounding to 15 significant
 * digits, with no minus sign on a result that rounds to zero.
 */
function numberFormat(value: number, places: number, separator: string): string {
    const fixed = rounded(Math.abs(value), places);
    const [whole = '0', fraction] = fixed.split('.');
    const negative = value < 0 && /[1-9]/.test(fixed);
    const grouped = separator === '' ? whole : whole.replace(/\B(?=(\d{3})+(?!\d))/g, separator);

    return (negative ? '-' : '') + grouped + (fraction === undefined ? '' : `.${fraction}`);
}

/** `value` (not negative) to exactly `places` decimals, as PHP's `_php_math_round` rounds it. */
function rounded(value: number, places: number): string {
    if (value === 0 || !Number.isFinite(value)) {
        return (0).toFixed(places);
    }

    const precisionPlaces = 14 - Math.floor(Math.log10(value));

    if (precisionPlaces > places && precisionPlaces - 15 < places) {
        return roundDecimal(plain(value.toPrecision(15)), places);
    }

    const scaled = value * 10 ** places;

    if (scaled >= 1e15) {
        return value.toFixed(places);
    }

    return (Math.floor(scaled + 0.5) / 10 ** places).toFixed(places);
}

/** A `toPrecision` result without an exponent. */
function plain(text: string): string {
    const [mantissa = '0', exponent] = text.split('e');

    if (exponent === undefined) {
        return mantissa;
    }

    const digits = mantissa.replace('.', '');
    const point = (mantissa.indexOf('.') === -1 ? mantissa.length : mantissa.indexOf('.')) + Number(exponent);

    if (point <= 0) {
        return `0.${'0'.repeat(-point)}${digits}`;
    }
    if (point >= digits.length) {
        return digits + '0'.repeat(point - digits.length);
    }

    return `${digits.slice(0, point)}.${digits.slice(point)}`;
}

/** A plain decimal string rounded half up to `places` decimals. */
function roundDecimal(decimal: string, places: number): string {
    const [whole = '0', fraction = ''] = decimal.split('.');
    const padded = fraction.padEnd(places + 1, '0');
    let digits = whole + padded.slice(0, places);

    if (padded[places]! >= '5') {
        const carried = (BigInt(digits) + 1n).toString();
        digits = carried.padStart(digits.length, '0');
    }

    const integer = digits.slice(0, digits.length - places) || '0';

    return places === 0 ? integer : `${integer}.${digits.slice(digits.length - places)}`;
}
