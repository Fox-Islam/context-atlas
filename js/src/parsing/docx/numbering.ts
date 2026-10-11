import { integer, isW, wAttribute, wChild, wValue } from './package.js';
import type { XmlElement } from './xml.js';

interface Level {
    start: number | null;
    format: string | null;
    text: string | null;
}

interface Num {
    abstract: string;
    levels: Map<number, Level>;
    starts: Map<number, number>;
}

const NOTHING: Level = { start: null, format: null, text: null };

const ROMAN: readonly [string, number][] = [['m', 1000], ['cm', 900], ['d', 500], ['cd', 400], ['c', 100], ['xc', 90], ['l', 50], ['xl', 40], ['x', 10], ['ix', 9], ['v', 5], ['iv', 4], ['i', 1]];

/**
 * List markers: each `w:num` points to an abstract numbering whose levels give a format, a start
 * value and a marker template (`%1.`); `w:lvlOverride` replaces a level or its start. Counters
 * are kept per numId (spec/formats/docx.md, Lists).
 */
export class Numbering {
    private readonly nums = new Map<string, Num>();

    private readonly abstracts = new Map<string, Map<number, Level>>();

    private readonly counters = new Map<string, Map<number, number>>();

    constructor(root: XmlElement | null) {
        for (const node of root?.children ?? []) {
            if (isW(node, 'abstractNum')) {
                const id = wAttribute(node, 'abstractNumId') ?? '';

                if (!this.abstracts.has(id)) {
                    this.abstracts.set(id, levels(node));
                }
            } else if (isW(node, 'num')) {
                const id = wAttribute(node, 'numId') ?? '';

                if (!this.nums.has(id)) {
                    this.nums.set(id, num(node));
                }
            }
        }
    }

    /** Whether a numId names a `w:num` whose abstract numbering exists. */
    has(numId: string | null): boolean {
        return numId !== null && this.nums.has(numId) && this.abstracts.has(this.nums.get(numId)!.abstract);
    }

    /** Counts one item at `ilvl` of `numId` and returns its marker. */
    marker(numId: string, ilvl: number): string {
        const counters = new Map(this.counters.get(numId) ?? []);
        counters.set(ilvl, counters.has(ilvl) ? counters.get(ilvl)! + 1 : this.start(numId, ilvl));

        for (const level of [...counters.keys()]) {
            if (level > ilvl) {
                counters.delete(level);
            }
        }
        this.counters.set(numId, counters);

        const level = this.level(numId, ilvl);

        if (level.format === 'bullet') {
            return '-';
        }

        return (level.text ?? `%${ilvl + 1}.`)
            .replace(/%([1-9])/g, (_, digit: string) => {
                const k = Number(digit) - 1;

                return format(counters.get(k) ?? this.start(numId, k), this.level(numId, k).format);
            })
            .replace(/^ +| +$/g, '');
    }

    private start(numId: string, ilvl: number): number {
        return this.nums.get(numId)?.starts.get(ilvl) ?? this.level(numId, ilvl).start ?? 0;
    }

    private level(numId: string, ilvl: number): Level {
        const found = this.nums.get(numId);

        if (found === undefined) {
            return NOTHING;
        }

        return found.levels.get(ilvl) ?? this.abstracts.get(found.abstract)?.get(ilvl) ?? NOTHING;
    }
}

function levels(parent: XmlElement): Map<number, Level> {
    const out = new Map<number, Level>();

    for (const lvl of parent.children) {
        if (isW(lvl, 'lvl')) {
            const ilvl = integer(wAttribute(lvl, 'ilvl'));

            if (ilvl !== null && ilvl >= 0 && ilvl <= 8 && !out.has(ilvl)) {
                out.set(ilvl, definition(lvl));
            }
        }
    }

    return out;
}

function definition(lvl: XmlElement): Level {
    return { start: integer(wValue(lvl, 'start')), format: wValue(lvl, 'numFmt'), text: wValue(lvl, 'lvlText') };
}

function num(element: XmlElement): Num {
    const found: Num = { abstract: wValue(element, 'abstractNumId') ?? '', levels: new Map(), starts: new Map() };

    for (const override of element.children) {
        if (!isW(override, 'lvlOverride')) {
            continue;
        }

        const ilvl = integer(wAttribute(override, 'ilvl'));

        if (ilvl === null || ilvl < 0 || ilvl > 8) {
            continue;
        }

        const start = integer(wValue(override, 'startOverride'));

        if (start !== null) {
            found.starts.set(ilvl, start);
        }

        const lvl = wChild(override, 'lvl');

        if (lvl !== null) {
            found.levels.set(ilvl, definition(lvl));
        }
    }

    return found;
}

function format(n: number, kind: string | null): string {
    switch (kind) {
        case 'bullet':
        case 'none':
            return '';
        case 'decimalZero':
            return (n >= 0 && n < 10 ? '0' : '') + String(n);
        case 'lowerLetter':
        case 'upperLetter':
            return n < 1 ? String(n) : String.fromCharCode((kind === 'upperLetter' ? 65 : 97) + ((n - 1) % 26)).repeat(Math.floor((n - 1) / 26) + 1);
        case 'lowerRoman':
        case 'upperRoman':
            return n < 1 || n > 3999 ? String(n) : roman(n, kind === 'upperRoman');
        default:
            return String(n);
    }
}

function roman(value: number, upper: boolean): string {
    let n = value;
    let out = '';

    for (const [numeral, size] of ROMAN) {
        while (n >= size) {
            out += numeral;
            n -= size;
        }
    }

    return upper ? out.toUpperCase() : out;
}
