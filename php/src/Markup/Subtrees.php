<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Markup;

use Phox\ContextAtlas\Document\Link;
use Phox\ContextAtlas\Document\Unit;
use Phox\ContextAtlas\Prose\Profiles;
use Phox\ContextAtlas\Text;

/**
 * Cuts a key tree into units: a subtree that fits MAX tokens is one unit; a bigger one keeps its
 * own lines and its children of SMALL tokens or fewer as a header unit, and each larger child is
 * cut the same way. A unit's text is a breadcrumb line (`title: path`) and its source lines with
 * their common indent removed, so a schema read alone shows where it sits.
 *
 * A node's parent is its nearest ancestor path that has a node (TOML has none for `tool` in
 * `[tool.ruff]`). A child of more than SMALL tokens lying outside its parent's span, such as a
 * `[package.metadata]` written after other tables, is cut on its own by the node whose span
 * holds it, and its lines are left out of that node's unit.
 *
 * Path subtrees completed 35 of 40 questions on an OpenAPI document, a Compose file and a CI
 * workflow, against 30 for 40-line windows; following `$ref` and alias links took it to 38.
 *
 * Links: `ref` from the unit holding a `$ref: "#/..."` (a JSON pointer into the same file) or
 * an alias (YAML, or an XML reference to an `id`) to the unit where its target starts.
 */
final class Subtrees
{
    private const int MAX = 450;

    private const int SMALL = 90;

    /** @var array<string, array{path: list<string>, start: int, end: int, value: string|null, alias: string|null}> */
    private array $entries = [];

    /** @var array<string, list<string>> */
    private array $children = [];

    /** @var list<string> */
    private array $detached = [];

    /** @var array<string, true> */
    private array $done = [];

    /** @var array<string, array<string, array<string, int>>> */
    private array $values = [];

    /** @var array<string, list<string>> */
    private array $siblings = [];

    /** @var list<Unit> */
    private array $units = [];

    /** @var array<int, string> */
    private array $owner = [];

    /**
     * @param  array{lines: list<string>, entries: list<array{path: list<string>, start: int, end: int, value: string|null, alias: string|null}>, anchors: array<string, list<string>>, labels?: list<string>, records?: bool}  $outline
     */
    private function __construct(
        private readonly array $outline,
        private readonly string $title,
        private readonly int $charsPerToken,
    ) {
        foreach ($outline['entries'] as $entry) {
            $this->entries[self::key($entry['path'])] = $entry;
        }
        foreach ($this->entries as $key => $entry) {
            if ($entry['path'] === []) {
                continue;
            }
            $parent = $this->parentOf($entry['path']);
            $this->children[$parent][] = (string) $key;
            if (! self::within($entry, $this->entries[$parent]) && $this->tokens($entry['start'], $entry['end']) > self::SMALL) {
                $this->detached[] = (string) $key;
            }
        }
    }

    /**
     * @param  array{lines: list<string>, entries: list<array{path: list<string>, start: int, end: int, value: string|null, alias: string|null}>, anchors: array<string, list<string>>, labels?: list<string>, records?: bool}  $outline
     * @return array{units: list<Unit>, links: list<Link>}
     */
    public static function build(array $outline, string $title, int $charsPerToken = 4): array
    {
        $subtrees = new self($outline, $title, $charsPerToken);
        $subtrees->cut('');

        return ['units' => $subtrees->units, 'links' => $subtrees->links()];
    }

    private function cut(string $key): void
    {
        if ($key === '' && ($this->outline['records'] ?? false) && isset($this->children[''])) {
            $this->cutRecords();

            return;
        }
        $this->done[$key] = true;
        $entry = $this->entries[$key];
        $whole = $key !== '' && ($this->tokens($entry['start'], $entry['end']) <= self::MAX || ! isset($this->children[$key]));
        $big = [];
        foreach ($whole ? [] : $this->children[$key] ?? [] as $child) {
            $c = $this->entries[$child];
            if ($this->tokens($c['start'], $c['end']) > self::SMALL) {
                $big[] = $child;
            }
        }
        $detached = array_values(array_filter($this->detached, fn (string $d): bool => ! isset($this->done[$d]) && self::within($this->entries[$d], $entry)
            && array_filter($big, fn (string $b): bool => self::within($this->entries[$d], $this->entries[$b])) === []));
        $covered = [];
        foreach ([...$big, ...$detached] as $child) {
            for ($i = $this->entries[$child]['start']; $i < $this->entries[$child]['end']; $i++) {
                $covered[$i] = true;
            }
        }
        $own = array_values(array_filter(range($entry['start'], max($entry['start'], $entry['end'] - 1)), static fn (int $i): bool => ! isset($covered[$i])));
        $useful = array_filter($own, fn (int $i): bool => preg_match('/^\s*(#.*)?$/', $this->outline['lines'][$i] ?? '') !== 1);
        if ($own !== [] && ($whole || count($useful) > 1 || $big === [])) {
            $this->emitOwn($key, $own, $big);
        }
        foreach ([...$big, ...$detached] as $child) {
            if (! isset($this->done[$child])) {
                $this->cut($child);
            }
        }
    }

    /**
     * Emits a node's own lines as one unit, or, past MAX tokens, as packs split at its small
     * children: lines outside them join the pack being filled, and a child that would take a
     * pack holding a child past MAX starts the next one. A pack's label ends with the first and
     * last child it holds: `(top level) (build … cascade > [3])`.
     *
     * @param  list<int>  $own
     * @param  list<string>  $big
     */
    private function emitOwn(string $key, array $own, array $big): void
    {
        $path = $this->entries[$key]['path'];
        if (Text::tokens($this->text($own), $this->charsPerToken) <= self::MAX) {
            $this->emit($path, $own);

            return;
        }
        $childOf = [];
        foreach ($this->children[$key] ?? [] as $child) {
            $c = $this->entries[$child];
            if (! in_array($child, $big, true) && self::within($c, $this->entries[$key])) {
                for ($i = $c['start']; $i < $c['end']; $i++) {
                    $childOf[$i] = $child;
                }
            }
        }
        $spans = [];
        foreach ($own as $line) {
            if (isset($childOf[$line])) {
                $spans[$childOf[$line]][] = $line;
            }
        }
        $lines = [[]];
        $held = [[]];
        $last = 0;
        foreach ($own as $n => $line) {
            $child = $childOf[$line] ?? null;
            if ($child !== null && ($n === 0 || ($childOf[$own[$n - 1]] ?? null) !== $child)) {
                if ($held[$last] !== [] && Text::tokens($this->text([...$lines[$last], ...$spans[$child]]), $this->charsPerToken) > self::MAX) {
                    $last++;
                    $lines[$last] = [];
                    $held[$last] = [];
                }
                $held[$last][] = $child;
            }
            $lines[$last][] = $line;
        }
        foreach ($lines as $p => $pack) {
            $this->emit($path, $pack, $this->range($path, $held[$p]));
        }
    }

    /**
     * Cuts the records of a JSONL file in order: a record of more than SMALL tokens is cut on
     * its own, and the runs of smaller records between them are packed into units of at most
     * MAX tokens, each labelled by its first and last record; a pack of one record is that
     * record's own unit.
     */
    private function cutRecords(): void
    {
        $this->done[''] = true;
        $pack = [];
        $held = [];
        foreach ($this->children[''] ?? [] as $child) {
            $c = $this->entries[$child];
            $lines = range($c['start'], max($c['start'], $c['end'] - 1));
            if ($this->tokens($c['start'], $c['end']) > self::SMALL) {
                $this->emitPack($pack, $held);
                [$pack, $held] = [[], []];
                $this->cut($child);

                continue;
            }
            if ($held !== [] && Text::tokens($this->text([...$pack, ...$lines]), $this->charsPerToken) > self::MAX) {
                $this->emitPack($pack, $held);
                [$pack, $held] = [[], []];
            }
            array_push($pack, ...$lines);
            $held[] = $child;
        }
        $this->emitPack($pack, $held);
    }

    /**
     * @param  list<int>  $lines
     * @param  list<string>  $held
     */
    private function emitPack(array $lines, array $held): void
    {
        if (count($held) === 1) {
            $this->cut($held[0]);
        } elseif ($held !== []) {
            $this->emit([], $lines, $this->range([], $held));
        }
    }

    /**
     * `(<first> … <last>)` naming the first and last of the children, or `(<first>)`, each by
     * its label segments after `$path`; null for none.
     *
     * @param  list<string>  $path
     * @param  list<string>  $children
     */
    private function range(array $path, array $children): ?string
    {
        $names = array_map(fn (string $child): string => implode(' > ', array_slice($this->label($this->entries[$child]['path']), count($path))), $children);

        return match (count($names)) {
            0 => null,
            1 => '(' . $names[0] . ')',
            default => '(' . $names[0] . ' … ' . $names[count($names) - 1] . ')',
        };
    }

    /**
     * @param  list<int>  $lineNumbers
     */
    private function text(array $lineNumbers): string
    {
        return implode("\n", array_map(fn (int $i): string => $this->outline['lines'][$i] ?? '', $lineNumbers));
    }

    /**
     * @param  list<string>  $path
     * @param  list<int>  $lineNumbers
     */
    private function emit(array $path, array $lineNumbers, ?string $range = null): void
    {
        $body = array_map(fn (int $i): string => $this->outline['lines'][$i] ?? '', $lineNumbers);
        $indents = array_map(static fn (string $l): int => strlen($l) - strlen(ltrim($l, ' ')), array_filter($body, static fn (string $l): bool => trim($l) !== ''));
        $indent = $indents === [] ? 0 : min($indents);
        $body = array_map(static fn (string $l): string => substr($l, min($indent, strlen($l) - strlen(ltrim($l, ' ')))), $body);
        $label = $this->label($path);
        $id = 's' . (count($this->units) + 1);
        $text = $this->title . ': ' . ($path === [] ? '(top level)' : implode(' > ', $label)) . ($range === null ? '' : ' ' . $range) . "\n" . rtrim(implode("\n", $body));
        $label = $range === null ? $label : [...$label, $range];
        $this->units[] = new Unit($id, 'subtree', $text, $label, ['path' => $path, 'lines' => [$lineNumbers[0] ?? 0, ($lineNumbers[count($lineNumbers) - 1] ?? 0) + 1], 'profile' => Profiles::MARKUP]);
        foreach ($lineNumbers as $i) {
            $this->owner[$i] ??= $id;
        }
    }

    /**
     * The path with each sequence index named by one of the item's children (`name()`):
     * `steps > [name=Install dependencies]`. An outline that lists its own label fields (XML adds
     * the `@` forms) also names a `tag[i]` segment: `item[name=Home]`.
     *
     * @param  list<string>  $path
     * @return list<string>
     */
    private function label(array $path): array
    {
        $pattern = isset($this->outline['labels']) ? '/^([^\[\]]*)\[\d+\]$/' : '/^()\[\d+\]$/';
        $out = [];
        foreach ($path as $i => $segment) {
            if (preg_match($pattern, $segment, $match) === 1) {
                $name = $this->name(array_slice($path, 0, $i + 1));
                if ($name !== null) {
                    $segment = $match[1] . '[' . $name[0] . '=' . $name[1] . ']';
                }
            }
            $out[] = $segment;
        }

        return $out;
    }

    /**
     * The field and value naming an item: the first label field with a value; else a child
     * whose key ends in `name`, `id`, `title` or `key` (any case) and has a value; else a child
     * with a non-empty one-line value, cut to 40 characters and `…` when longer than 60. In the
     * last two, the first child whose value no same-name sibling item shares wins, else the
     * first child. Children that are items themselves are passed over.
     *
     * @param  list<string>  $item
     * @return array{string, string}|null
     */
    private function name(array $item): ?array
    {
        foreach ($this->outline['labels'] ?? ['name', 'id', 'title', 'key'] as $field) {
            $value = $this->entries[self::key([...$item, $field])]['value'] ?? null;
            if ($value !== null) {
                return [ltrim($field, '@'), $value];
            }
        }
        $children = array_values(array_filter(array_map(fn (string $child): array => $this->entries[$child], $this->children[self::key($item)] ?? []), static fn (array $c): bool => $c['value'] !== null && preg_match('/\[\d+\]$/', $c['path'][count($c['path']) - 1]) !== 1));
        $ending = array_values(array_filter($children, static fn (array $c): bool => preg_match('/(?:name|id|title|key)$/i', ltrim($c['path'][count($c['path']) - 1], '@')) === 1));
        $plain = array_values(array_filter($children, static fn (array $c): bool => $c['value'] !== '' && ! str_contains((string) $c['value'], "\n")));
        foreach ([$ending, $plain] as $candidates) {
            if ($candidates === []) {
                continue;
            }
            $chosen = $candidates[0];
            foreach ($candidates as $candidate) {
                if ($this->unique($item, $candidate)) {
                    $chosen = $candidate;
                    break;
                }
            }
            $value = (string) $chosen['value'];

            return [ltrim($chosen['path'][count($chosen['path']) - 1], '@'), $candidates === $plain && mb_strlen($value) > 60 ? mb_substr($value, 0, 40) . '…' : $value];
        }

        return null;
    }

    /**
     * Whether no same-name sibling of the item (another item under the same parent path whose
     * last segment differs only in its index) has a child at the same relative path with the
     * same value.
     *
     * @param  list<string>  $item
     * @param  array{path: list<string>, start: int, end: int, value: string|null, alias: string|null}  $child
     */
    private function unique(array $item, array $child): bool
    {
        $group = self::key([...array_slice($item, 0, -1), (string) preg_replace('/\[\d+\]$/', '', $item[count($item) - 1])]);
        $relative = self::key(array_slice($child['path'], count($item)));
        if (! isset($this->values[$group][$relative])) {
            $counts = [];
            foreach ($this->siblings($item, $group) as $sibling) {
                $value = $this->entries[$sibling . "\x1f" . $relative]['value'] ?? null;
                if ($value !== null) {
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
            }
            $this->values[$group][$relative] = $counts;
        }

        return ($this->values[$group][$relative][(string) $child['value']] ?? 0) === 1;
    }

    /**
     * @param  list<string>  $item
     * @return list<string>
     */
    private function siblings(array $item, string $group): array
    {
        if (! isset($this->siblings[$group])) {
            $parent = array_slice($item, 0, -1);
            $tag = (string) preg_replace('/\[\d+\]$/', '', $item[count($item) - 1]);
            $this->siblings[$group] = array_values(array_filter($this->children[$this->parentOf($item)] ?? [], fn (string $key): bool => count($this->entries[$key]['path']) === count($item)
                && array_slice($this->entries[$key]['path'], 0, -1) === $parent
                && preg_match('/^' . preg_quote($tag, '/') . '\[\d+\]$/', $this->entries[$key]['path'][count($item) - 1]) === 1));
        }

        return $this->siblings[$group];
    }

    /**
     * @return list<Link>
     */
    private function links(): array
    {
        $links = [];
        foreach ($this->entries as $entry) {
            $from = $this->ownerOf($entry['path']);
            $target = match (true) {
                $entry['path'] !== [] && $entry['path'][count($entry['path']) - 1] === '$ref' && str_starts_with((string) $entry['value'], '#/') => array_map(static fn (string $p): string => str_replace(['~1', '~0'], ['/', '~'], $p), explode('/', substr((string) $entry['value'], 2))),
                $entry['alias'] !== null => $this->outline['anchors'][$entry['alias']] ?? null,
                default => null,
            };
            $to = $target === null ? null : $this->ownerOf($target);
            if ($from !== null && $to !== null && $to !== $from) {
                $links[$from . ' ' . $to] ??= new Link('ref', $from, $to);
            }
        }

        return array_values($links);
    }

    /**
     * The key of the nearest ancestor path that has a node; the root's otherwise.
     *
     * @param  list<string>  $path
     */
    private function parentOf(array $path): string
    {
        for ($n = count($path) - 1; $n > 0; $n--) {
            $key = self::key(array_slice($path, 0, $n));
            if (isset($this->entries[$key])) {
                return $key;
            }
        }

        return '';
    }

    /**
     * @param  array{start: int, end: int}  $inner
     * @param  array{start: int, end: int}  $outer
     */
    private static function within(array $inner, array $outer): bool
    {
        return $inner['start'] >= $outer['start'] && $inner['end'] <= $outer['end'];
    }

    /**
     * @param  list<string>  $path
     */
    private function ownerOf(array $path): ?string
    {
        $entry = $this->entries[self::key($path)] ?? null;

        return $entry === null ? null : ($this->owner[$entry['start']] ?? null);
    }

    private function tokens(int $start, int $end): int
    {
        return Text::tokens(implode("\n", array_slice($this->outline['lines'], $start, $end - $start)), $this->charsPerToken);
    }

    /**
     * @param  list<string>  $path
     */
    private static function key(array $path): string
    {
        return implode("\x1f", $path);
    }
}
