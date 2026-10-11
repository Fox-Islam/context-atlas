<?php

declare(strict_types=1);

namespace Phox\ContextAtlas;

use Closure;
use Phox\ContextAtlas\Jev\JevCaller;
use Phox\ContextAtlas\Retrieval\Passage;
use Phox\ContextAtlas\Retrieval\RankedPassage;
use Phox\TypeSafe\Client;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Requests\SystemOne;
use Phox\TypeSafe\Responses\SystemOneResponse;

/**
 * Reorders search hits by whether they answer the question asked.
 *
 * Nearest-neighbour search finds passages written like the query, which is
 * close to but not the same as passages that answer it. So over-fetch from
 * your search, pass the hits here, and keep the first few.
 *
 * Each passage gets one yes/no question, all in one request, and those scoring
 * at least `rankThreshold` come back best first. A choice of the single best
 * passage is asked in the same request and its pick moved to the front: scored
 * one at a time, no passage is weighed against another.
 *
 * Jev is shown `passageChars` of each passage. Over 60 questions on four
 * public documents, the answer ranked first for 52 at 240 characters and for
 * 60 at 2,400, at the same median latency.
 *
 * Returns null, never a partial list, when Jev does not answer, so a caller
 * can keep the order its search gave.
 */
final readonly class Reranker
{
    private const string PREFIX = 'c_';

    private const string BEST = 'best';

    private const int LABEL_LENGTH = 60;

    private const string SUBJECT = 'the passages the search found';

    private const string INTENT = 'the question being asked';

    private JevCaller $jev;

    /**
     * @param  Client|Closure(Closure(SystemOne): SystemOne): ?SystemOneResponse  $jev
     */
    public function __construct(
        Client|Closure $jev,
        private Config $config = new Config(),
    ) {
        $this->jev = new JevCaller($jev);
    }

    /**
     * @param  array<Passage>  $passages
     * @return list<RankedPassage>|null best first, at most $limit
     */
    public function rank(array $passages, string $query, ?int $limit = null): ?array
    {
        if ($passages === [] || Text::trim($query) === '') {
            return null;
        }

        $labelled = [];
        foreach (array_values($passages) as $index => $passage) {
            $labelled[self::label($passage, $index)] = $passage;
        }

        $response = $this->jev->send(fn (SystemOne $request): SystemOne => $this->build($request, $labelled, $query));

        if (! $response instanceof SystemOneResponse) {
            return null;
        }

        $ranked = [];
        foreach (array_slice($this->scores($response, array_keys($labelled)), 0, $limit, true) as $label => $score) {
            $ranked[] = new RankedPassage($labelled[$label], $score);
        }

        return $ranked;
    }

    /**
     * A readable name for a passage, from its heading path, with its position
     * on the end so two passages under one heading stay apart.
     */
    private static function label(Passage $passage, int $index): string
    {
        $name = $passage->headingPath === [] ? 'passage ' . ($index + 1) : implode(' > ', $passage->headingPath);
        $slug = Text::slug($name, self::LABEL_LENGTH);

        return ($slug === '' ? 'untitled' : $slug) . '-' . $index;
    }

    /**
     * @param  array<string, Passage>  $labelled
     */
    private function build(SystemOne $request, array $labelled, string $query): SystemOne
    {
        $request->state([
            self::SUBJECT => array_map(fn (Passage $passage): string => Text::head($passage->text, $this->config->passageChars), $labelled),
            self::INTENT => $query,
        ]);

        foreach (array_keys($labelled) as $label) {
            $request->ask(
                self::PREFIX . $label,
                Noul::ask("Does `{$label}` answer the question?")
                    ->yes("`{$label}` states the answer, or a part of it.")
                    ->no("`{$label}` is on the same subject but does not answer the question, or only asserts that it does."),
            );
        }

        if ($this->config->crossCheck) {
            $request->choice(self::BEST, array_map('strval', array_keys($labelled)), 'Which single one answers the question most directly?');
        }

        return $request;
    }

    /**
     * @param  list<string>  $labels
     * @return array<string, float> label => score, best first
     */
    private function scores(SystemOneResponse $response, array $labels): array
    {
        $all = [];
        foreach ($labels as $label) {
            $score = JevCaller::noul($response, self::PREFIX . $label);
            if ($score !== null) {
                $all[$label] = $score;
            }
        }

        $scores = array_filter($all, fn (float $score): bool => $score >= $this->config->rankThreshold);
        arsort($scores);

        $pick = JevCaller::choice($response, self::BEST);

        if ($pick === null || ! in_array($pick, $labels, true) || ($scores === [] && ! $this->config->crossCheckRescue)) {
            return $scores;
        }

        unset($scores[$pick]);

        return [$pick => $all[$pick] ?? 0.0] + $scores;
    }
}
