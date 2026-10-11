import { ContextAtlasError } from '../errors.js';
import { phpString } from '../support/php.js';
import { Link, type LinkObject } from './link.js';
import { Unit, type UnitObject } from './unit.js';

export interface Lexical {
    /** Unit id to its term count. */
    length: Record<string, number>;
    /**
     * Term to unit id to count, units in document order. Terms are written in byte order; a
     * JavaScript object lists a term that looks like an array index, such as `2019`, first.
     */
    terms: Record<string, Record<string, number>>;
}

export interface Detected {
    profile: string;
    score: number | null;
    by: string;
}

export interface DocumentObject {
    format: 2;
    id: string;
    profile: string;
    detected: Detected | null;
    units: UnitObject[];
    links: LinkObject[];
    lexical: Lexical;
    data: Record<string, unknown>;
}

/**
 * Everything ingestion builds for one input document, as plain data the caller stores where it
 * likes and hands back at retrieval. spec/document.md describes the JSON shape, which the PHP,
 * JavaScript and Python packages read and write alike.
 */
export class Document {
    static readonly FORMAT = 2;

    constructor(
        readonly id: string,
        readonly profile: string,
        readonly units: readonly Unit[],
        readonly links: readonly Link[] = [],
        readonly lexical: Lexical = { length: {}, terms: {} },
        readonly detected: Detected | null = null,
        readonly data: Readonly<Record<string, unknown>> = {},
    ) {}

    /** @throws {ContextAtlasError} when the value is not a format 2 document */
    static fromObject(value: Record<string, unknown>): Document {
        if (value['format'] !== Document.FORMAT) {
            throw new ContextAtlasError(`Not a format ${Document.FORMAT} document.`);
        }

        const lexical = record(value['lexical']);

        return new Document(
            phpString(value['id']),
            phpString(value['profile']),
            list(value['units']).map((unit) => Unit.fromObject(record(unit))),
            list(value['links']).map((link) => Link.fromObject(record(link))),
            {
                length: record(lexical['length']) as Record<string, number>,
                terms: record(lexical['terms']) as Record<string, Record<string, number>>,
            },
            detectedOf(value['detected']),
            record(value['data']),
        );
    }

    unit(id: string): Unit | null {
        return this.units.find((unit) => unit.id === id) ?? null;
    }

    toObject(): DocumentObject {
        return {
            format: Document.FORMAT,
            id: this.id,
            profile: this.profile,
            detected: this.detected === null ? null : { ...this.detected },
            units: this.units.map((unit) => unit.toObject()),
            links: this.links.map((link) => link.toObject()),
            lexical: { length: this.lexical.length, terms: this.lexical.terms },
            data: { ...this.data },
        };
    }

    toJSON(): DocumentObject {
        return this.toObject();
    }
}

/** A JSON object, or `[]` as PHP writes an empty map, as a record. */
function record(value: unknown): Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value) ? value as Record<string, unknown> : {};
}

function list(value: unknown): unknown[] {
    if (Array.isArray(value)) {
        return value;
    }

    return typeof value === 'object' && value !== null ? Object.values(value) : [];
}

function detectedOf(value: unknown): Detected | null {
    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const fields = value as Record<string, unknown>;
    const score = fields['score'];

    return {
        profile: phpString(fields['profile'] ?? ''),
        score: score === undefined || score === null ? null : Number(score),
        by: phpString(fields['by'] ?? ''),
    };
}
