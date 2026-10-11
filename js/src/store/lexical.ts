/**
 * Exact-term search: per-unit term counts at ingestion, BM25 at query time. Embeddings miss
 * identifiers, names and codes that a reader types exactly; this finds them. There is no
 * stemming, so the PHP, JavaScript and Python packages index identically.
 */
import type { Lexical } from '../document/document.js';
import type { Unit } from '../document/unit.js';
import { byteCompare } from '../support/php.js';
import type { Source } from './source.js';

const K1 = 1.2;

const B = 0.75;

export interface Hit {
    document: string;
    unit: string;
    score: number;
}

/** Lowercased runs of two or more Unicode letters or digits. */
export function terms(text: string): string[] {
    return text.toLowerCase().match(/[\p{L}\p{N}]{2,}/gu) ?? [];
}

/** Each unit's terms come from its text, its path and `extra[unit.id]`, text indexed but not embedded. */
export function buildLexical(units: readonly Unit[], extra: Readonly<Record<string, string>> = {}): Lexical {
    const length: Record<string, number> = {};
    const byTerm = new Map<string, Record<string, number>>();

    for (const unit of units) {
        const counts = new Map<string, number>();

        for (const term of terms(`${unit.text} ${unit.path.join(' ')} ${extra[unit.id] ?? ''}`)) {
            counts.set(term, (counts.get(term) ?? 0) + 1);
        }

        length[unit.id] = [...counts.values()].reduce((sum, count) => sum + count, 0);

        for (const [term, count] of counts) {
            const postings = byTerm.get(term) ?? {};
            postings[unit.id] = count;
            byTerm.set(term, postings);
        }
    }

    return { length, terms: Object.fromEntries([...byTerm].sort(([a], [b]) => byteCompare(a, b))) };
}

/**
 * Units ranked by BM25 against the query, best first; ties keep the order their first posting
 * was met. `kinds` keeps only units of those kinds, and `documents` only units of those
 * documents; term weights still come from the whole source.
 */
export async function searchLexical(source: Source, query: string, limit: number, kinds: readonly string[] | null = null, documents: readonly string[] | null = null): Promise<Hit[]> {
    const queryTerms = [...new Set(terms(query))];
    const stats = await source.stats();

    if (queryTerms.length === 0 || stats.units === 0) {
        return [];
    }

    const average = stats.length / stats.units;
    const scores = new Map<string, Hit>();
    const only = documents === null ? null : new Set(documents);

    for (const postings of (await source.postings(queryTerms)).values()) {
        const idf = Math.log(1 + (stats.units - postings.length + 0.5) / (postings.length + 0.5));

        for (const posting of postings) {
            if (only !== null && !only.has(posting.document)) {
                continue;
            }

            const key = `${posting.document}\0${posting.unit}`;
            const norm = posting.count + K1 * (1 - B + B * posting.length / Math.max(average, 1.0));
            const hit = scores.get(key) ?? { document: posting.document, unit: posting.unit, score: 0 };
            hit.score += idf * posting.count * (K1 + 1) / norm;
            scores.set(key, hit);
        }
    }

    const out: Hit[] = [];

    for (const hit of [...scores.values()].sort((a, b) => b.score - a.score)) {
        if (kinds !== null && !kinds.includes((await source.document(hit.document))?.unit(hit.unit)?.kind ?? '')) {
            continue;
        }
        out.push(hit);
        if (out.length === limit) {
            break;
        }
    }

    return out;
}
