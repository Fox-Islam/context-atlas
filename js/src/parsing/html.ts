import { Block, BlockType } from '../block.js';
import { squash, trim, WHITESPACE } from '../text.js';
import { dropped, findElement, headingLevel, isBlock, isSpeaker, lineText, LISTS, mathText, opensArticle, rawText, scriptMark } from './html/content.js';
import type { Element } from './html/element.js';
import { readTable, tableBlock } from './html/table.js';
import { buildTree } from './html/tree.js';

type Speaker = { speaker: string; speaker_full?: string };

interface Line {
    prefix: string;
    text: string;
}

const BOILERPLATE_WORDS = 8;

const BOILERPLATE_LINK_WORDS = 4;

const BOILERPLATE_REPEATS = 3;

/** PCRE with the `u` flag: `\s` is Unicode whitespace. */
const SEPARATORS = new RegExp(`^(?:${WHITESPACE}|[\\-–—:.,;])+`, 'u');

const SEPARATORS_END = new RegExp(`(?:${WHITESPACE}|[\\-–—:.,;])+$`, 'u');

const SPACE_RUN = new RegExp(`${WHITESPACE}+`, 'u');

const MARKER = /^(?:\(?(?:[0-9]{1,3}|[A-Za-z]{1,2}|[ivxlcIVXLC]{1,6})[.)]|[-–•*·])$/u;

/**
 * Reads the content of an HTML page into blocks: headings, paragraphs (a list as one paragraph
 * of `- ` or `1. ` lines), code from `pre`, and tables with their caption, header and rows in
 * meta. Reading starts at `main` (or `role="main"`), else `body`; page furniture such as
 * scripts, navigation and site headers is dropped, and so is a row of short links repeated
 * through the page. Speaker markup in transcripts gives the paragraphs after it
 * `meta.speaker`. No DOM library is used; spec/formats/html.md states the rules.
 */
export function parseHtml(text: string): Block[] {
    const root = buildTree(text.replace(/\r\n?/g, '\n'));
    const start = findElement(root, (e) => e.name === 'main' || e.token('role') === 'main')
        ?? findElement(root, (e) => e.name === 'body')
        ?? root;
    const reader = new HtmlReader(start);

    reader.walk(start, opensArticle(start));
    reader.flush();

    return titled(reader.withoutBoilerplate(), root);
}

/** The page's `title` as a level-1 heading in front, when no level-1 heading was read. */
function titled(blocks: Block[], root: Element): Block[] {
    if (blocks.some((block) => block.isHeading() && block.level === 1)) {
        return blocks;
    }

    const title = findElement(root, (e) => e.name === 'title', ['svg']);
    const text = title === null ? '' : squash(title.children.filter((child): child is string => typeof child === 'string').join(''));

    return text === '' ? blocks : [Block.heading(text, 1), ...blocks];
}

class HtmlReader {
    private readonly blocks: Block[] = [];

    private buffer = '';

    private lines: string[] = [];

    private speaker: Speaker | null = null;

    private speakerScope: Element | null = null;

    private afterSpeaker = false;

    /** Open blocks, each with the number of blocks emitted when it opened. */
    private readonly open: [Element, number][];

    /** The most words in one link's text in the buffer, null without a link. */
    private bufferLink: number | null = null;

    /** The same for the list lines. */
    private listLink: number | null = null;

    /** Block index to the repeat key of a paragraph that may be boilerplate. */
    private readonly candidates = new Map<number, string>();

    constructor(start: Element) {
        this.open = [[start, 0]];
    }

    walk(parent: Element, inArticle: boolean): void {
        for (const child of parent.children) {
            if (typeof child === 'string') {
                this.buffer += child;
            } else if (dropped(child, inArticle, isSoleButton(parent, child))) {
                continue;
            } else if (child.name === 'math') {
                this.buffer += mathText(child);
            } else if (isSpeaker(child)) {
                this.speak(child, inArticle);
            } else {
                this.bufferLink = linkWords(child, inArticle, this.bufferLink);
                this.element(child, inArticle || opensArticle(child));
            }
        }
    }

    /** Emits the buffer as a paragraph. Right after a speaker element, the separators between the name and the speech (`M. Dupont. - Texte`) are left out. */
    flush(): void {
        let text = squash(this.buffer);

        if (this.afterSpeaker) {
            text = text.replace(SEPARATORS, '');
            this.afterSpeaker = false;
        }
        this.emit(BlockType.Paragraph, text, null, this.bufferLink);
        this.buffer = '';
        this.bufferLink = null;
    }

    /** The blocks without boilerplate: a paragraph holding a link, no link longer than four words, made of short lines, whose text with digits removed has an ASCII letter and recurs at least three times in the page. */
    withoutBoilerplate(): Block[] {
        const repeats = new Map<string, number>();

        for (const key of this.candidates.values()) {
            repeats.set(key, (repeats.get(key) ?? 0) + 1);
        }

        return this.blocks.filter((_, i) => {
            const key = this.candidates.get(i);

            return key === undefined || repeats.get(key)! < BOILERPLATE_REPEATS;
        });
    }

    private element(element: Element, inArticle: boolean): void {
        const name = element.name;

        if (name === 'br') {
            this.buffer += '\n';

            return;
        }

        const level = headingLevel(element);

        if (level === null && !isBlock(name)) {
            this.buffer += scriptMark(element, this.buffer);
            this.walk(element, inArticle);

            return;
        }

        this.flush();

        if (level !== null) {
            this.emit(BlockType.Heading, squash(lineText(element, inArticle, true)), level);
        } else if (name === 'dl' && isMarkerList(element, inArticle)) {
            this.markers(element, inArticle, 0);
        } else if (name === 'pre') {
            this.emit(BlockType.Code, code(element, inArticle));
        } else if (name === 'table') {
            this.table(element, inArticle);
        } else if (LISTS.has(name)) {
            this.items(element, inArticle, 0);
        } else {
            this.block(element, inArticle);
        }

        this.emitList();
        this.flush();
    }

    private block(element: Element, inArticle: boolean): void {
        this.open.push([element, this.blocks.length]);
        this.walk(element, inArticle);
        this.flush();
        this.open.pop();

        if (this.speakerScope === element) {
            this.speaker = null;
            this.speakerScope = null;
        }
    }

    /**
     * A speaker element is not read as text: it names who speaks in the paragraphs after it,
     * up to the next speaker element or the end of the block it sits in. When it opens a `p`,
     * that block is the one around the `p`, so the speech's later paragraphs are included.
     */
    private speak(element: Element, inArticle: boolean): void {
        const full = squash(lineText(element, inArticle)).replace(SEPARATORS_END, '');

        if (full === '') {
            return;
        }

        const named = findElement(element, (e) => e.tokens('class').some((token) => token.includes('name')));
        const nameText = named === null ? '' : squash(lineText(named, inArticle)).replace(SEPARATORS_END, '');
        const name = nameText !== '' ? nameText : full;
        const opens = trim(this.buffer) === '';

        this.flush();
        this.speaker = name === full ? { speaker: name } : { speaker: name, speaker_full: full };

        const top = this.open.length - 1;
        let [block, openedAt] = this.open[top]!;

        if (opens && block.name === 'p' && openedAt === this.blocks.length && top > 0) {
            block = this.open[top - 1]![0];
        }
        this.speakerScope = block;
        this.afterSpeaker = true;
    }

    /** Adds a marker list's lines: each `dt` marker, a space, and the text of the `dd` after it. */
    private markers(dl: Element, inArticle: boolean, depth: number): void {
        let marker = '';

        for (const child of dl.children) {
            if (typeof child === 'string' || dropped(child, inArticle)) {
                continue;
            }
            if (child.name === 'dt') {
                marker = `${squash(lineText(child, inArticle))} `;
                continue;
            }

            const line: Line = { prefix: '  '.repeat(depth) + marker, text: '' };
            this.item(child, inArticle || opensArticle(child), depth, line);
            this.line(line, depth);
            marker = '';
        }
    }

    private emitList(): void {
        this.emit(BlockType.Paragraph, this.lines.join('\n'), null, this.listLink);
        this.lines = [];
        this.listLink = null;
    }

    /** `link` is the most words in one link's text inside the block, null when it has none. */
    private emit(type: BlockType, text: string, level: number | null = null, link: number | null = null): void {
        if (trim(text) === '') {
            return;
        }

        const meta = type === BlockType.Paragraph && this.speaker !== null ? { ...this.speaker } : {};
        const key = squash(text.replace(/[0-9]+/g, ''));

        if (link !== null && link <= BOILERPLATE_LINK_WORDS && /[A-Za-z]/.test(key) && isLinkLine(text)) {
            this.candidates.set(this.blocks.length, key);
        }
        this.blocks.push(new Block(type, text, level, meta));
    }

    private table(element: Element, inArticle: boolean): void {
        const table = readTable(element, inArticle);

        if (table !== null) {
            this.blocks.push(tableBlock(table));
        }
    }

    /** Adds a list's lines: one per item, `- ` or `<n>. ` after two spaces per level of nesting. */
    private items(list: Element, inArticle: boolean, depth: number): void {
        const ordered = list.name === 'ol';
        const start = (list.attribute('start') ?? '').replace(/^[ \t\n\f\r]+|[ \t\n\f\r]+$/g, '');
        const counter = { value: ordered && /^-?[0-9]{1,9}$/.test(start) ? Number.parseInt(start, 10) : 1 };

        this.children(list, inArticle, depth, ordered, counter);
    }

    private children(parent: Element, inArticle: boolean, depth: number, ordered: boolean, counter: { value: number }): void {
        const indent = '  '.repeat(depth);

        for (const child of parent.children) {
            if (typeof child === 'string') {
                const text = squash(child);

                if (text !== '') {
                    this.lines.push(indent + (ordered ? `${counter.value}. ` : '- ') + text);
                    counter.value++;
                }
            } else if (dropped(child, inArticle)) {
                continue;
            } else if (LISTS.has(child.name)) {
                this.items(child, inArticle || opensArticle(child), depth + 1);
            } else if (child.name === 'li') {
                const line: Line = { prefix: indent + (ordered ? `${counter.value}. ` : '- '), text: '' };
                this.item(child, inArticle || opensArticle(child), depth, line);
                this.line(line, depth);
                counter.value++;
            } else {
                this.children(child, inArticle || opensArticle(child), depth, ordered, counter);
            }
        }
    }

    /**
     * Reads an item's text in order. A list inside it adds its lines after the text so far; a
     * `pre` or table ends the list paragraph so far, is emitted, and the list goes on after.
     */
    private item(parent: Element, inArticle: boolean, depth: number, line: Line): void {
        for (const child of parent.children) {
            if (typeof child === 'string') {
                line.text += child;
                continue;
            }
            if (dropped(child, inArticle)) {
                continue;
            }

            const inside = inArticle || opensArticle(child);
            const name = child.name;
            this.listLink = linkWords(child, inside, this.listLink);

            if (name === 'br') {
                line.text += ' ';
            } else if (name === 'math') {
                line.text += mathText(child);
            } else if (name === 'dl' && isMarkerList(child, inside)) {
                this.line(line, depth);
                this.markers(child, inside, depth + 1);
            } else if (LISTS.has(name)) {
                this.line(line, depth);
                this.items(child, inside, depth + 1);
            } else if (name === 'pre' || name === 'table') {
                this.line(line, depth);
                this.emitList();
                if (name === 'pre') {
                    this.emit(BlockType.Code, code(child, inside));
                } else {
                    this.table(child, inside);
                }
            } else if (isBlock(name)) {
                line.text += ' ';
                this.item(child, inside, depth, line);
                line.text += ' ';
            } else {
                line.text += scriptMark(child, line.text);
                this.item(child, inside, depth, line);
            }
        }
    }

    /** Ends the text collected for an item as one line; an item's later lines are indented one level with no marker. */
    private line(line: Line, depth: number): void {
        const text = squash(line.text);
        line.text = '';

        if (text !== '') {
            this.lines.push(line.prefix + text);
            line.prefix = '  '.repeat(depth + 1);
        }
    }
}

/** A `button` that is the only content of a `summary` or `dt`, which holds its label. */
function isSoleButton(parent: Element, child: Element): boolean {
    if (child.name !== 'button' || (parent.name !== 'summary' && parent.name !== 'dt')) {
        return false;
    }

    return parent.children.every((other) => other === child || (typeof other === 'string' && trim(other) === ''));
}

/** A `dl` whose `dt` children are all enumeration markers, as statutes number their points: `1.`, `a)`, `(iv)`, `-`. */
function isMarkerList(dl: Element, inArticle: boolean): boolean {
    let markers = 0;

    for (const child of dl.children) {
        if (typeof child === 'string') {
            if (trim(child) !== '') {
                return false;
            }
        } else if (child.name === 'dt') {
            if (!MARKER.test(squash(lineText(child, inArticle)))) {
                return false;
            }
            markers++;
        } else if (child.name !== 'dd' && !dropped(child, inArticle)) {
            return false;
        }
    }

    return markers > 0;
}

/** The most words in one link's text so far, counting `element` when it is an `a`. */
function linkWords(element: Element, inArticle: boolean, most: number | null): number | null {
    if (element.name !== 'a') {
        return most;
    }

    const text = squash(lineText(element, inArticle));
    const words = text === '' ? 0 : text.split(SPACE_RUN).length;

    return Math.max(most ?? 0, words);
}

/** Short lines, none ending a sentence: what a row of links such as "Reply | Share" reads as. */
function isLinkLine(text: string): boolean {
    for (const raw of text.split('\n')) {
        const line = trim(raw.replace(/^[ \t\n\v\f\r]*(?:-|-?[0-9]+\.)[ \t\n\v\f\r]/, ''));

        if (line.split(SPACE_RUN).length > BOILERPLATE_WORDS || /[.!?]$/.test(line)) {
            return false;
        }
    }

    return true;
}

/** A `pre` element's text as written, a `br` as a line break, without one leading line break and without trailing whitespace. */
function code(pre: Element, inArticle: boolean): string {
    const text = rawText(pre, inArticle);

    return (text.startsWith('\n') ? text.slice(1) : text).replace(/[ \t\n\f]+$/, '');
}
