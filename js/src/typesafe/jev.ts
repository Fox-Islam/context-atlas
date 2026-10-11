import type { Questions, SystemOneRequest, SystemOneResult, TypeSafeClient } from '@typesafe-ai/sdk';

/** What a System One call returns, with answers read by name. */
export type JevResult = SystemOneResult<Questions>;

/**
 * Sends a request built by this package and returns the SDK's result, or null
 * for no answer. This is where a caller puts caching, feature switches or
 * logging around the call: `(request) => client.systemOne(request)` is the
 * plain form.
 */
export type JevHook = (request: SystemOneRequest) => Promise<JevResult | null> | JevResult | null;

/** A `TypeSafeClient` from `@typesafe-ai/sdk`, or a hook that sends the request itself. */
export type Jev = TypeSafeClient | JevHook;

/** The answers of one call, or null when the call threw, failed or returned nothing. */
export async function send(jev: Jev, request: SystemOneRequest): Promise<Answers | null> {
    try {
        const result = typeof jev === 'function' ? await jev(request) : await jev.systemOne(request);

        return typeof result === 'object' && result !== null ? new Answers(result) : null;
    } catch {
        return null;
    }
}

/**
 * The answers of a call. The SDK returns the parsed body without checking it,
 * so an answer of the wrong type or shape counts as missing.
 */
export class Answers {
    private readonly byName: Record<string, unknown>;

    constructor(result: JevResult) {
        const answers: unknown = result.answers;
        this.byName = typeof answers === 'object' && answers !== null ? answers as Record<string, unknown> : {};
    }

    noul(name: string): number | null {
        const answer = this.get(name);

        return answer?.['type'] === 'noul' && typeof answer['noul'] === 'number' ? answer['noul'] : null;
    }

    choice(name: string): string | null {
        const answer = this.get(name);

        return answer?.['type'] === 'choice' && typeof answer['choice'] === 'string' ? answer['choice'] : null;
    }

    /** The reported confidence of a choice answer, or 0 when it is missing or of another type. */
    choiceConfidence(name: string): number {
        const answer = this.get(name);

        return answer?.['type'] === 'choice' && typeof answer['confidence'] === 'number' ? answer['confidence'] : 0;
    }

    private get(name: string): Record<string, unknown> | null {
        const answer = Object.hasOwn(this.byName, name) ? this.byName[name] : null;

        return typeof answer === 'object' && answer !== null ? answer as Record<string, unknown> : null;
    }
}
