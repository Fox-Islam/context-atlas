import { TypeSafeClient, TypeSafeError, type Fetch } from '@typesafe-ai/sdk';

const SYSTEM_ONE_PATH = '/v1/systemone';

const OPENROUTER_PATH = '/api/alpha/decisions';

const OPENROUTER_BASE_URL = 'https://openrouter.ai';

/**
 * The SDK client the command line uses, configured from the environment the
 * PHP SDK reads: `TYPESAFE_PROVIDER=openrouter` sends calls to OpenRouter's
 * decisions endpoint keyed by `OPENROUTER_API_KEY`. The bodies are the same on
 * both providers and the SDK knows one path, so OpenRouter is a base URL and a
 * path rewrite in the transport.
 *
 * @throws {TypeSafeError} when the provider's API key is missing
 */
export function clientFromEnv(transport?: Fetch): TypeSafeClient {
    const send: Fetch = transport ?? ((input, init) => fetch(input, init));

    if (value(process.env['TYPESAFE_PROVIDER'])?.toLowerCase() !== 'openrouter') {
        return new TypeSafeClient({ fetch: send });
    }

    const apiKey = value(process.env['OPENROUTER_API_KEY']);

    if (apiKey === null) {
        throw new TypeSafeError('No API key was provided. Set the OPENROUTER_API_KEY environment variable.');
    }

    return new TypeSafeClient({
        apiKey,
        baseURL: value(process.env['TYPESAFE_BASE_URL']) ?? OPENROUTER_BASE_URL,
        fetch: (input, init) => send(input.replace(SYSTEM_ONE_PATH, OPENROUTER_PATH), init),
    });
}

function value(raw: string | undefined): string | null {
    return raw === undefined || raw.trim() === '' ? null : raw.trim();
}
