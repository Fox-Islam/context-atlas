/** Bad input: a block, a config key, a file or JSON that cannot be used. */
export class ContextAtlasError extends Error {
    override readonly name = 'ContextAtlasError';
}
