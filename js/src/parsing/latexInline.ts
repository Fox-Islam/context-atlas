import { squash, trim } from '../text.js';

/**
 * Turns LaTeX inline markup into plain text: formatting commands unwrapped, citations and
 * references kept as their keys, accents and symbols as characters, inline maths kept as
 * written (spec/formats/latex.md). Every character it reads is ASCII, so other text passes
 * through unchanged.
 */

const UNWRAP: ReadonlySet<string> = new Set(['textbf', 'textit', 'emph', 'texttt', 'underline', 'textsc', 'textrm', 'textsf', 'textsl', 'textup', 'textmd', 'textnormal', 'mbox', 'text', 'uline']);

const CITE: ReadonlySet<string> = new Set(['cite', 'citep', 'citet', 'citealp', 'citealt', 'citeauthor', 'citeyear', 'parencite', 'textcite', 'autocite', 'footcite', 'Cite', 'Citep', 'Citet']);

const REF: ReadonlySet<string> = new Set(['ref', 'eqref', 'autoref', 'cref', 'Cref', 'pageref', 'nameref', 'vref']);

const DROP: ReadonlySet<string> = new Set([
    'begin', 'end', 'label', 'nocite', 'documentclass', 'usepackage', 'RequirePackage', 'newcommand', 'renewcommand', 'providecommand',
    'newenvironment', 'renewenvironment', 'newtheorem', 'theoremstyle', 'setlength', 'addtolength', 'setcounter', 'addtocounter',
    'includegraphics', 'input', 'include', 'bibliography', 'bibliographystyle', 'vspace', 'hspace', 'pagestyle', 'thispagestyle',
    'pagenumbering', 'author', 'date', 'thanks', 'affil', 'affiliation', 'address', 'email', 'institute', 'index', 'graphicspath',
    'hypersetup', 'lstset', 'definecolor', 'color', 'geometry', 'newgeometry', 'captionsetup', 'numberwithin', 'lstinputlisting', 'inputminted',
    'fontfamily', 'fontseries', 'fontshape', 'fontsize', 'usetikzlibrary', 'setmainfont', 'setsansfont', 'setmonofont', 'setfloatalignment',
]);

const SYMBOLS: Readonly<Record<string, string>> = {
    LaTeX: 'LaTeX', TeX: 'TeX', LaTeXe: 'LaTeX2e', ldots: '…', dots: '…', textellipsis: '…',
    textendash: '–', textemdash: '—', S: '§', P: '¶', copyright: '©', textcopyright: '©',
    textregistered: '®', texttrademark: '™', pounds: '£', textsterling: '£', euro: '€', texteuro: '€',
    textbackslash: '\\', textasciitilde: '~', textasciicircum: '^', textless: '<', textgreater: '>',
    textbar: '|', textunderscore: '_', slash: '/', quad: ' ', qquad: ' ', newline: ' ', linebreak: ' ',
    textquoteleft: "'", textquoteright: "'", textquotedblleft: '"', textquotedblright: '"', textbullet: '•',
    textdegree: '°', dag: '†', ddag: '‡', i: 'ı', j: 'ȷ', ss: 'ß', o: 'ø', O: 'Ø', ae: 'æ',
    AE: 'Æ', oe: 'œ', OE: 'Œ', aa: 'å', AA: 'Å', l: 'ł', L: 'Ł',
};

const ACCENTS: Readonly<Record<string, string>> = { "'": '́', '`': '̀', '^': '̂', '"': '̈', '~': '̃', '=': '̄', '.': '̇' };

const LETTER_ACCENTS: Readonly<Record<string, string>> = { c: '̧', v: '̌', u: '̆', H: '̋', k: '̨', r: '̊' };

const SPACES: Readonly<Record<string, string>> = { ' ': ' ', '\n': ' ', '\t': ' ', ',': ' ', ';': ' ', ':': ' ', '>': ' ', '!': '', '-': '', '/': '', '@': '' };

/** Brace arguments of each quantity command. */
const QUANTITIES: Readonly<Record<string, number>> = { num: 1, numrange: 2, qty: 2, SI: 2, qtyrange: 3, SIrange: 3, unit: 1, si: 1 };

const FRACTIONS: ReadonlySet<string> = new Set(['frac', 'tfrac', 'dfrac', 'nicefrac', 'sfrac', 'xfrac']);

/** Marks a fraction's text, so a digit before it gets a space: `1\frac{1}{2}` reads `1 1/2`. */
const FRACTION = '\x03';

const SI_PREFIXES: Readonly<Record<string, string>> = {
    yocto: 'y', zepto: 'z', atto: 'a', femto: 'f', pico: 'p', nano: 'n', micro: 'µ', milli: 'm',
    centi: 'c', deci: 'd', deca: 'da', deka: 'da', hecto: 'h', kilo: 'k', mega: 'M', giga: 'G',
    tera: 'T', peta: 'P', exa: 'E', zetta: 'Z', yotta: 'Y',
};

const SI_UNITS: Readonly<Record<string, string>> = {
    metre: 'm', meter: 'm', gram: 'g', kilogram: 'kg', second: 's', ampere: 'A', kelvin: 'K',
    mole: 'mol', candela: 'cd', hertz: 'Hz', newton: 'N', pascal: 'Pa', joule: 'J', watt: 'W',
    volt: 'V', ohm: 'Ω', coulomb: 'C', farad: 'F', henry: 'H', tesla: 'T', weber: 'Wb',
    siemens: 'S', becquerel: 'Bq', gray: 'Gy', sievert: 'Sv', lumen: 'lm', lux: 'lx',
    radian: 'rad', steradian: 'sr', litre: 'L', liter: 'L', tonne: 't', hour: 'h', minute: 'min',
    day: 'd', hectare: 'ha', electronvolt: 'eV', degreeCelsius: '°C', degree: '°', arcminute: '′',
    arcsecond: '″', percent: '%', bel: 'B', decibel: 'dB', neper: 'Np', bit: 'bit', byte: 'B',
    angstrom: 'Å', astronomicalunit: 'au', dalton: 'Da', bar: 'bar',
};

/** Wraps an unknown command's leftover argument, so `paragraph` can tell a paragraph made only of them. */
const OPEN = '\x01';

const CLOSE = '\x02';

const MARKERS = /[\x01\x02]/g;

/** PCRE without the `u` flag: `$` also matches before a final line feed. */
const DIMENSION = /^[ \t\n]*[-+]?(?:\d+(?:\.\d+)?|\.\d+)[ \t\n]*(?:pt|em|ex|in|cm|mm|bp|pc|sp|mu|px|\\[A-Za-z]+)[ \t\n]*$/;

/** A `\verb` delimiter is one character; an astral one is its surrogate pair. */
const VERB = /\\[vV]erb\*?((?:[^A-Za-z \t\n*\uD800-\uDBFF]|[\uD800-\uDBFF][\uDC00-\uDFFF]))/y;

type Result = [string, number];

const has = (table: Readonly<Record<string, string>>, key: string): boolean => Object.hasOwn(table, key);

export function convert(text: string): string {
    return marked(text).replace(MARKERS, '');
}

/**
 * A paragraph's text, or `''` when it holds nothing but leftover arguments of unknown commands,
 * each one short token of letters, digits, `.`, `_` or `-` (`\pgfsetlayers{main}`).
 */
export function paragraph(text: string): string {
    const marks = marked(text);
    const spans = [...marks.matchAll(/\x01([^\x01\x02]*)\x02/g)].map((match) => match[1]!);
    const outside = squash(marks.replace(/\x01[^\x01\x02]*\x02/g, ''));
    const words = spans.filter((span) => trim(span) !== '');
    const single = words.filter((span) => /^[A-Za-z0-9._-]{1,12}$/.test(trim(span)));

    return outside === '' && words.length > 0 && single.length === words.length ? '' : marks.replace(MARKERS, '');
}

/** Converts, wrapping each unknown command's leftover argument in OPEN and CLOSE. */
function marked(text: string): string {
    let out = '';
    let i = 0;

    while (i < text.length) {
        const c = text[i]!;
        let piece: string;

        if (c === '\\') {
            [piece, i] = command(text, i);
            if (piece.startsWith(FRACTION)) {
                piece = (/[0-9]\n?$/.test(out) ? ' ' : '') + piece.slice(1);
            }
        } else if (c === '{') {
            let inner: string;
            [inner, i] = group(text, i);
            piece = convert(inner);
        } else if (c === '$') {
            [piece, i] = math(text, i);
        } else {
            [piece, i] = character(text, i);
        }
        out += piece;
    }

    return out;
}

/** The offset after spaces and tabs and, when allowed, one line break with the spaces after it. */
export function space(text: string, at: number, newline = true): number {
    while (at < text.length && (text[at] === ' ' || text[at] === '\t')) {
        at++;
    }
    if (newline && at < text.length && text[at] === '\n') {
        at++;
        while (at < text.length && (text[at] === ' ' || text[at] === '\t')) {
            at++;
        }
    }

    return at;
}

/** The content of the brace group opening at `at` and the offset after its closing brace; an unclosed group runs to the end. */
export function group(text: string, at: number): Result {
    let depth = 0;

    for (let i = at; i < text.length; i++) {
        if (text[i] === '\\') {
            i++;
        } else if (text[i] === '{') {
            depth++;
        } else if (text[i] === '}' && --depth === 0) {
            return [text.slice(at + 1, i), i + 1];
        }
    }

    return [text.slice(at + 1), text.length];
}

/** The content of the `[...]` opening at `at` and the offset after it: the first `]` outside braces closes it. */
export function optional(text: string, at: number): Result {
    let depth = 0;

    for (let i = at + 1; i < text.length; i++) {
        if (text[i] === '\\') {
            i++;
        } else if (text[i] === '{') {
            depth++;
        } else if (text[i] === '}') {
            depth--;
        } else if (text[i] === ']' && depth <= 0) {
            return [text.slice(at + 1, i), i + 1];
        }
    }

    return [text.slice(at + 1), text.length];
}

/**
 * A command's required argument after any optional ones, each allowed to follow spaces and one
 * line break; null, and `at` unchanged, when no brace group follows.
 */
export function argument(text: string, at: number): [string | null, number] {
    let i = space(text, at);

    while (text[i] === '[') {
        i = space(text, optional(text, i)[1]);
    }

    return text[i] === '{' ? group(text, i) : [null, at];
}

/** The `[...]` and `{...}` groups directly after `at`, with nothing between them, and the brace groups' contents. */
export function directArguments(text: string, at: number): [string[], number] {
    const groups: string[] = [];

    for (;;) {
        const c = text[at];

        if (c === '[') {
            at = optional(text, at)[1];
        } else if (c === '{') {
            let content: string;
            [content, at] = group(text, at);
            groups.push(content);
        } else {
            return [groups, at];
        }
    }
}

function command(text: string, at: number): Result {
    const next = text[at + 1];

    if (next === undefined) {
        return ['', at + 1];
    }

    const name = leadingLetters(text, at + 1);

    if (name === '') {
        return symbol(text, at, next);
    }

    let i = at + 1 + name.length;
    i = text[i] === '*' ? i + 1 : i;

    if (name === 'verb' || name === 'Verb') {
        return verb(text, at);
    }
    if (has(LETTER_ACCENTS, name) && text[i] === '{') {
        let inner: string;
        [inner, i] = group(text, i);

        return [accent(convert(inner), LETTER_ACCENTS[name]!), i];
    }
    if (has(SYMBOLS, name)) {
        return [SYMBOLS[name]!, i];
    }

    return named(text, name, i);
}

/** The run of ASCII letters starting at `at`. */
export function leadingLetters(text: string, at: number): string {
    let end = at;

    while (end < text.length && /[A-Za-z]/.test(text[end]!)) {
        end++;
    }

    return text.slice(at, end);
}

/**
 * siunitx quantities and the `units` package: `\qty{9.81}{\metre\per\second\squared}` gives
 * `9.81 m/s²`, `\unit[11]{inches}` gives `11 inches`.
 */
function quantity(text: string, name: string, start: number): Result {
    let i = start;
    let value: string | null = null;
    const j = space(text, i);

    if (name === 'unit' && text[j] === '[') {
        const [option, after] = optional(text, j);

        if (!option.includes('=')) {
            [value, i] = [option, after];
        }
    }

    const parts: string[] = [];

    for (let k = 0; k < QUANTITIES[name]!; k++) {
        let part: string | null;
        [part, i] = argument(text, i);
        parts.push(part ?? '');
    }

    const plain = name === 'num' || name === 'numrange';
    const numbers = name === 'unit' || name === 'si' ? [] : parts.slice(0, plain ? parts.length : parts.length - 1).map((part) => trim(convert(part)));
    const unit = plain ? '' : units(parts[parts.length - 1]!);
    const number = [...(value === null ? [] : [trim(convert(value))]), ...numbers].join('–');

    return [trim(`${number} ${unit}`), i];
}

/**
 * A siunitx unit: prefixes and units as symbols, `\per` as `/`, `\squared` and `\cubed` (or
 * `\square` and `\cubic` before the unit) as powers; other macros by name, other text converted.
 */
function units(raw: string): string {
    let out = '';
    let prefix = '';
    let power = '';
    let joiner = '';

    for (const [, macro, plain] of raw.matchAll(/\\([A-Za-z]+)|([^\\]+)/g)) {
        if (macro === undefined) {
            out += joiner + trim(convert(plain ?? ''));
            joiner = '';
        } else if (has(SI_PREFIXES, macro)) {
            prefix += SI_PREFIXES[macro]!;
        } else if (macro === 'per') {
            joiner = '/';
        } else if (macro === 'square' || macro === 'cubic') {
            power = macro === 'square' ? '²' : '³';
        } else if (macro === 'squared' || macro === 'cubed') {
            out += macro === 'squared' ? '²' : '³';
        } else {
            const symbol = has(SI_UNITS, macro) ? SI_UNITS[macro]! : macro;
            out += (joiner !== '' ? joiner : out === '' ? '' : ' ') + prefix + symbol + power;
            prefix = '';
            power = '';
            joiner = '';
        }
    }

    return trim(out);
}

function named(text: string, name: string, i: number): Result {
    if (Object.hasOwn(QUANTITIES, name)) {
        return quantity(text, name, i);
    }
    if (FRACTIONS.has(name)) {
        let top: string | null;
        let bottom: string | null;
        [top, i] = argument(text, i);
        [bottom, i] = argument(text, i);

        return [`${FRACTION}${trim(convert(top ?? ''))}/${trim(convert(bottom ?? ''))}`, i];
    }
    if (UNWRAP.has(name)) {
        let value: string | null;
        [value, i] = argument(text, i);

        return [value === null ? '' : convert(value), i];
    }
    if (name === 'url') {
        let value: string | null;
        [value, i] = argument(text, i);

        return [trim(value ?? ''), i];
    }
    if (name === 'href') {
        let url: string | null;
        let label: string | null;
        [url, i] = argument(text, i);
        [label, i] = argument(text, i);

        return [`${convert(label ?? '')} (${trim(url ?? '')})`, i];
    }
    if (name === 'footnote') {
        let value: string | null;
        [value, i] = argument(text, i);

        return [value === null ? '' : ` (${convert(value)})`, i];
    }
    if (CITE.has(name)) {
        let keys: string | null;
        [keys, i] = argument(text, i);

        return [`[${(keys ?? '').split(',').map(trim).filter((key) => key !== '').join(', ')}]`, i];
    }
    if (REF.has(name)) {
        let key: string | null;
        [key, i] = argument(text, i);

        return [trim(key ?? ''), i];
    }

    let groups: string[];
    [groups, i] = directArguments(text, i);
    const last = groups.at(-1);

    if (last === undefined || DROP.has(name) || DIMENSION.test(last)) {
        return ['', i];
    }

    return [OPEN + convert(last) + CLOSE, i];
}

function symbol(text: string, at: number, next: string): Result {
    if (next === '\\') {
        let i = at + 2;
        i = text[i] === '*' ? i + 1 : i;

        return [' ', text[i] === '[' ? optional(text, i)[1] : i];
    }
    if (next === '(') {
        const found = text.indexOf('\\)', at + 2);
        const end = found === -1 ? text.length : found + 2;

        return [text.slice(at, end), end];
    }
    if (has(ACCENTS, next)) {
        return symbolAccent(text, at + 2, ACCENTS[next]!);
    }

    return [has(SPACES, next) ? SPACES[next]! : next, at + 2];
}

function symbolAccent(text: string, i: number, mark: string): Result {
    const c = text[i] ?? '';

    if (c === '{') {
        let inner: string;
        [inner, i] = group(text, i);

        return [accent(convert(inner), mark), i];
    }
    if (c === '\\') {
        let base: string;
        [base, i] = command(text, i);

        return [accent(base, mark), i];
    }
    if (/[A-Za-z]/.test(c)) {
        return [accent(c, mark), i + 1];
    }

    return ['', i];
}

/** The first character of `base` with the combining mark, composed where Unicode has a precomposed form. */
function accent(base: string, mark: string): string {
    if (base === '') {
        return '';
    }

    const points = Array.from(base);

    return (points[0]! + mark).normalize('NFC') + points.slice(1).join('');
}

function verb(text: string, at: number): Result {
    VERB.lastIndex = at;
    const match = VERB.exec(text);

    if (match === null) {
        return ['', at + 5];
    }

    const start = at + match[0].length;
    const found = text.indexOf(match[1]!, start);
    const end = found === -1 ? text.length : found;

    return [text.slice(start, end), Math.min(text.length, end + match[1]!.length)];
}

function math(text: string, at: number): Result {
    const double = text[at + 1] === '$';

    for (let i = at + (double ? 2 : 1); i < text.length; i++) {
        if (text[i] === '\\') {
            i++;
        } else if (text[i] === '$' && (!double || text[i + 1] === '$')) {
            const end = i + (double ? 2 : 1);

            return [text.slice(at, end), end];
        }
    }

    return ['$', at + 1];
}

function character(text: string, at: number): Result {
    const c = text[at]!;

    if (c === '}') {
        return ['', at + 1];
    }
    if (c === '~') {
        return [' ', at + 1];
    }
    if (text.startsWith('---', at)) {
        return ['—', at + 3];
    }
    if (text.startsWith('--', at)) {
        return ['–', at + 2];
    }
    if (text.startsWith('``', at) || text.startsWith("''", at)) {
        return ['"', at + 2];
    }

    return c === '`' ? ["'", at + 1] : [c, at + 1];
}
