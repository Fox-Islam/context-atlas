import { Block, BlockType } from '../block.js';
import { phpTrim } from '../support/php.js';
import { length, squash, WHITESPACE } from '../text.js';

const NUMBERED = new RegExp(`^((?:Appendix|Annex)${WHITESPACE}+[A-Z]|\\p{Nd}+)((?:\\.\\p{Nd}+)*)\\.?${WHITESPACE}+(\\p{Lu}[^\\n]{0,78})$`, 'u');

const SHORT = 60;

/**
 * Plain text: each run of lines between blank lines is a paragraph, its lines joined by single
 * spaces. Structure the layout shows is kept (spec/behaviour.md, "Plain text"):
 *
 * - a one-line paragraph numbered like `2.5.3.  Encryption` or `Appendix A.  Notes` is a
 *   heading, its level the count of numbers (the appendix letter counts as one);
 * - a one-line paragraph at or left of the body's indent, of at most 60 characters, starting
 *   with a capital and not ending in `.`, `,`, `;` or `:`, is a level-1 heading when the
 *   paragraph after it is more than twice as long;
 * - a paragraph whose lines are all indented at least two spaces deeper than the body is code,
 *   its lines kept with their smallest indent removed. The body's indent is the indent of the
 *   paragraphs' first lines that holds the most text (the smallest on a tie).
 */
export function parseText(text: string): Block[] {
    const normalised = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n').replace(/\f/g, '\n').replace(/^﻿/, '');
    const chunks = normalised.split(/\n[ \t]*\n/).filter((chunk) => squash(chunk) !== '');
    const body = bodyIndent(chunks);

    return chunks.map((chunk, i) => {
        const lines = chunk.split('\n').filter((line) => phpTrim(line) !== '');
        const indents = lines.map(indentOf);
        const paragraph = squash(chunk);
        const numbered = lines.length === 1 ? NUMBERED.exec(paragraph) : null;

        if (indents.length > 0 && Math.min(...indents) >= body + 2) {
            const dedent = Math.min(...indents);

            return new Block(BlockType.Code, lines.map((line) => line.slice(dedent).replace(/[ \t\n\r\0\x0B]+$/, '')).join('\n'));
        }
        if (numbered !== null && !/[.,;:]$/.test(numbered[3]!)) {
            return Block.heading(paragraph, 1 + (numbered[2]!.match(/\./g)?.length ?? 0));
        }
        if (lines.length === 1 && indents[0]! <= body && isTitle(paragraph) && length(squash(chunks[i + 1] ?? '')) > 2 * length(paragraph)) {
            return Block.heading(paragraph, 1);
        }

        return Block.paragraph(paragraph);
    });
}

/** The indent of the paragraphs' first lines that holds the most text, the smallest on a tie. */
function bodyIndent(chunks: readonly string[]): number {
    const counts = new Map<number, number>();

    for (const chunk of chunks) {
        const first = chunk.split('\n').find((line) => phpTrim(line) !== '');

        if (first !== undefined) {
            counts.set(indentOf(first), (counts.get(indentOf(first)) ?? 0) + length(squash(chunk)));
        }
    }

    const sorted = [...counts].sort(([a], [b]) => a - b);
    const most = Math.max(...sorted.map(([, count]) => count));

    return sorted.length === 0 ? 0 : sorted.find(([, count]) => count === most)![0];
}

function indentOf(line: string): number {
    return line.length - line.replace(/^[ \t]+/, '').length;
}

function isTitle(line: string): boolean {
    return length(line) <= SHORT && /^\p{Lu}/u.test(line) && !/[.,;:]$/.test(line);
}
