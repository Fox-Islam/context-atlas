import { Document, type DocumentObject } from '../document/document.js';
import type { Posting, Source, SourceStats } from './source.js';

/** Documents held in memory, in the order they were first put. */
export class Collection implements Source {
    private readonly byId = new Map<string, Document>();

    constructor(documents: Iterable<Document> = []) {
        for (const document of documents) {
            this.put(document);
        }
    }

    /** A collection of stored artifacts, as `Document.toObject()` wrote them. */
    static fromObjects(documents: readonly (DocumentObject | Record<string, unknown>)[]): Collection {
        return new Collection(documents.map((document) => Document.fromObject(document as Record<string, unknown>)));
    }

    /** A document already held keeps its place. */
    put(document: Document): void {
        this.byId.set(document.id, document);
    }

    remove(id: string): void {
        this.byId.delete(id);
    }

    document(id: string): Document | null {
        return this.byId.get(id) ?? null;
    }

    documents(): Document[] {
        return [...this.byId.values()];
    }

    postings(terms: readonly string[]): Map<string, Posting[]> {
        const out = new Map<string, Posting[]>();

        for (const term of terms) {
            const postings: Posting[] = [];

            for (const document of this.byId.values()) {
                const units = Object.hasOwn(document.lexical.terms, term) ? document.lexical.terms[term]! : {};

                for (const [unit, count] of Object.entries(units)) {
                    postings.push({ document: document.id, unit, count: Math.trunc(Number(count)), length: Math.trunc(Number(document.lexical.length[unit] ?? 0)) });
                }
            }
            out.set(term, postings);
        }

        return out;
    }

    stats(): SourceStats {
        let units = 0;
        let length = 0;

        for (const document of this.byId.values()) {
            const lengths = Object.values(document.lexical.length);
            units += lengths.length;
            length += lengths.reduce((sum, value) => sum + Number(value), 0);
        }

        return { units, length };
    }
}
