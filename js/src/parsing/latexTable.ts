import { squash } from '../text.js';
import { argument, convert, leadingLetters, optional, space } from './latexInline.js';

/**
 * Splits the content of a LaTeX tabular into rows and cells: rows at `\\` and `\tabularnewline`,
 * cells at `&`, both outside braces; rule commands dropped. In a longtable the repeated header
 * and the footers are dropped and a `\caption` row gives the caption (spec/formats/latex.md).
 */

const RULES = /\\(?:(?:toprule|midrule|bottomrule)(?:\[[^\]]*\])?|hline|hdashline|cline[ \t\n]*\{[^}]*\}|hhline[ \t\n]*\{[^}]*\}|cmidrule[ \t\n]*(?:\[[^\]]*\])?[ \t\n]*(?:\([^)]*\))?[ \t\n]*\{[^}]*\}|addlinespace(?:[ \t\n]*\[[^\]]*\])?|specialrule[ \t\n]*\{[^}]*\}[ \t\n]*\{[^}]*\}[ \t\n]*\{[^}]*\}|rowcolor[ \t\n]*(?:\[[^\]]*\])?[ \t\n]*\{[^}]*\})(?![A-Za-z])/g;

const CAPTION = /(?<!\\)\\caption(?![A-Za-z])\*?/;

const LONGTABLE_MARKS: ReadonlySet<string> = new Set(['endfirsthead', 'endhead', 'endfoot', 'endlastfoot']);

/** The caption a longtable gives, and the rows. */
export function readTable(content: string, long: boolean): [string | null, string[][]] {
    let caption: string | null = null;
    const rows: string[][] = [];

    for (const source of kept(split(content, long))) {
        const raw = source.replace(RULES, '');
        const titled = long ? CAPTION.exec(raw) : null;

        if (titled !== null) {
            caption = squash(convert(argument(raw, titled.index + titled[0].length)[0] ?? ''));
            continue;
        }

        const cells = cellsOf(raw).map((cell) => squash(convert(cell)));

        if (cells.some((cell) => cell !== '')) {
            rows.push(cells);
        }
    }

    return [caption, rows];
}

/** Each row's source and the longtable mark that ended it. */
function split(content: string, long: boolean): [string, string | null][] {
    const rows: [string, string | null][] = [];
    let row = '';
    let depth = 0;

    for (let i = 0; i < content.length; i++) {
        const c = content[i]!;

        if (c !== '\\') {
            depth += c === '{' ? 1 : c === '}' ? -1 : 0;
            row += c;
            continue;
        }

        const name = leadingLetters(content, i + 1);
        const mark = long && LONGTABLE_MARKS.has(name) ? name : null;

        if (depth <= 0 && (content[i + 1] === '\\' || name === 'tabularnewline' || mark !== null)) {
            rows.push([row, mark]);
            row = '';
            i = afterBreak(content, i, name);
            continue;
        }

        const piece = name !== '' ? `\\${name}` : content.slice(i, i + 2);
        row += piece;
        i += piece.length - 1;
    }
    rows.push([row, null]);

    return rows;
}

function afterBreak(content: string, i: number, name: string): number {
    if (name !== '') {
        return i + name.length;
    }

    i += 2;
    i = content[i] === '*' ? i + 1 : i;
    const j = space(content, i, false);

    return (content[j] === '[' ? optional(content, j)[1] : i) - 1;
}

/**
 * Drops a longtable's repeated header (the rows ended by `\endhead` when `\endfirsthead` is
 * used) and its footers (the rows ended by `\endfoot` or `\endlastfoot`).
 */
function kept(rows: readonly [string, string | null][]): string[] {
    const first = rows.some(([, mark]) => mark === 'endfirsthead');
    const out: string[] = [];
    let segment: string[] = [];

    for (const [raw, mark] of rows) {
        segment.push(raw);
        if (mark === null) {
            continue;
        }
        if (mark === 'endfirsthead' || (mark === 'endhead' && !first)) {
            out.push(...segment);
        }
        segment = [];
    }

    return [...out, ...segment];
}

function cellsOf(row: string): string[] {
    const cells: string[] = [];
    let cell = '';
    let depth = 0;

    for (let i = 0; i < row.length; i++) {
        const c = row[i]!;

        if (c === '\\') {
            cell += row.slice(i, i + 2);
            i++;
        } else if (c === '&' && depth <= 0) {
            cells.push(cell);
            cell = '';
        } else {
            depth += c === '{' ? 1 : c === '}' ? -1 : 0;
            cell += c;
        }
    }
    cells.push(cell);

    return cells;
}
