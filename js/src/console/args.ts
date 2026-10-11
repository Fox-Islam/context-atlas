/** `command positional... --flag --option=value`, in any order after the command. */
export class Args {
    private constructor(
        readonly command: string | null,
        readonly positional: readonly string[],
        private readonly options: ReadonlyMap<string, string | true>,
    ) {}

    static parse(argv: readonly string[]): Args {
        const positional: string[] = [];
        const options = new Map<string, string | true>();

        for (const arg of argv) {
            if (arg === '-h' || arg === '--help') {
                options.set('help', true);
            } else if (arg.startsWith('--')) {
                const equals = arg.indexOf('=');
                options.set(equals === -1 ? arg.slice(2) : arg.slice(2, equals), equals === -1 ? true : arg.slice(equals + 1));
            } else {
                positional.push(arg);
            }
        }

        const command = options.has('help') ? null : positional.shift() ?? null;

        return new Args(command, positional, options);
    }

    has(name: string): boolean {
        return this.options.has(name);
    }

    /** The option's value, or null when it is absent or given as a bare flag. */
    option(name: string): string | null {
        const value = this.options.get(name);

        return typeof value === 'string' ? value : null;
    }
}
