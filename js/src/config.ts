import { ContextAtlasError } from './errors.js';

export interface ConfigOptions {
    /** A chunk is closed before it grows past this. */
    maxTokens: number;
    /** A chunk is not closed for size below this, and a section is only split by topic above it. */
    minTokens: number;
    /** How much of the previous chunk a chunk repeats, within one section and not at a topic change. */
    overlapTokens: number;
    /** No piece cut at a topic change is smaller than this. */
    topicMinTokens: number;
    /** A paragraph longer than this is split between sentences. */
    splitLongParagraphAt: number;
    /** Unicode code points counted as one token. */
    charsPerToken: number;
    /** A seam whose two sides belong together less than this is worth cutting. */
    seamThreshold: number;
    /** The share of a seam's togetherness taken from whether the second side depends on the first. */
    refersBackWeight: number;
    /** A seam whose second side depends on the first at least this much is never cut. */
    refersBackProtect: number;
    /** How much of each side of a seam Jev is shown. */
    snippetChars: number;
    /** Seams per Jev request; each seam asks two questions. */
    batchSize: number;
    /** Seams asked about per document at most; the rest are not cut by topic. */
    maxPairs: number;
    /** A passage scoring below this is left out of the ranking. */
    rankThreshold: number;
    /** How much of each passage Jev is shown when ranking. */
    passageChars: number;
    /** Also ask which single passage answers best, and put it first. */
    crossCheck: boolean;
    /** Return the cross-check's pick even when no passage reached `rankThreshold`. */
    crossCheckRescue: boolean;
    /** Ask Jev which earlier message a conversation turn replies to when the source does not say. */
    conversationReplies: boolean;
    /** Ask Jev which conversation messages record an outcome and what each settles. */
    conversationOutcomes: boolean;
    /** Ask Jev which conversation messages correct an earlier one. */
    conversationCorrections: boolean;
}

/** `integer` and `number` carry the schema's bounds; `config.test.ts` checks this table against spec/config.schema.json. */
type Rule =
    | { key: keyof ConfigOptions; type: 'integer'; minimum: number; default: number }
    | { key: keyof ConfigOptions; type: 'number'; minimum: number; maximum: number; default: number }
    | { key: keyof ConfigOptions; type: 'boolean'; default: boolean };

export const CONFIG_RULES: Readonly<Record<string, Rule>> = {
    max_tokens: { key: 'maxTokens', type: 'integer', minimum: 1, default: 800 },
    min_tokens: { key: 'minTokens', type: 'integer', minimum: 0, default: 200 },
    overlap_tokens: { key: 'overlapTokens', type: 'integer', minimum: 0, default: 80 },
    topic_min_tokens: { key: 'topicMinTokens', type: 'integer', minimum: 0, default: 60 },
    split_long_paragraph_at: { key: 'splitLongParagraphAt', type: 'integer', minimum: 1, default: 900 },
    chars_per_token: { key: 'charsPerToken', type: 'integer', minimum: 1, default: 4 },
    seam_threshold: { key: 'seamThreshold', type: 'number', minimum: 0, maximum: 1, default: 0.5 },
    refers_back_weight: { key: 'refersBackWeight', type: 'number', minimum: 0, maximum: 1, default: 0.4 },
    refers_back_protect: { key: 'refersBackProtect', type: 'number', minimum: 0, maximum: 1, default: 0.5 },
    snippet_chars: { key: 'snippetChars', type: 'integer', minimum: 1, default: 240 },
    batch_size: { key: 'batchSize', type: 'integer', minimum: 1, default: 40 },
    max_pairs: { key: 'maxPairs', type: 'integer', minimum: 0, default: 2000 },
    rank_threshold: { key: 'rankThreshold', type: 'number', minimum: 0, maximum: 1, default: 0.4 },
    passage_chars: { key: 'passageChars', type: 'integer', minimum: 1, default: 2400 },
    cross_check: { key: 'crossCheck', type: 'boolean', default: true },
    cross_check_rescue: { key: 'crossCheckRescue', type: 'boolean', default: false },
    conversation_replies: { key: 'conversationReplies', type: 'boolean', default: true },
    conversation_outcomes: { key: 'conversationOutcomes', type: 'boolean', default: true },
    conversation_corrections: { key: 'conversationCorrections', type: 'boolean', default: true },
};

/**
 * Every tunable number. Sizes are approximate tokens of `charsPerToken`
 * Unicode code points each. A value outside spec/config.schema.json is refused.
 */
export class Config implements ConfigOptions {
    readonly maxTokens!: number;
    readonly minTokens!: number;
    readonly overlapTokens!: number;
    readonly topicMinTokens!: number;
    readonly splitLongParagraphAt!: number;
    readonly charsPerToken!: number;
    readonly seamThreshold!: number;
    readonly refersBackWeight!: number;
    readonly refersBackProtect!: number;
    readonly snippetChars!: number;
    readonly batchSize!: number;
    readonly maxPairs!: number;
    readonly rankThreshold!: number;
    readonly passageChars!: number;
    readonly crossCheck!: boolean;
    readonly crossCheckRescue!: boolean;
    readonly conversationReplies!: boolean;
    readonly conversationOutcomes!: boolean;
    readonly conversationCorrections!: boolean;

    /** @throws {ContextAtlasError} on a value of the wrong type or out of range */
    constructor(options: Partial<ConfigOptions> = {}) {
        const values: Record<string, number | boolean> = {};

        for (const [name, rule] of Object.entries(CONFIG_RULES)) {
            const value: unknown = options[rule.key] ?? rule.default;
            check(name, rule, value);
            values[rule.key] = value as number | boolean;
        }

        Object.assign(this, values);
        Object.freeze(this);
    }

    /**
     * A config from snake_case keys, as spec/config.schema.json names them.
     *
     * @throws {ContextAtlasError} on an unknown key or a bad value
     */
    static fromObject(values: Record<string, unknown>): Config {
        const options: Record<string, unknown> = {};

        for (const [name, value] of Object.entries(values)) {
            const rule = Object.hasOwn(CONFIG_RULES, name) ? CONFIG_RULES[name] : undefined;

            if (rule === undefined) {
                throw new ContextAtlasError(`Unknown config key "${name}".`);
            }
            check(name, rule, value);
            options[rule.key] = value;
        }

        return new Config(options as Partial<ConfigOptions>);
    }

    /** The values under their snake_case keys. */
    toObject(): Record<string, number | boolean> {
        return Object.fromEntries(Object.entries(CONFIG_RULES).map(([name, rule]) => [name, this[rule.key]]));
    }
}

function check(name: string, rule: Rule, value: unknown): void {
    if (rule.type === 'boolean') {
        if (typeof value !== 'boolean') {
            throw new ContextAtlasError(`Config key "${name}" must be true or false.`);
        }

        return;
    }

    const isNumber = typeof value === 'number' && (rule.type === 'integer' ? Number.isInteger(value) : Number.isFinite(value));
    const maximum = rule.type === 'number' ? rule.maximum : Infinity;

    if (!isNumber || value < rule.minimum || value > maximum) {
        const range = rule.type === 'number' ? `between ${rule.minimum} and ${rule.maximum}` : `at least ${rule.minimum}`;
        throw new ContextAtlasError(`Config key "${name}" must be ${rule.type === 'integer' ? 'an integer' : 'a number'} ${range}.`);
    }
}
