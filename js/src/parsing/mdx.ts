import type { Block } from '../block.js';
import { trim } from '../text.js';
import { parseMarkdown } from './markdown.js';

const FENCE = /^ {0,3}(`{3,}|~{3,})[ \t]*([^ \t]*)/;

const ESM = /^(?:import|export)(?=[ \t{*])/;

const TAG = /^(?:<\/?[A-Z][A-Za-z0-9_.]*(?:[ \t][^<>]*)?\/?>|<\/?>)$/;

/** PCRE without the `u` flag: `.` is any character but a line feed. */
const WRAPPED = /^<([A-Z][A-Za-z0-9_.]*)(?:[ \t][^<>]*)?>([^\n]*)<\/\1[ \t]*>$/;

const TAG_START = /^<[A-Z][A-Za-z0-9_.]*(?:[ \t][^<>]*)?$/;

const ADMONITION = /^:{3,}([A-Za-z][A-Za-z0-9_-]*)?(?:\[([^\]]*)\]|[ \t]+([^\n]*))?$/;

const HTML = /<\/?[a-z][a-z0-9-]*(?:[ \t][^<>]*)?\/?>|<!--[^\n]*?-->/g;

const COMMENT_OPEN = /\{[ \t]*\/\*/g;

const COMMENT_CLOSE = /\*\/[ \t]*\}/g;

/**
 * Reads MDX as Markdown after blanking what is not prose: import and export statements, lines
 * that hold only a component tag, and `{/* ... *\/}` comments. Fenced code is left alone, except
 * that an `mdx-code-block` fence is unwrapped and its content read as MDX (spec/formats/mdx.md).
 */
export function parseMdx(text: string): Block[] {
    const lines = text.replace(/\r\n|\r/g, '\n').split('\n');
    const start = frontMatterEnd(lines);
    const out = lines.slice(0, start);
    const comment = { open: false };
    let fence: string | null = null;
    let unwrap: string | null = null;
    let depth = 0;
    let tag = false;

    for (let i = start; i < lines.length; i++) {
        const line = lines[i]!;

        if (fence !== null) {
            fence = closes(line, fence) ? null : fence;
            out.push(line);
            continue;
        }
        if (unwrap !== null && closes(line, unwrap)) {
            unwrap = null;
            out.push('');
            continue;
        }
        if (depth > 0) {
            depth = trim(line) === '' ? 0 : depth + balance(line);
            out.push('');
            continue;
        }
        if (tag) {
            tag = !trim(line).endsWith('>');
            out.push('');
            continue;
        }

        const opening = comment.open ? null : FENCE.exec(line);

        if (opening !== null) {
            if (opening[2] === 'mdx-code-block' && unwrap === null) {
                unwrap = opening[1]!;
                out.push('');
            } else {
                fence = opening[1]!;
                out.push(line);
            }
            continue;
        }
        if (!comment.open && ESM.test(line)) {
            depth = Math.max(0, balance(line));
            out.push('');
            continue;
        }

        const kept = withoutComments(line, comment);
        const trimmed = trim(kept);
        const admonition = ADMONITION.exec(trimmed);
        const wrapped = admonition === null ? WRAPPED.exec(trimmed) : null;

        if (admonition !== null) {
            const title = trim(admonition[2] ?? admonition[3] ?? '');
            out.push(...(title === '' ? [''] : ['', title, '']));
        } else if (wrapped !== null) {
            out.push('', withoutHtml(wrapped[2]!), '');
        } else if (TAG.test(trimmed) || trimmed === '') {
            out.push('');
        } else if (TAG_START.test(trimmed)) {
            tag = true;
            out.push('');
        } else {
            const plain = withoutHtml(kept);
            out.push(trim(plain) === '' ? '' : plain);
        }
    }

    return parseMarkdown(out.join('\n'));
}

function frontMatterEnd(lines: readonly string[]): number {
    if (lines[0] !== '---') {
        return 0;
    }
    for (let i = 1; i < lines.length; i++) {
        if (lines[i] === '---' || lines[i] === '...') {
            return i + 1;
        }
    }

    return 0;
}

/** The line without lowercase HTML tags and `<!-- -->` comments, outside backtick code spans. */
function withoutHtml(line: string): string {
    return line.split(/(`[^`]*`)/).map((part, i) => (i % 2 === 0 ? part.replace(HTML, '') : part)).join('');
}

function closes(line: string, fence: string): boolean {
    return new RegExp(`^ {0,3}\\${fence[0]}{${fence.length},}[ \\t]*$`).test(line);
}

/** Opening minus closing brackets of all three kinds. */
function balance(line: string): number {
    return line.replace(/[^{[(]/g, '').length - line.replace(/[^}\])]/g, '').length;
}

/** The line without its `{/* ... *\/}` comments; `comment.open` carries an unclosed one to the next line. */
function withoutComments(source: string, comment: { open: boolean }): string {
    let line = source;

    if (comment.open) {
        COMMENT_CLOSE.lastIndex = 0;
        const end = COMMENT_CLOSE.exec(line);

        if (end === null) {
            return '';
        }
        line = line.slice(end.index + end[0].length);
        comment.open = false;
    }

    for (;;) {
        COMMENT_OPEN.lastIndex = 0;
        const open = COMMENT_OPEN.exec(line);

        if (open === null) {
            return line;
        }

        COMMENT_CLOSE.lastIndex = open.index + open[0].length;
        const end = COMMENT_CLOSE.exec(line);

        if (end === null) {
            comment.open = true;

            return line.slice(0, open.index);
        }
        line = line.slice(0, open.index) + line.slice(end.index + end[0].length);
    }
}
