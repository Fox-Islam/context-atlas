/** Windows-1252 code points for bytes 0x80 to 0x9F; the undefined bytes map to the C1 controls, as PHP's mbstring maps them. */
const CP1252 = [
    0x20ac, 0x81, 0x201a, 0x192, 0x201e, 0x2026, 0x2020, 0x2021, 0x2c6, 0x2030, 0x160, 0x2039, 0x152, 0x8d, 0x17d, 0x8f,
    0x90, 0x2018, 0x2019, 0x201c, 0x201d, 0x2022, 0x2013, 0x2014, 0x2dc, 0x2122, 0x161, 0x203a, 0x153, 0x9d, 0x17e, 0x178,
];

/**
 * Bytes as UTF-8 when they are valid UTF-8, else as Windows-1252, the usual encoding of files
 * written on Windows. A byte-order mark is kept as U+FEFF.
 */
export function utf8OrWindows1252(bytes: Uint8Array): string {
    try {
        return new TextDecoder('utf-8', { fatal: true, ignoreBOM: true }).decode(bytes);
    } catch {
        return Array.from(bytes, (byte) => String.fromCodePoint(byte >= 0x80 && byte <= 0x9f ? CP1252[byte - 0x80]! : byte)).join('');
    }
}
