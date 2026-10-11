import { BlockType, type Block } from '../block.js';
import { Chunk } from '../chunk.js';
import type { Config } from '../config.js';
import { isWide, sentences, tail, tokens as tokensOf, trim } from '../text.js';
import type { Crumb } from './breadcrumbs.js';
import type { Shifts } from './topicShifts.js';

interface Raw {
    blocks: number[];
    text: string;
    tokens: number;
    topicStart: boolean;
}

interface Part {
    text: string;
    topicStart: boolean;
}

/**
 * Packs blocks into chunks between `minTokens` and `maxTokens`. A heading
 * always starts a chunk, and so does a block or sentence that opens a topic
 * once the chunk so far holds `topicMinTokens`. The heading's text is not part
 * of the chunk's own text, since it ends the path the chunk is prefixed with,
 * and a heading with nothing under it before the next heading makes no chunk.
 * A paragraph longer than `splitLongParagraphAt` is packed sentence by
 * sentence. Block indexes here are positions among the kept blocks.
 */
export class Packer {
    private readonly chunks: Raw[] = [];

    private current: Raw = empty(false);

    constructor(
        private readonly blocks: readonly Block[],
        private readonly tokens: readonly number[],
        private readonly crumbs: readonly Crumb[],
        private readonly shifts: Shifts,
        private readonly config: Config,
    ) {}

    pack(): Chunk[] {
        this.blocks.forEach((block, index) => {
            const opensTopic = this.shifts.blocks.has(index) && this.current.tokens >= this.config.topicMinTokens;

            if (block.isHeading()) {
                this.flush(false);
                this.current = empty(false);
                this.current.blocks = [index];

                return;
            }
            if (opensTopic && this.current.text !== '') {
                this.flush(true);
            }

            if (block.type === BlockType.Paragraph && this.tokens[index]! > this.config.splitLongParagraphAt) {
                this.addSentences(index, block.text);
            } else if ((block.type === BlockType.Code || block.type === BlockType.Table) && this.tokens[index]! > this.config.maxTokens) {
                for (const part of this.lineParts(trim(block.text), block.type === BlockType.Table)) {
                    this.addBlock(index, part, tokensOf(part, this.config.charsPerToken));
                }
            } else {
                this.addBlock(index, trim(block.text), this.tokens[index]!);
            }
        });

        if (this.current.text !== '') {
            this.flush(false);
        }

        return this.finish();
    }

    private addBlock(index: number, text: string, tokens: number): void {
        if (this.config.maxTokens >= this.current.tokens + tokens) {
            this.append(index, text, tokens);
        } else if (this.current.tokens >= this.config.minTokens) {
            this.flush(false);
            this.append(index, text, tokens);
        } else {
            this.append(index, text, tokens);
            this.flush(false);
        }
    }

    private addSentences(index: number, text: string): void {
        const parts = this.sentenceParts(text, this.shifts.sentences.get(index) ?? new Set())
            .flatMap((part) => this.cut(part.text, this.config.maxTokens).map((piece, i) => ({ text: piece, topicStart: part.topicStart && i === 0 })));

        for (const part of parts) {
            const tokens = tokensOf(part.text, this.config.charsPerToken);
            const full = this.config.maxTokens < this.current.tokens + tokens && this.current.tokens >= this.config.minTokens;

            if (this.current.text !== '' && (full || part.topicStart)) {
                this.flush(part.topicStart);
            }
            this.append(index, part.text, tokens);
        }
    }

    /**
     * A code or table block over `maxTokens` cut at line boundaries into parts of at most
     * `maxTokens`; each part of a table starts with its header line (after its `Table:` caption
     * line, when it has one). A line still over the limit is cut by `cut()`.
     */
    private lineParts(text: string, table: boolean): string[] {
        const lines = text.split('\n');
        const head: string[] = [];

        if (table) {
            head.push(lines.shift() ?? '');
            if (head[0]!.startsWith('Table:') && lines.length > 0) {
                head.push(lines.shift()!);
            }
        }

        const headTokens = head.length === 0 ? 0 : tokensOf(`${head.join('\n')}\n`, this.config.charsPerToken);
        const limit = Math.max(1, this.config.maxTokens - headTokens);
        const parts: string[] = [];
        let buffer: string[] = [];
        let size = 0;

        for (const line of lines) {
            for (const piece of this.cut(line, limit)) {
                const pieceTokens = tokensOf(`${piece}\n`, this.config.charsPerToken);

                if (buffer.length > 0 && size + pieceTokens > limit) {
                    parts.push([...head, ...buffer].join('\n'));
                    buffer = [];
                    size = 0;
                }
                buffer.push(piece);
                size += pieceTokens;
            }
        }
        if (buffer.length > 0 || parts.length === 0) {
            parts.push([...head, ...buffer].join('\n'));
        }

        return parts;
    }

    /**
     * A text over `limit` tokens cut into pieces of at most `limit`, each ending before a space
     * when there is one in its second half, else at the limit. Pieces are right-trimmed and the
     * rest left-trimmed with PHP's `trim()` characters.
     */
    private cut(text: string, limit: number): string[] {
        const pieces: string[] = [];
        let rest = Array.from(text);

        for (;;) {
            const wide = [0];

            rest.forEach((point, i) => wide.push(wide[i]! + (isWide(point) ? 1 : 0)));

            const count = (size: number): number => Math.ceil((size - wide[size]!) / Math.max(1, this.config.charsPerToken) + wide[size]!);

            if (count(rest.length) <= limit) {
                break;
            }

            let size = 1;

            while (size < rest.length && count(size + 1) <= limit) {
                size++;
            }

            const space = rest.slice(0, size).lastIndexOf(' ');
            const end = space !== -1 && space >= Math.floor(size / 2) ? space : size;
            pieces.push(rest.slice(0, end).join('').replace(/[ \t\n\r\0\x0B]+$/, ''));
            rest = Array.from(rest.slice(end).join('').replace(/^[ \t\n\r\0\x0B]+/, ''));
        }
        pieces.push(rest.join(''));

        return pieces;
    }

    private sentenceParts(text: string, shifts: ReadonlySet<number>): Part[] {
        const parts: Part[] = [];
        let buffer = '';
        let tokens = 0;
        let topicStart = false;

        sentences(text).forEach((sentence, i) => {
            const sentenceTokens = tokensOf(sentence, this.config.charsPerToken);
            const opensTopic = shifts.has(i) && tokens >= this.config.topicMinTokens;
            const full = tokens + sentenceTokens > this.config.maxTokens && tokens >= this.config.minTokens;

            if (buffer !== '' && (full || opensTopic)) {
                parts.push({ text: trim(buffer), topicStart });
                buffer = sentence;
                tokens = sentenceTokens;
                topicStart = opensTopic;
            } else {
                buffer = buffer === '' ? sentence : `${buffer} ${sentence}`;
                tokens += sentenceTokens;
            }
        });

        if (buffer !== '') {
            parts.push({ text: trim(buffer), topicStart });
        }

        return parts;
    }

    private append(index: number, text: string, tokens: number): void {
        if (!this.current.blocks.includes(index)) {
            this.current.blocks.push(index);
        }
        this.current.text += (this.current.text === '' ? '' : '\n\n') + text;
        this.current.tokens += tokens;
    }

    /** Flushing a chunk with no text does nothing, and its topic mark stays as it was. */
    private flush(nextOpensTopic: boolean): void {
        if (this.current.text === '') {
            return;
        }

        this.chunks.push(this.current);
        this.current = empty(nextOpensTopic);
    }

    /** Prefixes each chunk with its heading path, and the tail of the previous chunk's own text where it continues it. */
    private finish(): Chunk[] {
        let previous: { text: string; section: number; path: string[] } | null = null;

        return this.chunks.map((raw) => {
            const crumb = this.crumbs[raw.blocks[0]!]!;
            let overlap = '';

            if (previous !== null && this.config.overlapTokens > 0 && !raw.topicStart
                && previous.section === crumb.section && samePath(previous.path, crumb.path)) {
                overlap = tail(previous.text, this.config.overlapTokens, this.config.charsPerToken);
            }

            const text = (crumb.path.length === 0 ? '' : `[${crumb.path.join(' > ')}]\n\n`)
                + (overlap === '' ? '' : `${overlap}\n\n`)
                + raw.text;

            previous = { text: raw.text, section: crumb.section, path: crumb.path };

            return new Chunk(
                text,
                raw.blocks,
                crumb.path,
                crumb.level,
                crumb.section,
                tokensOf(text, this.config.charsPerToken),
                overlap === '' ? 0 : tokensOf(overlap, this.config.charsPerToken),
                raw.topicStart,
            );
        });
    }
}

function empty(topicStart: boolean): Raw {
    return { blocks: [], text: '', tokens: 0, topicStart };
}

function samePath(a: readonly string[], b: readonly string[]): boolean {
    return a.length === b.length && a.every((title, index) => title === b[index]);
}
