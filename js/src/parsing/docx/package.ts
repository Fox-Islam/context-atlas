import { readFileSync, statSync } from 'node:fs';
import { basename } from 'node:path';
import { ContextAtlasError } from '../../errors.js';
import { parseXml, type XmlElement, type XmlNode } from './xml.js';
import { ZipArchive } from './zip.js';

/** The parts unpacked from one package may total at most 50 MB. */
export const LIMIT = 50 * 1024 * 1024;

export const W: readonly string[] = ['http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'http://purl.oclc.org/ooxml/wordprocessingml/main'];

/** Parts whose elements nest deeper than this are refused before parsing. */
const MAX_DEPTH = 1000;

const REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

const DEFAULTS: readonly [string, string][] = [
    ['styles', 'word/styles.xml'],
    ['numbering', 'word/numbering.xml'],
    ['footnotes', 'word/footnotes.xml'],
    ['endnotes', 'word/endnotes.xml'],
];

export interface Parts {
    document: XmlElement;
    styles: XmlElement | null;
    numbering: XmlElement | null;
    footnotes: XmlElement | null;
    endnotes: XmlElement | null;
    core: XmlElement | null;
}

/**
 * The parts of a DOCX package the reader uses, found through the package relationships and parsed
 * as XML. Refuses packages whose parts unpack to more than 50 MB and XML that declares a DOCTYPE,
 * so no entity is expanded and nothing is fetched (spec/formats/docx.md).
 *
 * @throws {ContextAtlasError} with the spec's fixed messages
 */
export function openPackage(path: string): Parts {
    const name = basename(path);
    let bytes: Uint8Array;

    try {
        if (!statSync(path).isFile()) {
            throw new Error('Not a file.');
        }
        bytes = readFileSync(path);
    } catch {
        throw new ContextAtlasError(`Cannot open ${name} as a DOCX document.`);
    }

    return packageParts(bytes, name);
}

/** The parts of a package already in memory, named `name` in messages. */
export function packageParts(bytes: Uint8Array, name: string): Parts {
    let zip: ZipArchive;

    try {
        zip = new ZipArchive(bytes);
    } catch {
        throw new ContextAtlasError(`Cannot open ${name} as a DOCX document.`);
    }

    return new Package(zip, name).parts();
}

class Package {
    private read = 0;

    constructor(
        private readonly zip: ZipArchive,
        private readonly name: string,
    ) {}

    parts(): Parts {
        const root = this.relationships('_rels/.rels', '');
        const main = root.get('officeDocument') ?? 'word/document.xml';
        const document = this.xml(main);

        if (document === null) {
            throw new ContextAtlasError(`${this.name} has no main document part (${main}).`);
        }

        const slash = main.lastIndexOf('/');
        const folder = slash === -1 ? '' : main.slice(0, slash + 1);
        const related = this.relationships(`${folder}_rels/${main.slice(slash + 1)}.rels`, folder);
        const parts: Record<string, XmlElement | null> = {};

        for (const [type, fallback] of DEFAULTS) {
            parts[type] = this.xml(related.get(type) ?? fallback);
        }

        return {
            document,
            styles: parts['styles']!,
            numbering: parts['numbering']!,
            footnotes: parts['footnotes']!,
            endnotes: parts['endnotes']!,
            core: this.xml(root.get('core-properties') ?? 'docProps/core.xml'),
        };
    }

    /**
     * Relationship type (the last segment of its `Type`) to part path, for internal targets: a
     * target starting with `/` is from the package root, any other from `folder`.
     */
    private relationships(part: string, folder: string): Map<string, string> {
        const rels = this.xml(part);
        const targets = new Map<string, string>();

        if (rels === null) {
            return targets;
        }

        for (const relation of descendants(rels, REL, 'Relationship')) {
            const type = attributeOf(relation, 'Type');
            const key = type.slice(type.lastIndexOf('/') + 1);

            if (attributeOf(relation, 'TargetMode') === 'External' || targets.has(key)) {
                continue;
            }

            const target = attributeOf(relation, 'Target');
            targets.set(key, normalise(target.startsWith('/') ? target.slice(1) : folder + target));
        }

        return targets;
    }

    /** The root element of a part, or null when the package has no such part. */
    private xml(part: string): XmlElement | null {
        const entry = this.zip.stat(part);

        if (entry === null) {
            return null;
        }

        this.read += entry.size;

        if (this.read > LIMIT) {
            throw new ContextAtlasError(`${this.name} is too large: its parts unpack to more than 50 MB.`);
        }

        const raw = this.zip.read(entry, entry.size + 1);

        if (raw === null || raw.length !== entry.size) {
            throw new ContextAtlasError(`${part} in ${this.name} cannot be read.`);
        }

        let bytes = raw[0] === 0xef && raw[1] === 0xbb && raw[2] === 0xbf ? raw.subarray(3) : raw;
        const latin = Buffer.from(bytes).toString('latin1');

        if (/<!DOCTYPE/i.test(latin)) {
            throw new ContextAtlasError(`${part} in ${this.name} declares a DOCTYPE, which is not read.`);
        }

        const declaration = /^[ \t\n\v\f\r]*<\?xml[^>]*\?>/.exec(latin);

        if (declaration !== null) {
            bytes = bytes.subarray(declaration[0].length);
        }
        if (depth(Buffer.from(bytes.buffer, bytes.byteOffset, bytes.byteLength)) > MAX_DEPTH) {
            throw new ContextAtlasError(`${part} in ${this.name} is nested more than ${MAX_DEPTH} elements deep.`);
        }

        let root: XmlElement;

        try {
            root = parseXml(new TextDecoder('utf-8', { fatal: true, ignoreBOM: true }).decode(bytes));
        } catch {
            throw new ContextAtlasError(`${part} in ${this.name} is not well-formed XML.`);
        }

        return root;
    }
}

/**
 * How deep a part's elements nest, judged from its tags alone: comments, CDATA sections and
 * processing instructions are skipped, `</` closes a level (never below 0), and any other tag
 * opens one unless it ends in `/>`. An unclosed tag ends the scan.
 */
function depth(bytes: Buffer): number {
    let level = 0;
    let deepest = 0;

    for (let at = bytes.indexOf(0x3c); at !== -1 && at < bytes.length; at = bytes.indexOf(0x3c, at + 1)) {
        if (startsWith(bytes, at, '<!--')) {
            const end = bytes.indexOf('-->', at + 4, 'latin1');
            at = end === -1 ? bytes.length : end + 2;
        } else if (startsWith(bytes, at, '<![CDATA[')) {
            const end = bytes.indexOf(']]>', at + 9, 'latin1');
            at = end === -1 ? bytes.length : end + 2;
        } else if (bytes[at + 1] === 0x3f) {
            const end = bytes.indexOf('?>', at + 2, 'latin1');
            at = end === -1 ? bytes.length : end + 1;
        } else if (bytes[at + 1] === 0x2f) {
            level = Math.max(0, level - 1);
        } else {
            const close = tagEnd(bytes, at + 1);

            if (close === null) {
                break;
            }
            if (bytes[close - 1] !== 0x2f) {
                deepest = Math.max(deepest, ++level);
            }
            at = close;
        }
    }

    return deepest;
}

/** The position of the `>` ending a tag, the first outside quoted attribute values, or null. */
function tagEnd(bytes: Buffer, from: number): number | null {
    let quote: number | null = null;

    for (let i = from; i < bytes.length; i++) {
        const char = bytes[i]!;

        if (quote !== null) {
            quote = char === quote ? null : quote;
        } else if (char === 0x22 || char === 0x27) {
            quote = char;
        } else if (char === 0x3e) {
            return i;
        }
    }

    return null;
}

function startsWith(bytes: Buffer, at: number, text: string): boolean {
    return bytes.toString('latin1', at, at + text.length) === text;
}

/** A path with `.` and empty segments dropped, and each `..` removing the segment before it. */
function normalise(path: string): string {
    const segments: string[] = [];

    for (const segment of path.split('/')) {
        if (segment === '..') {
            segments.pop();
        } else if (segment !== '.' && segment !== '') {
            segments.push(segment);
        }
    }

    return segments.join('/');
}

/** Descendant elements with this name, in document order. */
function descendants(element: XmlElement, ns: string, local: string): XmlElement[] {
    const found: XmlElement[] = [];
    const stack: XmlNode[] = [...element.children].reverse();

    while (stack.length > 0) {
        const node = stack.pop()!;

        if (typeof node === 'string') {
            continue;
        }
        if (node.ns === ns && node.local === local) {
            found.push(node);
        }
        for (let i = node.children.length - 1; i >= 0; i--) {
            stack.push(node.children[i]!);
        }
    }

    return found;
}

/** An unqualified attribute's value, or `''`, as DOM's `getAttribute` gives it. */
function attributeOf(element: XmlElement, name: string): string {
    return element.attributes.find((attribute) => attribute.ns === null && attribute.local === name)?.value ?? '';
}

export function isW(node: unknown, local: string): node is XmlElement {
    return typeof node === 'object' && node !== null && (node as XmlElement).local === local && W.includes((node as XmlElement).ns ?? '');
}

/** A `w:` attribute's value, or null. */
export function wAttribute(element: XmlElement, local: string): string | null {
    for (const ns of W) {
        const found = element.attributes.find((attribute) => attribute.ns === ns && attribute.local === local);

        if (found !== undefined) {
            return found.value;
        }
    }

    return null;
}

/** The first child element `w:<local>`, or null. */
export function wChild(element: XmlElement | null, local: string): XmlElement | null {
    return (element?.children.find((child) => isW(child, local)) as XmlElement | undefined) ?? null;
}

/** The `w:val` of the first child element `w:<local>`, or null. */
export function wValue(element: XmlElement | null, local: string): string | null {
    const child = wChild(element, local);

    return child === null ? null : wAttribute(child, 'val');
}

/** An integer attribute value: `^-?[0-9]{1,9}$` exactly. */
export function integer(value: string | null): number | null {
    return value !== null && /^-?[0-9]{1,9}$/.test(value) ? Number.parseInt(value, 10) : null;
}
