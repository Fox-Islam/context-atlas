import { phpArray, phpString } from '../support/php.js';

/** The JSON shape of a passage, as the `rank` command reads it. */
export interface PassageObject {
    id?: unknown;
    text?: unknown;
    heading_path?: unknown;
}

/** A search hit to rank: the caller's id for it, its text, and the heading path that names it for Jev. */
export class Passage {
    constructor(
        readonly id: string,
        readonly text: string,
        readonly headingPath: readonly string[] = [],
    ) {}

    /** Values are cast to strings as PHP casts them; a scalar `heading_path` is a path of one title. */
    static fromObject(value: PassageObject): Passage {
        const path = value.heading_path ?? [];
        const titles = typeof path === 'object' && path !== null ? phpArray(path).map(([, title]) => phpString(title)) : [phpString(path)];

        return new Passage(phpString(value.id ?? ''), phpString(value.text ?? ''), titles);
    }
}

export interface RankedObject {
    id: string;
    score: number;
}

export class RankedPassage {
    constructor(
        readonly passage: Passage,
        readonly score: number,
    ) {}

    toObject(): RankedObject {
        return { id: this.passage.id, score: this.score };
    }

    toJSON(): RankedObject {
        return this.toObject();
    }
}
