import type { Document } from '../document/document.js';

export interface Posting {
    document: string;
    unit: string;
    count: number;
    length: number;
}

export interface SourceStats {
    /** How many units the source holds. */
    units: number;
    /** Their total length in terms. */
    length: number;
}

type MaybePromise<T> = T | Promise<T>;

/**
 * Where retrieval reads ingested documents from. Collection holds them in memory; a caller with
 * a large collection implements this over its own table or key-value store, reading only the
 * terms and documents a query touches.
 */
export interface Source {
    /** Every unit holding one of the terms, per term in the order asked: documents in source order, units in the order the document's term map lists them. */
    postings(terms: readonly string[]): MaybePromise<ReadonlyMap<string, readonly Posting[]>>;

    stats(): MaybePromise<SourceStats>;

    document(id: string): MaybePromise<Document | null>;
}
