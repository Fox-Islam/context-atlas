import { TypeSafeClient } from '@typesafe-ai/sdk';

export interface Reply {
    status: number;
    body: unknown;
}

/** An SDK client whose transport answers from `replies` in order and records each request, parsed and as sent; it never reaches the network. */
export function fakeClient(replies: readonly Reply[]): { client: TypeSafeClient; requests: Record<string, unknown>[]; bodies: string[] } {
    const requests: Record<string, unknown>[] = [];
    const bodies: string[] = [];
    const client = new TypeSafeClient({
        apiKey: 'test-key',
        logLevel: 'off',
        retry: { maxRetries: 0 },
        fetch: async (_input, init) => {
            bodies.push(String(init?.body));
            requests.push(JSON.parse(String(init?.body)) as Record<string, unknown>);
            const reply = replies[requests.length - 1] ?? { status: 500, body: { error: 'no scripted reply' } };

            return new Response(JSON.stringify(reply.body), { status: reply.status, headers: { 'content-type': 'application/json' } });
        },
    });

    return { client, requests, bodies };
}

export const failure: Reply = { status: 500, body: { error: 'scripted failure' } };

/** A JSON value with each object kept as its entries in written order. */
export type OrderedJson = null | boolean | number | string | OrderedJson[] | { entries: [string, OrderedJson][] };

/** JSON text read with every object's keys in the order written, which `JSON.parse` loses for keys like `2019`. */
export function parseOrdered(text: string): OrderedJson {
    let at = 0;
    const space = (): void => {
        while (/\s/.test(text[at] ?? '')) {
            at++;
        }
    };
    const string = (): string => {
        const start = at++;

        while (text[at] !== '"') {
            at += text[at] === '\\' ? 2 : 1;
        }
        at++;

        return JSON.parse(text.slice(start, at)) as string;
    };
    const value = (): OrderedJson => {
        space();
        const char = text[at];

        if (char === '{' || char === '[') {
            at++;
            const object = char === '{';
            const items: [string, OrderedJson][] = [];

            space();
            while (text[at] !== (object ? '}' : ']')) {
                space();
                const key = object ? string() : '';

                if (object) {
                    space();
                    at++;
                }
                items.push([key, value()]);
                space();
                if (text[at] === ',') {
                    at++;
                }
                space();
            }
            at++;

            return object ? { entries: items } : items.map(([, item]) => item);
        }
        if (char === '"') {
            return string();
        }

        const start = at;

        while (/[^,\]}\s]/.test(text[at] ?? '')) {
            at++;
        }

        return JSON.parse(text.slice(start, at)) as OrderedJson;
    };

    return value();
}

/** The value's key at `key`, for an ordered object. */
export function member(value: OrderedJson, key: string): OrderedJson {
    return typeof value === 'object' && value !== null && !Array.isArray(value) ? value.entries.find(([name]) => name === key)?.[1] ?? null : null;
}

/** Every object key in document order, depth first: equal sequences mean the same key order everywhere. */
export function keyOrder(value: OrderedJson): string[] {
    if (Array.isArray(value)) {
        return value.flatMap(keyOrder);
    }
    if (typeof value === 'object' && value !== null) {
        return value.entries.flatMap(([key, item]) => [key, ...keyOrder(item)]);
    }

    return [];
}
