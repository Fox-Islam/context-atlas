<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Chunking;

use Phox\ContextAtlas\Config;

/**
 * Chooses where to cut a stretch of passages, all cuts together instead of
 * seam by seam, so one early cut cannot strand a fragment too small to keep.
 *
 * A seam's cost is how strongly its two sides belong together, less the
 * threshold: a seam between two subjects costs less than nothing, so cutting
 * there lowers the total. The planner takes the cheapest set of cuts that
 * leaves no piece below `topicMinTokens`, and may take none. A seam whose
 * second side depends on the first is never cut.
 */
final readonly class CutPlanner
{
    public function __construct(
        private Config $config,
    ) {}

    /**
     * @param  list<array{key: string, text: string, tokens: int}>  $units
     * @param  array<string, array{continues: float, refers_back: float}>  $scores
     * @return list<string> keys of the units that open a new piece, in order
     */
    public function cuts(array $units, array $scores): array
    {
        $n = count($units);
        $costs = [];
        foreach ($units as $index => $unit) {
            $costs[$index] = $index === 0 ? 0.0 : $this->cutCost($scores[$unit['key']] ?? null);
        }
        $best = [0 => 0.0];
        $from = [];

        for ($end = 1; $end <= $n; $end++) {
            $best[$end] = INF;
            $tokens = 0;
            for ($start = $end - 1; $start >= 0; $start--) {
                $tokens += $units[$start]['tokens'];
                $total = $best[$start] + $this->pieceCost($costs, $start, $end, $n, $tokens);
                if ($total < $best[$end]) {
                    $best[$end] = $total;
                    $from[$end] = $start;
                }
            }
        }

        $cuts = [];
        for ($end = $n; $end > 0 && isset($from[$end]); $end = $from[$end]) {
            if ($from[$end] > 0) {
                $cuts[] = $units[$from[$end]]['key'];
            }
        }

        return array_reverse($cuts);
    }

    /**
     * The cost of a piece running from unit $start up to $end: the cost of the
     * cut that opens it, or INF where the piece is too small to stand alone.
     *
     * @param  array<int, float>  $costs  cost of cutting before each unit
     */
    private function pieceCost(array $costs, int $start, int $end, int $n, int $tokens): float
    {
        if ($tokens < $this->config->topicMinTokens && ! ($start === 0 && $end === $n)) {
            return INF;
        }

        return $costs[$start] ?? INF;
    }

    /**
     * @param  array{continues: float, refers_back: float}|null  $score
     */
    private function cutCost(?array $score): float
    {
        if ($score === null || $score['refers_back'] >= $this->config->refersBackProtect) {
            return INF;
        }

        $weight = $this->config->refersBackWeight;
        $together = (1 - $weight) * $score['continues'] + $weight * $score['refers_back'];

        return $together - $this->config->seamThreshold;
    }
}
