import type { Config } from '../config.js';

export interface Unit {
    key: string;
    text: string;
    tokens: number;
}

export interface SeamScore {
    continues: number;
    refersBack: number;
}

/**
 * Chooses where to cut a stretch of units, all at once instead of seam by
 * seam. A seam costs how strongly its two sides belong together less
 * `seamThreshold`, so cutting between two subjects lowers the total. The
 * cheapest set of cuts that leaves no piece below `topicMinTokens` wins;
 * leaving the stretch whole is always allowed. A seam whose second side
 * depends on the first is never cut.
 */
export class CutPlanner {
    constructor(private readonly config: Config) {}

    /** Keys of the units that open a new piece, in order. */
    cuts(units: readonly Unit[], scores: ReadonlyMap<string, SeamScore>): string[] {
        const n = units.length;
        const costs = units.map((unit, index) => (index === 0 ? 0 : this.cutCost(scores.get(unit.key))));
        const best: number[] = [0];
        const from = new Map<number, number>();

        for (let end = 1; end <= n; end++) {
            best[end] = Infinity;
            let tokens = 0;

            for (let start = end - 1; start >= 0; start--) {
                tokens += units[start]!.tokens;
                const total = best[start]! + this.pieceCost(costs, start, end, n, tokens);

                if (total < best[end]!) {
                    best[end] = total;
                    from.set(end, start);
                }
            }
        }

        const cuts: string[] = [];

        for (let end = n; end > 0 && from.has(end); end = from.get(end)!) {
            const start = from.get(end)!;

            if (start > 0) {
                cuts.push(units[start]!.key);
            }
        }

        return cuts.reverse();
    }

    private pieceCost(costs: readonly number[], start: number, end: number, n: number, tokens: number): number {
        if (tokens < this.config.topicMinTokens && !(start === 0 && end === n)) {
            return Infinity;
        }

        return costs[start] ?? Infinity;
    }

    private cutCost(score: SeamScore | undefined): number {
        if (score === undefined || score.refersBack >= this.config.refersBackProtect) {
            return Infinity;
        }

        const weight = this.config.refersBackWeight;
        const together = (1 - weight) * score.continues + weight * score.refersBack;

        return together - this.config.seamThreshold;
    }
}
