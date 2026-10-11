import { phpArray, phpString } from '../support/php.js';

export interface UnitObject {
    id: string;
    kind: string;
    text: string;
    path?: string[];
    meta?: Record<string, unknown>;
}

/**
 * One retrievable piece of a document: a passage, a table. `text` is what the caller embeds and
 * what retrieval returns; `path` locates it in the document.
 */
export class Unit {
    constructor(
        readonly id: string,
        readonly kind: string,
        readonly text: string,
        readonly path: readonly string[] = [],
        readonly meta: Readonly<Record<string, unknown>> = {},
    ) {}

    static fromObject(value: Record<string, unknown>): Unit {
        const meta = value['meta'];

        return new Unit(
            phpString(value['id']),
            phpString(value['kind']),
            phpString(value['text']),
            value['path'] === undefined || value['path'] === null ? [] : phpArray(value['path']).map(([, title]) => phpString(title)),
            meta === undefined || meta === null ? {} : Object.fromEntries(phpArray(meta)),
        );
    }

    /** `path` and `meta` are left out when empty. */
    toObject(): UnitObject {
        return {
            id: this.id,
            kind: this.kind,
            text: this.text,
            ...(this.path.length === 0 ? {} : { path: [...this.path] }),
            ...(Object.keys(this.meta).length === 0 ? {} : { meta: { ...this.meta } }),
        };
    }

    toJSON(): UnitObject {
        return this.toObject();
    }
}
