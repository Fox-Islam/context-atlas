import { integer, isW, wAttribute, wChild, wValue } from './package.js';
import type { XmlElement } from './xml.js';

const CHAIN = 20;

interface Style {
    name: string;
    basedOn: string | null;
    outline: number | null;
    numId: string | null;
    ilvl: number | null;
}

/**
 * Paragraph styles: each style's own name, and the outline level and numbering it has or inherits
 * through `w:basedOn` (spec/formats/docx.md, Styles).
 */
export class Styles {
    private readonly styles = new Map<string, Style>();

    private readonly defaultStyle: string | null = null;

    constructor(root: XmlElement | null) {
        for (const style of root?.children ?? []) {
            if (!isW(style, 'style') || wAttribute(style, 'type') !== 'paragraph') {
                continue;
            }

            const id = wAttribute(style, 'styleId') ?? '';

            if (this.styles.has(id)) {
                continue;
            }

            const properties = wChild(style, 'pPr');
            const numbering = wChild(properties, 'numPr');
            this.styles.set(id, {
                name: wValue(style, 'name') ?? '',
                basedOn: wValue(style, 'basedOn'),
                outline: integer(wValue(properties, 'outlineLvl')),
                numId: wValue(numbering, 'numId'),
                ilvl: integer(wValue(numbering, 'ilvl')),
            });
            if (this.defaultStyle === null && ['1', 'true', 'on'].includes(wAttribute(style, 'default') ?? '')) {
                this.defaultStyle = id;
            }
        }
    }

    /** A paragraph's style: its `w:pStyle` when that names a known style, else the default paragraph style. */
    resolve(id: string | null): string | null {
        return id !== null && this.styles.has(id) ? id : this.defaultStyle;
    }

    name(id: string | null): string {
        return id === null ? '' : this.styles.get(id)?.name ?? '';
    }

    /** The first value of `key` set on the style or the styles it is based on, visiting at most 20. */
    inherited<K extends 'outline' | 'numId' | 'ilvl'>(start: string | null, key: K): Style[K] | null {
        let id = start;

        for (let seen = 0; id !== null && this.styles.has(id) && seen < CHAIN; seen++) {
            const style = this.styles.get(id)!;

            if (style[key] !== null) {
                return style[key];
            }
            id = style.basedOn;
        }

        return null;
    }
}
