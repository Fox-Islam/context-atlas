<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Prose;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\BlockType;
use Phox\ContextAtlas\Text;

/**
 * Turns the blocks of a reference section into entries: one per documented member, glossary
 * term or table row, each carrying its name and the fields a filter can match.
 *
 * An entry starts at a block whose meta names it (`entry`), or by layout: a short line with no
 * sentence end followed by a paragraph more than twice its length, or a call signature such as
 * `Path.walk(top_down=True)`. The blocks after it belong to it until the next entry or heading.
 *
 * A table becomes one entry per row. When its row key is a short index and two or more column
 * groups share a prefix ("A series", "B series"), each group is its own entry, named by the
 * prefix's leading word and the key ("A4"): a reader asks for "C5", which no cell holds.
 */
final class Entries
{
    private const int HEAD_MAX = 60;

    private const string SIGNATURE = '/^[A-Za-z_][\w.]*\(.*\)\s*(?:->.*)?$|^[A-Z]\w*\.\w+$/';

    /**
     * @param  list<array{block: Block, path: list<string>}>  $blocks  in document order, with each block's heading path
     * @return list<array{name: string, text: string, path: list<string>, kind: string, group?: string, fields: array<string, list<string>>, blocks: list<int>}>
     */
    public static function build(array $blocks): array
    {
        $entries = [];
        $current = null;
        $byMeta = array_filter($blocks, static fn (array $item): bool => is_string($item['block']->meta['entry'] ?? null)) !== [];

        foreach ($blocks as $i => $item) {
            $block = $item['block'];
            if ($block->isHeading()) {
                $current = self::close($entries, $current);
                $next = $blocks[$i + 1]['block'] ?? null;
                if ($next === null || ($next->isHeading() && ($next->level ?? 0) <= ($block->level ?? 0))) {
                    $entries[] = ['name' => Text::squash($block->text), 'text' => Text::squash($block->text), 'path' => $item['path'], 'kind' => 'entry', 'fields' => [], 'blocks' => [$i]];
                }

                continue;
            }
            if ($block->type === BlockType::Table) {
                $current = self::close($entries, $current);
                array_push($entries, ...self::rows($block, $item['path'], $i));

                continue;
            }

            $name = self::head($blocks, $i, $byMeta);
            if ($name !== null) {
                $current = self::close($entries, $current);
                $signature = is_string($block->meta['signature'] ?? null) ? $block->meta['signature'] : $name;
                $current = ['name' => $name, 'signature' => $signature, 'lines' => [Text::trim($block->text)], 'path' => $item['path'], 'kind' => 'entry', 'blocks' => [$i]];

                continue;
            }
            if ($current !== null) {
                $current['lines'][] = Text::trim($block->text);
                $current['blocks'][] = $i;

                continue;
            }
            $items = self::items($block);
            if ($items !== [] && self::isGlossary($items)) {
                foreach ($items as $line) {
                    $entries[] = ['name' => self::itemName($line), 'text' => $line, 'path' => $item['path'], 'kind' => 'entry', 'fields' => self::fields('', $line), 'blocks' => [$i]];
                }

                continue;
            }
            $path = $item['path'];
            $current = ['name' => $path === [] ? 'section' : $path[count($path) - 1], 'signature' => '', 'lines' => [Text::trim($block->text)], 'path' => $path, 'kind' => 'entry', 'blocks' => [$i]];
        }

        self::close($entries, $current);

        return $entries;
    }

    /**
     * The items of a list paragraph (every line opening with `- `, `* `, `+ ` or `1.`/`1)`), with
     * their markers removed; empty for any other block.
     *
     * @return list<string>
     */
    private static function items(Block $block): array
    {
        if ($block->type !== BlockType::Paragraph) {
            return [];
        }
        $items = [];
        foreach (explode("\n", Text::trim($block->text)) as $line) {
            if (preg_match('/^\s*(?:[-*+]|\d{1,9}[.)])\s+(\S.*)$/u', $line, $match) !== 1) {
                return [];
            }
            $items[] = Text::trim($match[1]);
        }

        return $items;
    }

    /**
     * Whether most items name a term before a separator (`Term: …`, `Term – …`), so each is an
     * entry of its own. A plain list, such as the names in a vote, stays one entry: a question
     * about it needs the whole list.
     *
     * @param  list<string>  $items
     */
    private static function isGlossary(array $items): bool
    {
        $named = array_filter($items, static fn (string $item): bool => preg_match('/^.{1,' . self::HEAD_MAX . '}?(?::\s| – | - )\S/u', $item) === 1);

        return 2 * count($named) > count($items);
    }

    /**
     * An item's name: its text up to the first `: `, ` – `, ` - ` or ` (`, when that comes within
     * HEAD_MAX characters, else its first HEAD_MAX characters.
     */
    private static function itemName(string $item): string
    {
        if (preg_match('/^(.{1,' . self::HEAD_MAX . '}?)(?::\s| – | - | \()/u', $item, $match) === 1) {
            return Text::trim($match[1]);
        }

        return Text::trim(mb_substr($item, 0, self::HEAD_MAX));
    }

    /**
     * The entry name a block starts, or null. Where the converter named entries in meta, only
     * those start one; otherwise a paragraph does by layout.
     *
     * @param  list<array{block: Block, path: list<string>}>  $blocks
     */
    private static function head(array $blocks, int $i, bool $byMeta): ?string
    {
        $block = $blocks[$i]['block'];
        $text = Text::squash($block->text);

        if ($byMeta) {
            $entry = $block->meta['entry'] ?? null;

            return is_string($entry) && ($i === 0 || ($blocks[$i - 1]['block']->meta['entry'] ?? null) !== $entry) ? $entry : null;
        }
        if ($block->type !== BlockType::Paragraph || preg_match('/\p{L}/u', $text) !== 1) {
            return null;
        }
        if (preg_match(self::SIGNATURE, $text) === 1 && mb_strlen($text) <= 2 * self::HEAD_MAX) {
            return $text;
        }

        $next = $blocks[$i + 1]['block'] ?? null;
        $short = mb_strlen($text) <= self::HEAD_MAX && preg_match('/[.!?:;,]$/u', $text) !== 1;

        return $short && $next !== null && $next->type === BlockType::Paragraph && mb_strlen(Text::trim($next->text)) > 2 * mb_strlen($text) ? $text : null;
    }

    /**
     * @param  list<array{name: string, text: string, path: list<string>, kind: string, group?: string, fields: array<string, list<string>>, blocks: list<int>}>  $entries
     * @param  array{name: string, signature: string, lines: list<string>, path: list<string>, kind: string, blocks: list<int>}|null  $current
     */
    private static function close(array &$entries, ?array $current): null
    {
        if ($current !== null) {
            $text = implode("\n\n", $current['lines']);
            $entries[] = ['name' => $current['name'], 'text' => $text, 'path' => $current['path'], 'kind' => 'entry', 'fields' => self::fields($current['signature'], $text), 'blocks' => $current['blocks']];
        }

        return null;
    }

    /**
     * Parameters from a call signature, versions from "Added in version 3.12" style notes and
     * reST directives, and exception names.
     *
     * @return array<string, list<string>>
     */
    private static function fields(string $name, string $text): array
    {
        $fields = [];

        if (preg_match('/\((.*)\)/', $name, $match) === 1) {
            foreach (preg_split('/\s*,\s*/', $match[1]) ?: [] as $parameter) {
                $parameter = (string) preg_replace('/[=:].*$/', '', Text::trim($parameter));
                $parameter = ltrim($parameter, '*');
                if ($parameter !== '' && $parameter !== '/' && $parameter !== 'self') {
                    $fields['parameter'][] = $parameter;
                }
            }
        }
        foreach (['added' => '(?:added in version|versionadded::?|new in version)', 'changed' => '(?:changed in version|versionchanged::?)', 'deprecated' => '(?:deprecated since version|deprecated::?)'] as $field => $pattern) {
            if (preg_match_all('/' . $pattern . '\s*(\d+(?:\.\d+)*)/i', $text, $matches) > 0) {
                $fields[$field] = array_values(array_unique($matches[1]));
            }
        }
        if (preg_match_all('/\b([A-Z][A-Za-z]*(?:Error|Exception|Warning))\b/', $text, $matches) > 0) {
            $fields['exception'] = array_values(array_unique($matches[1]));
        }

        return $fields;
    }

    /**
     * One entry per table row. When the row key is an index (one or two digits, or a Roman
     * numeral) and two or more columns are headed by a one- or two-letter group ("A series
     * ..."), each group is its own entry named group + key; when only the caption names a
     * series ("DIN D series"), the entry is named series + key.
     *
     * @param  list<string>  $path
     * @return list<array{name: string, text: string, path: list<string>, kind: string, group?: string, fields: array<string, list<string>>, blocks: list<int>}>
     */
    private static function rows(Block $table, array $path, int $index): array
    {
        [$caption, $header, $rows] = self::table($table);
        $groups = [];
        foreach ($header as $c => $label) {
            if ($c > 0 && preg_match('/^([A-Z]{1,2})(?: series)?\b/', $label, $match) === 1) {
                $groups[$match[1]][] = $c;
            }
        }
        $series = preg_match('/\b([A-Z]{1,3}) series\b/', $caption, $match) === 1 ? $match[1] : null;
        $section = implode(' > ', $path);
        $context = Text::trim(($section !== '' ? "[{$section}] " : '') . ($caption !== '' ? 'Table: ' . $caption : ''));
        $entries = [];

        foreach ($rows as $row) {
            $key = Text::trim($row[0] ?? '');
            if ($key === '' || count(array_filter($row, static fn (string $c): bool => Text::trim($c) !== '')) < 2) {
                continue;
            }
            $index_ = preg_match('/^(\d{1,2}|[IVX]{1,5})$/', $key) === 1;
            $all = range(1, max(1, count($row) - 1));
            $names = match (true) {
                $index_ && count($groups) >= 2 => array_combine(array_map(static fn (string $g): string => $g . $key, array_map('strval', array_keys($groups))), array_values($groups)),
                $index_ && $series !== null => [$series . $key => $all],
                default => [$key => $all],
            };
            foreach ($names as $name => $columns) {
                $fields = [];
                $labels = [];
                foreach ($columns as $c) {
                    $value = Text::trim($row[$c] ?? '');
                    if ($value !== '') {
                        $head = Text::trim($header[$c] ?? "column {$c}");
                        $fields[$head][] = $value;
                        $labels[] = "{$head}: {$value}";
                    }
                }
                $entries[] = [
                    'name' => (string) $name,
                    'text' => $context . "\n" . implode(' | ', $header) . "\n" . implode(' | ', $row) . "\nEntry {$name}: " . implode('; ', $labels),
                    'path' => $path,
                    'kind' => 'row',
                    'group' => $caption !== '' ? $caption : $section,
                    'fields' => $fields,
                    'blocks' => [$index],
                ];
            }
        }

        return $entries;
    }

    /**
     * A table's caption, header and rows: from the block's meta when the converter gave them,
     * otherwise from its text (lines split on `|`, a leading "Table: ..." line as the caption,
     * Markdown separator rows dropped).
     *
     * @return array{string, list<string>, list<list<string>>}
     */
    private static function table(Block $table): array
    {
        if (is_array($table->meta['header'] ?? null) && is_array($table->meta['rows'] ?? null)) {
            return [
                (string) ($table->meta['caption'] ?? ''),
                array_values(array_map('strval', $table->meta['header'])),
                array_values(array_map(static fn (mixed $r): array => array_values(array_map('strval', (array) $r)), $table->meta['rows'])),
            ];
        }

        $caption = '';
        $rows = [];
        foreach (explode("\n", $table->text) as $line) {
            $line = Text::trim($line);
            if (preg_match('/^Table:\s*(.*)$/', $line, $match) === 1) {
                $caption = $match[1];
            } elseif (str_contains($line, '|') && preg_match('/^\|?[\s:|-]+\|?$/', $line) !== 1) {
                $rows[] = array_map(Text::trim(...), explode('|', trim($line, '|')));
            }
        }

        return [$caption, array_shift($rows) ?? [], $rows];
    }
}
