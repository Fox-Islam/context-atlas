<?php

declare(strict_types=1);

/*
 * Reference profile fixtures: entries found by layout (call signatures), their fields, and a
 * matrix table whose rows become one entry per series.
 */

$api = ['id' => 'api', 'profile' => 'reference', 'blocks' => [
    ['type' => 'heading', 'text' => 'File functions', 'level' => 1],
    ['type' => 'paragraph', 'text' => "open(path, mode='r', *, encoding=None)"],
    ['type' => 'paragraph', 'text' => 'Open the file at path and return a handle. Raises FileNotFoundError when the path does not exist. Added in version 3.4.'],
    ['type' => 'paragraph', 'text' => 'close(handle)'],
    ['type' => 'paragraph', 'text' => 'Close a handle returned by open. Raises ValueError when the handle is already closed. Changed in version 3.6.'],
    ['type' => 'paragraph', 'text' => 'remove(path, missing_ok=False)'],
    ['type' => 'paragraph', 'text' => 'Delete the file at path. Raises FileNotFoundError unless missing_ok is true. Added in version 3.8.'],
    ['type' => 'heading', 'text' => 'Paper sizes', 'level' => 1],
    ['type' => 'table', 'text' => "Table: ISO paper sizes\nFormat | A series mm | B series mm\n4 | 210 × 297 | 250 × 353\n5 | 148 × 210 | 176 × 250"],
]];

$votes = ['id' => 'votes', 'profile' => 'reference', 'blocks' => [
    ['type' => 'heading', 'text' => 'Division 386', 'level' => 1],
    ['type' => 'paragraph', 'text' => 'The House divided: Ayes 100, Noes 100.'],
    ['type' => 'heading', 'text' => 'Aye: 100 MPs', 'level' => 2],
    ['type' => 'heading', 'text' => 'No: 100 MPs', 'level' => 2],
    ['type' => 'paragraph', 'text' => "- Simon Hoare Conservative\n- Jim Allister Traditional Unionist Voice"],
    ['type' => 'heading', 'text' => 'Glossary', 'level' => 2],
    ['type' => 'paragraph', 'text' => "- Division: the House votes by dividing\n- Teller - a person who counts the votes\n- Whip"],
]];

return [
    ['name' => 'build entries by layout, with fields, and one entry per series of a matrix table', 'kind' => 'prose-ingest', 'input' => $api],
    [
        'name' => 'gather the entries a comparison names',
        'kind' => 'prose-retrieve',
        'documents' => [$api],
        'scope' => ['api'],
        'query' => 'How do open and remove differ in what they raise?',
        'answers' => ['c_open-path-mode-r-encoding-none-0' => 0.6, 'c_remove-path-missing-ok-false-1' => 0.3, 'c_close-handle-2' => 0.05, 'n_open-path-mode-r-encoding-none-0' => 0.9, 'n_remove-path-missing-ok-false-1' => 0.85, 'best' => 'open-path-mode-r-encoding-none-0', 'scope' => 'named entries'],
    ],
    [
        'name' => 'gather every entry with a property through a field filter',
        'kind' => 'prose-retrieve',
        'documents' => [$api],
        'scope' => ['api'],
        'query' => 'Which functions can raise FileNotFoundError?',
        'answers' => ['c_remove-path-missing-ok-false-0' => 0.7, 'c_open-path-mode-r-encoding-none-1' => 0.6, 'c_close-handle-2' => 0.05, 'best' => 'remove-path-missing-ok-false-0', 'scope' => 'all entries with a property', 'filter' => 'f0'],
    ],
    ['name' => 'keep text before the first entry, a plain list as one entry, glossary items and empty headings as entries', 'kind' => 'prose-ingest', 'input' => $votes],
];
