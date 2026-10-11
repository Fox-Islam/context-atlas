export interface ContextSource {
    document: string;
    unit: string;
    /** For a table, the `sheet!row` of each record the text shows. */
    rows?: string[];
    /** For a message a later one corrects, the id of the last message in that chain of corrections. */
    superseded_by?: string;
}

export interface ContextObject {
    text: string;
    sources: ContextSource[];
    abstained: boolean;
    trace: Record<string, unknown>;
}

/**
 * What retrieval hands to the LLM: the text to put in its context, where each part came from,
 * and how it was chosen. `abstained` means the source does not hold what the question needs.
 */
export class Context {
    constructor(
        readonly text: string,
        readonly sources: readonly ContextSource[],
        readonly abstained: boolean,
        readonly trace: Readonly<Record<string, unknown>> = {},
    ) {}

    static abstain(trace: Record<string, unknown> = {}): Context {
        return new Context('', [], true, trace);
    }

    toObject(): ContextObject {
        return { text: this.text, sources: this.sources.map((source) => ({ ...source })), abstained: this.abstained, trace: { ...this.trace } };
    }

    toJSON(): ContextObject {
        return this.toObject();
    }
}
