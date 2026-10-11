<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Markup;

use Phox\ContextAtlas\Exceptions\ContextAtlasException;

/**
 * The records of a JSON Lines file as one key tree: each non-blank line is a JSON value read and
 * printed by JsonOutline, under the root as item `[i]`, i counting non-blank lines from 0. The
 * outline is marked `records`, so Subtrees cuts the records in order with no root unit.
 */
final class JsonlOutline
{
    /**
     * @return array{lines: list<string>, entries: list<array{path: list<string>, start: int, end: int, value: string|null, alias: string|null}>, anchors: array<string, list<string>>, records: true}
     *
     * @throws ContextAtlasException
     */
    public static function read(string $text): array
    {
        $lines = [];
        $entries = [['path' => [], 'start' => 0, 'end' => 0, 'value' => null, 'alias' => null]];
        $item = 0;
        foreach (explode("\n", $text) as $n => $line) {
            if (trim($line, " \t\r") === '') {
                continue;
            }
            try {
                $record = JsonOutline::read($line);
            } catch (ContextAtlasException $e) {
                throw new ContextAtlasException('Invalid JSONL at line ' . ($n + 1) . ': ' . $e->getMessage(), 0, $e);
            }
            $offset = count($lines);
            foreach ($record['entries'] as $entry) {
                $entries[] = ['path' => ['[' . $item . ']', ...$entry['path']], 'start' => $entry['start'] + $offset, 'end' => $entry['end'] + $offset, 'value' => $entry['value'], 'alias' => null];
            }
            array_push($lines, ...$record['lines']);
            $item++;
        }
        $entries[0]['end'] = count($lines);

        return ['lines' => $lines, 'entries' => $entries, 'anchors' => [], 'records' => true];
    }
}
