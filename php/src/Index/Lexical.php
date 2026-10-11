<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Index;

use Phox\ContextAtlas\Document\Unit;

/**
 * Exact-term search: per-unit term counts at ingestion, BM25 at query time. Embeddings miss
 * identifiers, names and codes that a reader types exactly; this finds them.
 *
 * Terms are runs of Unicode letters and digits, lowercased, of two characters or more. There is
 * no stemming, so the PHP, JavaScript and Python packages index identically.
 */
final class Lexical
{
    private const float K1 = 1.2;

    private const float B = 0.75;

    /**
     * @param  list<Unit>  $units
     * @param  array<string, string>  $extra  unit id => further text to index but not embed, such as every value of a table's label columns
     * @return array{length: array<string, int>, terms: array<string, array<string, int>>}
     */
    public static function build(array $units, array $extra = []): array
    {
        $length = [];
        $terms = [];

        foreach ($units as $unit) {
            $counts = array_count_values(self::terms($unit->text . ' ' . implode(' ', $unit->path) . ' ' . ($extra[$unit->id] ?? '')));
            $length[$unit->id] = array_sum($counts);
            foreach ($counts as $term => $count) {
                $terms[(string) $term][$unit->id] = $count;
            }
        }

        ksort($terms, SORT_STRING);

        return ['length' => $length, 'terms' => $terms];
    }

    /**
     * @return list<string>
     */
    public static function terms(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{2,}/u', mb_strtolower($text), $matches);

        return $matches[0];
    }

    /**
     * Units ranked by BM25 against the query, best first.
     *
     * Collection-wide statistics weight the terms; with `$documents`, only those documents' units
     * are scored, so a document's units are found however large the collection.
     *
     * @param  list<string>|null  $kinds  only units of these kinds, or all
     * @param  list<string>|null  $documents  only units of these documents, or all
     * @return list<array{document: string, unit: string, score: float}>
     */
    public static function search(Source $source, string $query, int $limit, ?array $kinds = null, ?array $documents = null): array
    {
        $terms = array_values(array_unique(self::terms($query)));
        $stats = $source->stats();

        if ($terms === [] || $stats['units'] === 0) {
            return [];
        }

        $average = $stats['length'] / $stats['units'];
        $scores = [];
        $only = $documents === null ? null : array_flip($documents);

        foreach ($source->postings($terms) as $postings) {
            $idf = log(1 + ($stats['units'] - count($postings) + 0.5) / (count($postings) + 0.5));
            foreach ($postings as $posting) {
                if ($only !== null && ! isset($only[$posting['document']])) {
                    continue;
                }
                $key = $posting['document'] . "\0" . $posting['unit'];
                $norm = $posting['count'] + self::K1 * (1 - self::B + self::B * $posting['length'] / max($average, 1.0));
                $scores[$key] = ($scores[$key] ?? 0.0) + $idf * $posting['count'] * (self::K1 + 1) / $norm;
            }
        }

        arsort($scores);
        $out = [];

        foreach ($scores as $key => $score) {
            [$document, $unit] = explode("\0", (string) $key, 2);
            if ($kinds !== null && ! in_array($source->document($document)?->unit($unit)?->kind, $kinds, true)) {
                continue;
            }
            $out[] = ['document' => $document, 'unit' => $unit, 'score' => $score];
            if (count($out) === $limit) {
                break;
            }
        }

        return $out;
    }
}
