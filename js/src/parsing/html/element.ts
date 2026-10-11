/**
 * An element of the tree `buildTree` makes: a lowercase name, its attributes (lowercase names,
 * decoded values, the first of a repeated name kept) and its children, text as strings.
 */
export class Element {
    readonly children: (Element | string)[] = [];

    constructor(
        readonly name: string,
        readonly attributes: ReadonlyMap<string, string> = new Map(),
    ) {}

    attribute(name: string): string | null {
        return this.attributes.get(name) ?? null;
    }

    /** The attribute's value trimmed of ASCII whitespace and lowercased (ASCII), or null. */
    token(name: string): string | null {
        const value = this.attributes.get(name);

        return value === undefined ? null : asciiLower(value.replace(/^[ \t\n\f\r]+|[ \t\n\f\r]+$/g, ''));
    }

    /** The attribute's value split at runs of ASCII whitespace, case kept. */
    tokens(name: string): string[] {
        return (this.attributes.get(name) ?? '').split(/[ \t\n\f\r]/).filter((token) => token !== '');
    }

    append(child: Element | string): void {
        const last = this.children.length - 1;

        if (typeof child === 'string' && last >= 0 && typeof this.children[last] === 'string') {
            this.children[last] += child;

            return;
        }

        this.children.push(child);
    }
}

/** PHP's `strtolower`: ASCII letters only. */
export function asciiLower(text: string): string {
    return text.replace(/[A-Z]+/g, (letters) => letters.toLowerCase());
}
