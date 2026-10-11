/**
 * A namespace-aware XML 1.0 reader for DOCX parts, accepting what libxml2's `loadXML` accepts as
 * PHP uses it (`LIBXML_PARSEHUGE`, refusing any error above warning level): a well-formedness
 * error and a namespace error are both fatal, and no size or depth limit applies. There is no
 * DTD: only the predefined entities and character references exist. The reader keeps its own
 * stack of open elements, so nesting depth is bounded by memory, not the call stack.
 */
export interface XmlElement {
    readonly ns: string | null;
    readonly local: string;
    readonly attributes: readonly XmlAttribute[];
    readonly children: readonly XmlNode[];
}

export interface XmlAttribute {
    readonly ns: string | null;
    readonly local: string;
    readonly value: string;
}

export type XmlNode = XmlElement | string;

const XML_NS = 'http://www.w3.org/XML/1998/namespace';

const XMLNS_NS = 'http://www.w3.org/2000/xmlns/';

const PCT = '%[0-9A-Fa-f]{2}';

const UNRESERVED = "A-Za-z0-9\\-._~";

const SUB_DELIMS = "!$&'()*+,;=";

const PCHAR = `(?:[${UNRESERVED}${SUB_DELIMS}:@]|${PCT})`;

const SEGMENT_NZ_NC = `(?:[${UNRESERVED}${SUB_DELIMS}@]|${PCT})+`;

const AUTHORITY = `(?:(?:[${UNRESERVED}${SUB_DELIMS}:]|${PCT})*@)?(?:\\[[0-9A-Fa-f:.vV${UNRESERVED}${SUB_DELIMS}]*\\]|(?:[${UNRESERVED}${SUB_DELIMS}]|${PCT})*)(?::[0-9]*)?`;

const TAIL = `(?:\\?(?:${PCHAR}|[/?])*)?(?:#(?:${PCHAR}|[/?])*)?`;

const ABSOLUTE = `[A-Za-z][A-Za-z0-9+\\-.]*:(?://${AUTHORITY}(?:/${PCHAR}*)*|/(?:${PCHAR}+(?:/${PCHAR}*)*)?|${PCHAR}+(?:/${PCHAR}*)*|)`;

const RELATIVE = `(?://${AUTHORITY}(?:/${PCHAR}*)*|/(?:${PCHAR}+(?:/${PCHAR}*)*)?|${SEGMENT_NZ_NC}(?:/${PCHAR}*)*|)`;

/** An RFC 3986 URI reference, as libxml2 checks a namespace name. */
const URI_REFERENCE = new RegExp(`^(?:${ABSOLUTE}|${RELATIVE})${TAIL}$`);

const NAME_START = ':A-Z_a-z\\u00C0-\\u00D6\\u00D8-\\u00F6\\u00F8-\\u02FF\\u0370-\\u037D\\u037F-\\u1FFF\\u200C-\\u200D\\u2070-\\u218F\\u2C00-\\u2FEF\\u3001-\\uD7FF\\uF900-\\uFDCF\\uFDF0-\\uFFFD\\u{10000}-\\u{EFFFF}';

const NAME_CHAR = `${NAME_START}\\-.0-9\\u00B7\\u0300-\\u036F\\u203F-\\u2040`;

const NAME = new RegExp(`[${NAME_START}][${NAME_CHAR}]*`, 'uy');

const NCNAME = new RegExp(`^[${NAME_START.slice(1)}][${NAME_CHAR.replace(':', '')}]*$`, 'u');

const INVALID_CHAR = new RegExp('[^\\t\\n\\r\\u0020-\\uD7FF\\uE000-\\uFFFD\\u{10000}-\\u{10FFFF}]', 'u');

const SPACE = /[ \t\r\n]/;

/** Thrown for XML that is not well-formed. */
export class XmlError extends Error {}

interface Open {
    name: string;
    element: { ns: string | null; local: string; attributes: readonly XmlAttribute[]; children: XmlNode[] };
    bindings: ReadonlyMap<string, string>;
    pending: string;
}

const NO_ATTRIBUTES: readonly XmlAttribute[] = Object.freeze([]);

/** The root element of a document; throws XmlError when the text is not well-formed. */
export function parseXml(source: string): XmlElement {
    const text = source.replace(/\r\n?/g, '\n');

    if (INVALID_CHAR.test(text)) {
        throw new XmlError('Invalid character.');
    }

    return new Reader(text).document();
}

class Reader {
    private at = 0;

    constructor(private readonly text: string) {}

    document(): XmlElement {
        if (this.text.startsWith('\uFEFF')) {
            this.at = 1;
        }
        this.misc();
        if (this.text[this.at] !== '<') {
            throw new XmlError('Start tag expected.');
        }

        const root = this.elements();
        this.misc();
        if (this.at !== this.text.length) {
            throw new XmlError('Extra content at the end of the document.');
        }

        return root;
    }

    private misc(): void {
        for (;;) {
            this.space();
            if (this.text.startsWith('<!--', this.at)) {
                this.comment();
            } else if (this.text.startsWith('<?', this.at)) {
                this.instruction();
            } else {
                return;
            }
        }
    }

    /** The element starting at the cursor and everything in it, read with an explicit stack. */
    private elements(): XmlElement {
        const stack: Open[] = [];
        let open = this.startTag(new Map([['xml', XML_NS]]));

        if (open.empty) {
            return open.frame.element;
        }
        stack.push(open.frame);

        for (;;) {
            const top = stack[stack.length - 1]!;
            const next = this.text.indexOf('<', this.at);

            if (next === -1) {
                throw new XmlError('Premature end of data.');
            }

            const chunk = this.text.slice(this.at, next);

            if (chunk.includes(']]>')) {
                throw new XmlError("Sequence ']]>' not allowed in content.");
            }
            top.pending += references(chunk);
            this.at = next;

            if (this.text.startsWith('</', this.at)) {
                this.at += 2;
                const closing = this.name();
                this.space();
                this.expect('>');
                if (closing !== top.name) {
                    throw new XmlError('Opening and ending tag mismatch.');
                }
                if (top.pending !== '') {
                    top.element.children.push(top.pending);
                }
                stack.pop();
                if (stack.length === 0) {
                    return top.element;
                }
                stack[stack.length - 1]!.element.children.push(top.element);
            } else if (this.text.startsWith('<!--', this.at)) {
                this.comment();
            } else if (this.text.startsWith('<![CDATA[', this.at)) {
                const end = this.text.indexOf(']]>', this.at + 9);

                if (end === -1) {
                    throw new XmlError('CData section not finished.');
                }
                top.pending += this.text.slice(this.at + 9, end);
                this.at = end + 3;
            } else if (this.text.startsWith('<?', this.at)) {
                this.instruction();
            } else if (this.text.startsWith('<!', this.at)) {
                throw new XmlError('Unexpected markup.');
            } else {
                if (top.pending !== '') {
                    top.element.children.push(top.pending);
                    top.pending = '';
                }
                open = this.startTag(top.bindings);
                if (open.empty) {
                    top.element.children.push(open.frame.element);
                } else {
                    stack.push(open.frame);
                }
            }
        }
    }

    /** A start or empty-element tag, its names bound to namespaces. */
    private startTag(scope: ReadonlyMap<string, string>): { frame: Open; empty: boolean } {
        this.at++;
        const name = this.name();
        const raw: { name: string; value: string }[] = [];
        const seen = new Set<string>();
        let empty = false;

        for (;;) {
            const spaced = this.space();

            if (this.text.startsWith('/>', this.at)) {
                this.at += 2;
                empty = true;
                break;
            }
            if (this.text[this.at] === '>') {
                this.at++;
                break;
            }
            if (!spaced) {
                throw new XmlError('Attributes construct error.');
            }

            const attribute = this.name();
            this.space();
            this.expect('=');
            this.space();

            const quote = this.text[this.at];

            if (quote !== '"' && quote !== "'") {
                throw new XmlError('AttValue: " or \' expected.');
            }
            this.at++;

            const end = this.text.indexOf(quote, this.at);

            if (end === -1) {
                throw new XmlError('Unterminated attribute value.');
            }

            const value = this.text.slice(this.at, end);

            if (value.includes('<')) {
                throw new XmlError("Unescaped '<' not allowed in attributes values.");
            }
            this.at = end + 1;
            if (seen.has(attribute)) {
                throw new XmlError('Attribute redefined.');
            }
            seen.add(attribute);
            raw.push({ name: attribute, value: references(value.replace(/[\t\n]/g, ' ')) });
        }

        const bindings = bind(raw, scope);
        const [ns, local] = qualify(name, false, bindings);
        const attributes = qualifyAttributes(raw, bindings);

        return { frame: { name, element: { ns, local, attributes, children: [] }, bindings, pending: '' }, empty };
    }

    private comment(): void {
        const end = this.text.indexOf('--', this.at + 4);

        if (end === -1 || this.text[end + 2] !== '>') {
            throw new XmlError('Double hyphen within comment, or comment not terminated.');
        }
        this.at = end + 3;
    }

    private instruction(): void {
        this.at += 2;
        const target = this.name();

        if (/^xml$/i.test(target)) {
            throw new XmlError('XML declaration allowed only at the start of the document.');
        }

        const end = this.text.indexOf('?>', this.at);

        if (end === -1) {
            throw new XmlError('PI not terminated.');
        }
        if (end !== this.at && !SPACE.test(this.text[this.at]!)) {
            throw new XmlError('ParsePI: PI space expected.');
        }
        this.at = end + 2;
    }

    private name(): string {
        NAME.lastIndex = this.at;
        const match = NAME.exec(this.text);

        if (match === null) {
            throw new XmlError('Invalid name.');
        }
        this.at += match[0].length;

        return match[0];
    }

    private space(): boolean {
        const start = this.at;

        while (this.at < this.text.length && SPACE.test(this.text[this.at]!)) {
            this.at++;
        }

        return this.at > start;
    }

    private expect(char: string): void {
        if (this.text[this.at] !== char) {
            throw new XmlError(`Expected ${char}.`);
        }
        this.at++;
    }
}

/** Character and predefined entity references decoded; anything else after `&` is fatal. */
function references(text: string): string {
    if (!text.includes('&')) {
        return text;
    }

    return text.replace(/&([^;&]*);?/g, (whole, body: string) => {
        if (!whole.endsWith(';')) {
            throw new XmlError("EntityRef: expecting ';'.");
        }

        let code: number;

        if (/^#x[0-9a-fA-F]+$/.test(body)) {
            code = Number.parseInt(body.slice(2), 16);
        } else if (/^#[0-9]+$/.test(body)) {
            code = Number.parseInt(body.slice(1), 10);
        } else {
            const named: Record<string, string> = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" };

            if (!Object.hasOwn(named, body)) {
                throw new XmlError('Entity not defined.');
            }

            return named[body]!;
        }

        const valid = code === 0x9 || code === 0xa || code === 0xd || (code >= 0x20 && code <= 0xd7ff) || (code >= 0xe000 && code <= 0xfffd) || (code >= 0x10000 && code <= 0x10ffff);

        if (!valid) {
            throw new XmlError('Invalid character reference.');
        }

        return String.fromCodePoint(code);
    });
}

/**
 * The namespace bindings in scope inside an element. libxml2 reports a namespace problem as an
 * error, which PHP's reader refuses like a well-formedness error: an undeclared prefix, a
 * malformed qualified name, a namespaced attribute given twice, a reserved prefix or namespace
 * misused, an empty prefixed namespace, and a namespace name that is not an RFC 3986 URI
 * reference.
 */
function bind(raw: readonly { name: string; value: string }[], scope: ReadonlyMap<string, string>): ReadonlyMap<string, string> {
    let bindings: Map<string, string> | null = null;

    for (const { name, value } of raw) {
        if (name === 'xmlns') {
            if (value === XML_NS || value === XMLNS_NS || (value !== '' && !URI_REFERENCE.test(value))) {
                throw new XmlError('Bad default namespace.');
            }
            (bindings ??= new Map(scope)).set('', value);
        } else if (name.startsWith('xmlns:')) {
            const prefix = name.slice(6);

            if (!NCNAME.test(prefix) || value === '' || value === XMLNS_NS || prefix === 'xmlns' || (prefix === 'xml') !== (value === XML_NS) || !URI_REFERENCE.test(value)) {
                throw new XmlError('Bad namespace declaration.');
            }
            (bindings ??= new Map(scope)).set(prefix, value);
        }
    }

    return bindings ?? scope;
}

function qualify(name: string, isAttribute: boolean, bindings: ReadonlyMap<string, string>): [string | null, string] {
    const colon = name.indexOf(':');

    if (colon === -1) {
        const ns = isAttribute ? null : bindings.get('') ?? null;

        return [ns === '' ? null : ns, name];
    }

    const prefix = name.slice(0, colon);
    const local = name.slice(colon + 1);
    const ns = bindings.get(prefix);

    if (!NCNAME.test(prefix) || !NCNAME.test(local) || ns === undefined || ns === '') {
        throw new XmlError('Bad qualified name or undeclared prefix.');
    }

    return [ns, local];
}

function qualifyAttributes(raw: readonly { name: string; value: string }[], bindings: ReadonlyMap<string, string>): readonly XmlAttribute[] {
    const attributes: XmlAttribute[] = [];
    const seen = new Set<string>();

    for (const { name, value } of raw) {
        if (name === 'xmlns' || name.startsWith('xmlns:')) {
            continue;
        }

        const [ns, local] = qualify(name, true, bindings);
        const key = `${ns ?? ''} ${local}`;

        if (seen.has(key)) {
            throw new XmlError('Namespaced attribute redefined.');
        }
        seen.add(key);
        attributes.push({ ns, local, value });
    }

    return attributes.length === 0 ? NO_ATTRIBUTES : attributes;
}

/** The text of an element and everything in it, in document order. */
export function textContent(element: XmlElement): string {
    let out = '';
    const stack: XmlNode[] = [element];

    while (stack.length > 0) {
        const node = stack.pop()!;

        if (typeof node === 'string') {
            out += node;
        } else {
            for (let i = node.children.length - 1; i >= 0; i--) {
                stack.push(node.children[i]!);
            }
        }
    }

    return out;
}
