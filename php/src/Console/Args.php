<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Console;

/**
 * `command positional... --flag --option=value`, in any order after the command.
 */
final readonly class Args
{
    /**
     * @param  list<string>  $positional
     * @param  array<array-key, string|true>  $options  keyed by option name; a numeric name becomes an integer key
     */
    private function __construct(
        public ?string $command,
        public array $positional,
        private array $options,
    ) {}

    /**
     * @param  list<string>  $argv
     */
    public static function parse(array $argv): self
    {
        $positional = [];
        $options = [];

        foreach ($argv as $arg) {
            if ($arg === '-h' || $arg === '--help') {
                $options['help'] = true;
            } elseif (str_starts_with($arg, '--')) {
                [$name, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
                $options[$name] = $value;
            } else {
                $positional[] = $arg;
            }
        }

        $command = isset($options['help']) ? null : (array_shift($positional) ?? null);

        return new self($command, $positional, $options);
    }

    public function has(string $name): bool
    {
        return isset($this->options[$name]);
    }

    public function option(string $name): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}
