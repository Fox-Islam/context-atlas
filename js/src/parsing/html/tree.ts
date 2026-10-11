import { decodeEntities } from './entities.js';
import { asciiLower, Element } from './element.js';

const NAME = '[A-Za-z][^\\t\\n\\f\\r \\/>]*';

const SPACE = /[\t\n\f\r ]*/y;

const BOGUS = /<[!?]|<\/(?![A-Za-z])/y;

const END_TAG = new RegExp(`<\\/(${NAME})[^>]*(?:>|$)`, 'y');

const START_TAG = new RegExp(`<(${NAME})`, 'y');

const ATTRIBUTE_NAME = /[^\t\n\f\r />][^\t\n\f\r /=>]*/y;

const EQUALS = /[\t\n\f\r ]*=[\t\n\f\r ]*/y;

const VALUE = /"([^"]*)(?:"|$)|'([^']*)(?:'|$)|([^\t\n\f\r >]*)/y;

const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'keygen', 'link', 'meta', 'param', 'source', 'track', 'wbr']);

const RAW = new Set(['iframe', 'noembed', 'noframes', 'noscript', 'script', 'style', 'textarea', 'title', 'xmp']);

const ESCAPABLE_RAW = new Set(['textarea', 'title']);

const CLOSES_P = new Set([
    'address', 'article', 'aside', 'blockquote', 'center', 'dd', 'details', 'dialog', 'dir', 'div', 'dl', 'dt',
    'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup',
    'hr', 'li', 'main', 'menu', 'nav', 'ol', 'p', 'pre', 'search', 'section', 'summary', 'table', 'ul',
]);

const HEADINGS = new Set(['h1', 'h2', 'h3', 'h4', 'h5', 'h6']);

const SCOPE = ['button', 'caption', 'html', 'table', 'td', 'template', 'th'];

const TABLE_PARTS = new Set(['caption', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr']);

const SECTIONS = ['tbody', 'tfoot', 'thead'];

/**
 * Tokenizes HTML and builds a tree with a stack of open elements: void elements never open,
 * `/>` closes any element at once, a few start tags close the element they cannot sit in (a `p`
 * before a block, an `li` before the next `li`, table cells and rows), and an end tag closes
 * the nearest open element of its name or is ignored. Every character the tokenizer looks for
 * is ASCII, so UTF-16 positions give the tokens PHP's byte offsets give (spec/formats/html.md).
 */
export function buildTree(html: string): Element {
    return new TreeBuilder(html).run();
}

class TreeBuilder {
    private readonly root = new Element('#document');

    /** Open elements below the document node, innermost last. */
    private readonly stack: Element[] = [];

    constructor(private readonly html: string) {}

    run(): Element {
        const length = this.html.length;
        let at = 0;

        while (at < length) {
            const lt = this.html.indexOf('<', at);

            if (lt === -1) {
                this.text(this.html.slice(at));
                break;
            }
            if (lt > at) {
                this.text(this.html.slice(at, lt));
            }
            at = this.markup(lt);
        }

        return this.root;
    }

    /** Reads the markup token at `at`, a `<`, and returns the position after it. */
    private markup(at: number): number {
        if (this.html.startsWith('<!--', at)) {
            return this.after('-->', at + 4);
        }
        if (matchAt(BOGUS, this.html, at) !== null) {
            return this.after('>', at + 2);
        }

        const end = matchAt(END_TAG, this.html, at);

        if (end !== null) {
            this.end(asciiLower(end[1]!));

            return at + end[0].length;
        }

        const start = matchAt(START_TAG, this.html, at);

        if (start !== null) {
            return this.startTag(asciiLower(start[1]!), at + start[0].length);
        }

        this.text('<');

        return at + 1;
    }

    /** The position just after the next `needle` from `from`, or the end of the input. */
    private after(needle: string, from: number): number {
        const found = from <= this.html.length ? this.html.indexOf(needle, from) : -1;

        return found === -1 ? this.html.length : found + needle.length;
    }

    /** Reads a start tag's attributes from `at`, just after its name. A tag the input ends inside is dropped. */
    private startTag(name: string, from: number): number {
        const length = this.html.length;
        const attributes = new Map<string, string>();
        let selfClosing = false;
        let at = from;

        for (;;) {
            at += matchAt(SPACE, this.html, at)?.[0].length ?? 0;

            if (at >= length) {
                return length;
            }
            if (this.html[at] === '>') {
                at++;
                break;
            }
            if (this.html[at] === '/') {
                at++;
                if (at < length && this.html[at] === '>') {
                    selfClosing = true;
                    at++;
                    break;
                }
                continue;
            }

            const raw = matchAt(ATTRIBUTE_NAME, this.html, at)?.[0] ?? this.html[at]!;
            at += raw.length;

            let value = '';
            const equals = matchAt(EQUALS, this.html, at);

            if (equals !== null) {
                at += equals[0].length;

                const quoted = matchAt(VALUE, this.html, at);

                if (quoted !== null) {
                    at += quoted[0].length;
                    value = decodeEntities(quoted[1] ?? quoted[2] ?? quoted[3] ?? '');
                }
            }

            const attribute = asciiLower(raw);

            if (!attributes.has(attribute)) {
                attributes.set(attribute, value);
            }
        }

        this.start(name, attributes, selfClosing);

        return RAW.has(name) && !selfClosing ? this.rawText(name, at) : at;
    }

    /** The content of a raw text element runs to its end tag, which is then read as a tag. */
    private rawText(name: string, at: number): number {
        const pattern = new RegExp(`<\\/${name}(?=[\\t\\n\\f\\r \\/>]|$)`, 'gi');
        pattern.lastIndex = at;
        const found = pattern.exec(this.html);
        const end = found === null ? this.html.length : found.index;

        if (end > at) {
            const raw = this.html.slice(at, end);
            this.current().append(ESCAPABLE_RAW.has(name) ? decodeEntities(raw) : raw);
        }

        return end;
    }

    private start(name: string, attributes: ReadonlyMap<string, string>, selfClosing: boolean): void {
        if (CLOSES_P.has(name)) {
            this.close(['p'], SCOPE);
        }

        if (name === 'li') {
            this.close(['li'], [...SCOPE, 'menu', 'ol', 'ul']);
        } else if (name === 'dd' || name === 'dt') {
            this.close(['dd', 'dt'], [...SCOPE, 'dl']);
        } else if (HEADINGS.has(name)) {
            if (this.stack.length > 0 && HEADINGS.has(this.current().name)) {
                this.stack.pop();
            }
        } else if (name === 'tr') {
            this.close(['tr'], ['table', ...SECTIONS]);
        } else if (name === 'td' || name === 'th') {
            this.close(['td', 'th'], ['table', 'tr', ...SECTIONS]);
        } else if (SECTIONS.includes(name)) {
            if (!this.close(SECTIONS, ['table'])) {
                this.close(['tr'], ['table']);
            }
        }

        const element = new Element(name, attributes);
        this.current().append(element);

        if (!selfClosing && !VOID.has(name)) {
            this.stack.push(element);
        }
    }

    private end(name: string): void {
        if (name === 'br') {
            this.start('br', new Map(), false);

            return;
        }

        this.close([name], name === 'table' ? [] : TABLE_PARTS.has(name) ? ['table'] : SCOPE);
    }

    /**
     * Closes the nearest open element named in `targets`, with everything opened inside it,
     * unless an element named in `boundaries` is reached first. The document node never closes.
     */
    private close(targets: readonly string[], boundaries: readonly string[]): boolean {
        for (let i = this.stack.length - 1; i >= 0; i--) {
            const name = this.stack[i]!.name;

            if (targets.includes(name)) {
                this.stack.length = i;

                return true;
            }
            if (boundaries.includes(name)) {
                return false;
            }
        }

        return false;
    }

    private text(text: string): void {
        this.current().append(decodeEntities(text));
    }

    private current(): Element {
        return this.stack.at(-1) ?? this.root;
    }
}

function matchAt(pattern: RegExp, text: string, at: number): RegExpExecArray | null {
    pattern.lastIndex = at;

    return pattern.exec(text);
}
