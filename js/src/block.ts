import { ContextAtlasError } from './errors.js';
import { phpArray } from './support/php.js';

export const BlockType = {
    Heading: 'heading',
    Paragraph: 'paragraph',
    Table: 'table',
    Code: 'code',
} as const;

export type BlockType = (typeof BlockType)[keyof typeof BlockType];

const TYPES: ReadonlySet<string> = new Set(Object.values(BlockType));

/** The JSON shape of a block, as `parseBlocks` reads it. */
export interface BlockObject {
    type: BlockType;
    text: string;
    level?: number;
    meta?: Record<string, unknown>;
}

/**
 * One unit of a document. A paragraph is the only type split into sentences
 * when it is too long; a table or a code block is kept whole. `meta` is carried
 * through and never read, so a caller can map chunks back to pages or offsets.
 * Only a heading has a level.
 */
export class Block {
    readonly level: number | null;

    constructor(
        readonly type: BlockType,
        readonly text: string,
        level: number | null = null,
        readonly meta: Readonly<Record<string, unknown>> = {},
    ) {
        this.level = type === BlockType.Heading ? level : null;
    }

    static heading(text: string, level: number, meta: Record<string, unknown> = {}): Block {
        return new Block(BlockType.Heading, text, level, meta);
    }

    static paragraph(text: string, meta: Record<string, unknown> = {}): Block {
        return new Block(BlockType.Paragraph, text, null, meta);
    }

    /** @throws {ContextAtlasError} on an unknown type, a text that is not a string, or a heading without an integer level */
    static fromObject(value: unknown): Block {
        const object = isRecord(value) ? value : {};
        const type = object['type'];
        const text = object['text'];
        const level = object['level'];

        if (typeof type !== 'string' || !TYPES.has(type) || typeof text !== 'string') {
            throw new ContextAtlasError('A block needs a "type" (heading, paragraph, table or code) and a "text".');
        }
        if (type === BlockType.Heading && !Number.isInteger(level)) {
            throw new ContextAtlasError('A heading block needs an integer "level".');
        }

        return new Block(type as BlockType, text, type === BlockType.Heading ? level as number : null, metaOf(object['meta']));
    }

    isHeading(): boolean {
        return this.type === BlockType.Heading;
    }

    /** `level` and `meta` are left out when empty. */
    toObject(): BlockObject {
        return {
            type: this.type,
            text: this.text,
            ...(this.level === null ? {} : { level: this.level }),
            ...(Object.keys(this.meta).length === 0 ? {} : { meta: { ...this.meta } }),
        };
    }

    toJSON(): BlockObject {
        return this.toObject();
    }
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

/** A scalar `meta` becomes `{"0": value}`, as PHP's `(array)` cast makes it. */
function metaOf(value: unknown): Record<string, unknown> {
    if (value === undefined || value === null) {
        return {};
    }

    return Object.fromEntries(phpArray(value));
}
