import { Block, BlockType } from '../block.js';
import { trim } from '../text.js';

const FENCE = /^ {0,3}(`{3,}|~{3,})/;

const ATX = /^ {0,3}(#{1,6})[ \t]+(.+?)(?:[ \t]+#+)?[ \t]*$/s;

const SETEXT = /^ {0,3}(=+|-+)[ \t]*$/;

const BREAK = /^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/;

const TABLE = /^ {0,3}\|/;

const LIST_ITEM = /^ {0,3}(?:[-*+]|\d{1,9}[.)])[ \t]+/;

const QUOTE = /^ {0,3}>[ \t]?/;

/**
 * Reads the block structure of Markdown: ATX and setext headings, fenced code,
 * pipe tables, lists, block quotes and paragraphs. Inline markup is left in
 * the text. spec/behaviour.md states the rules.
 */
export function parseMarkdown(markdown: string): Block[] {
    return new MarkdownReader().read(withoutFrontMatter(markdown.replace(/\r\n?/g, '\n').split('\n')));
}

class MarkdownReader {
    private readonly blocks: Block[] = [];

    private lines: string[] = [];

    private open: BlockType = BlockType.Paragraph;

    private list = false;

    read(lines: readonly string[]): Block[] {
        for (let i = 0; i < lines.length; i++) {
            const line = lines[i]!;
            const fence = FENCE.exec(line);

            if (fence !== null) {
                this.close();
                i = this.fenced(lines, i, fence[1]!);
            } else {
                this.line(line);
            }
        }

        this.close();

        return this.blocks;
    }

    private line(line: string): void {
        const atx = ATX.exec(line);
        const setext = this.open === BlockType.Paragraph && !this.list && this.lines.length > 0 ? SETEXT.exec(line) : null;

        if (trim(line) === '') {
            this.close();
        } else if (atx !== null) {
            this.close();
            this.blocks.push(Block.heading(trim(atx[2]!), atx[1]!.length));
        } else if (setext !== null) {
            this.blocks.push(Block.heading(this.lines.join(' '), setext[1]!.startsWith('=') ? 1 : 2));
            this.lines = [];
        } else if (BREAK.test(line)) {
            this.close();
        } else {
            this.content(line);
        }
    }

    private content(line: string): void {
        const isTable = TABLE.test(line);
        const isItem = LIST_ITEM.test(line);
        const type = isTable ? BlockType.Table : BlockType.Paragraph;

        if (this.lines.length > 0 && (type !== this.open || (isItem && !this.list))) {
            this.close();
        }

        this.open = type;
        this.list = this.list || isItem;
        this.lines.push(isTable ? trim(line) : trim(line.replace(QUOTE, '')));
    }

    /** Index of the closing fence line, or of the last line when the fence is never closed. */
    private fenced(lines: readonly string[], start: number, fence: string): number {
        const body: string[] = [];
        const closer = new RegExp(`^ {0,3}\\${fence[0]}{${fence.length},}[ \\t]*$`);
        let i = start + 1;

        for (; i < lines.length; i++) {
            if (closer.test(lines[i]!)) {
                break;
            }
            body.push(lines[i]!);
        }

        const text = body.join('\n');

        if (trim(text) !== '') {
            this.blocks.push(new Block(BlockType.Code, text));
        }

        return i;
    }

    private close(): void {
        if (this.lines.length > 0) {
            const glue = this.open === BlockType.Table || this.list ? '\n' : ' ';
            this.blocks.push(new Block(this.open, this.lines.join(glue)));
        }

        this.lines = [];
        this.open = BlockType.Paragraph;
        this.list = false;
    }
}

function withoutFrontMatter(lines: string[]): string[] {
    if (lines[0] !== '---') {
        return lines;
    }

    for (let i = 1; i < lines.length; i++) {
        if (lines[i] === '---' || lines[i] === '...') {
            return lines.slice(i + 1);
        }
    }

    return lines;
}
