export interface ChunkObject {
    text: string;
    blocks: number[];
    heading_path: string[];
    heading_level: number | null;
    section: number;
    tokens: number;
    overlap: number;
    topic_start: boolean;
}

export interface OutlineEntry {
    block: number;
    title: string;
    level: number;
}

export interface ChunkedObject {
    chunks: ChunkObject[];
    outline: OutlineEntry[];
    used_jev: boolean;
}

/**
 * A passage ready to embed. `text` is the heading path in brackets, any
 * overlap carried from the previous chunk, then the chunk's own text; `tokens`
 * counts all of it and `overlap` the carried part. `blocks` are indexes into
 * the blocks passed to `Chunker.chunk`.
 */
export class Chunk {
    constructor(
        readonly text: string,
        readonly blocks: readonly number[],
        readonly headingPath: readonly string[],
        readonly headingLevel: number | null,
        readonly section: number,
        readonly tokens: number,
        readonly overlap: number,
        readonly topicStart: boolean,
    ) {}

    toObject(): ChunkObject {
        return {
            text: this.text,
            blocks: [...this.blocks],
            heading_path: [...this.headingPath],
            heading_level: this.headingLevel,
            section: this.section,
            tokens: this.tokens,
            overlap: this.overlap,
            topic_start: this.topicStart,
        };
    }

    toJSON(): ChunkObject {
        return this.toObject();
    }
}

/** The chunks of a document and its outline: every heading in order. */
export class Chunked {
    constructor(
        readonly chunks: readonly Chunk[],
        readonly outline: readonly OutlineEntry[],
        readonly usedJev: boolean,
    ) {}

    toObject(): ChunkedObject {
        return {
            chunks: this.chunks.map((chunk) => chunk.toObject()),
            outline: this.outline.map((entry) => ({ ...entry })),
            used_jev: this.usedJev,
        };
    }

    toJSON(): ChunkedObject {
        return this.toObject();
    }
}
