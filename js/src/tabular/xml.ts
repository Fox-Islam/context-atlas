/**
 * Just enough XML for the parts of an XLSX package: elements, attributes, text, CDATA and the
 * predefined and numeric entities. Comments, processing instructions and a DOCTYPE are skipped;
 * no entity is expanded from a DTD and nothing is fetched.
 *
 * Children are found by their qualified name, as PHP's SimpleXML finds them without a namespace
 * argument: `sheetData` matches `<sheetData>` and not `<x:sheetData>`.
 */
export class XmlElement {
    readonly children: XmlElement[] = [];

    /** The element's own text nodes, joined: SimpleXML's `(string)` of an element. */
    text = '';

    constructor(
        readonly name: string,
        readonly attributes: ReadonlyMap<string, string>,
    ) {}

    child(name: string): XmlElement | null {
        return this.children.find((element) => element.name === name) ?? null;
    }

    all(name: string): XmlElement[] {
        return this.children.filter((element) => element.name === name);
    }

    attribute(name: string): string {
        return this.attributes.get(name) ?? '';
    }
}

const ENTITIES: Readonly<Record<string, string>> = { lt: '<', gt: '>', amp: '&', quot: '"', apos: "'" };

/** @throws {Error} on XML this reader cannot follow */
export function parseXml(source: string): XmlElement {
    const xml = source.replace(/\r\n?/g, '\n');
    const stack: XmlElement[] = [];
    let root: XmlElement | null = null;
    let i = 0;

    while (i < xml.length) {
        const open = xml.indexOf('<', i);
        const textEnd = open === -1 ? xml.length : open;

        if (textEnd > i && stack.length > 0) {
            stack[stack.length - 1]!.text += decode(xml.slice(i, textEnd));
        }
        if (open === -1) {
            break;
        }

        if (xml.startsWith('<!--', open)) {
            i = after(xml, '-->', open);
        } else if (xml.startsWith('<![CDATA[', open)) {
            const end = xml.indexOf(']]>', open);

            if (end === -1) {
                throw new Error('Unclosed CDATA section.');
            }
            if (stack.length > 0) {
                stack[stack.length - 1]!.text += xml.slice(open + 9, end);
            }
            i = end + 3;
        } else if (xml.startsWith('<?', open)) {
            i = after(xml, '?>', open);
        } else if (xml.startsWith('<!', open)) {
            i = after(xml, '>', open);
        } else if (xml.startsWith('</', open)) {
            const end = after(xml, '>', open);
            const name = xml.slice(open + 2, end - 1).trim();

            if (stack.pop()?.name !== name) {
                throw new Error(`Mismatched closing tag </${name}>.`);
            }
            i = end;
        } else {
            const [element, end, selfClosing] = tag(xml, open);

            if (stack.length > 0) {
                stack[stack.length - 1]!.children.push(element);
            } else if (root === null) {
                root = element;
            } else {
                throw new Error('More than one root element.');
            }
            if (!selfClosing) {
                stack.push(element);
            }
            i = end;
        }
    }

    if (root === null || stack.length > 0) {
        throw new Error('Not a complete XML document.');
    }

    return root;
}

function tag(xml: string, open: number): [XmlElement, number, boolean] {
    const pattern = /\s*([^\s=/>]+)\s*=\s*(?:"([^"]*)"|'([^']*)')|\s*(\/?)>/gy;
    const name = /^<([^\s/>]+)/.exec(xml.slice(open, open + 512))?.[1];

    if (name === undefined) {
        throw new Error('A tag without a name.');
    }

    const attributes = new Map<string, string>();
    pattern.lastIndex = open + 1 + name.length;

    for (;;) {
        const match = pattern.exec(xml);

        if (match === null) {
            throw new Error(`Cannot read the <${name}> tag.`);
        }
        if (match[1] === undefined) {
            return [new XmlElement(name, attributes), pattern.lastIndex, match[4] === '/'];
        }
        attributes.set(match[1], decode(match[2] ?? match[3] ?? ''));
    }
}

function after(xml: string, marker: string, from: number): number {
    const end = xml.indexOf(marker, from);

    if (end === -1) {
        throw new Error(`Expected ${marker}.`);
    }

    return end + marker.length;
}

function decode(text: string): string {
    return text.replace(/&(#x[0-9a-fA-F]+|#\d+|[a-zA-Z]+);/g, (whole, entity: string) => {
        if (entity.startsWith('#x')) {
            return String.fromCodePoint(Number.parseInt(entity.slice(2), 16));
        }
        if (entity.startsWith('#')) {
            return String.fromCodePoint(Number.parseInt(entity.slice(1), 10));
        }

        return ENTITIES[entity] ?? whole;
    });
}
