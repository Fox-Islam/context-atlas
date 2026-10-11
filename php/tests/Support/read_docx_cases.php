<?php

declare(strict_types=1);

/*
 * DOCX reader fixtures: each case reads one file from spec/fixtures/ (built from hand-written
 * XML so every construct is known) into blocks, or records the error every package must give.
 */

$docx = static fn (string $name, string $file): array => ['name' => $name, 'kind' => 'read', 'input' => ['format' => 'docx', 'file' => "spec/fixtures/{$file}"]];

return [
    $docx('docx: title shift, heading styles and outline levels, run text, fields (table-of-contents results dropped), revisions, notes, text boxes and content controls', 'docx-text.docx'),
    $docx('docx: numbered and bulleted lists with level templates, formats, start values, overrides and style numbering; the core title', 'docx-lists.docx'),
    $docx('docx: tables with header rows, spans, vertical merges, nested tables, captions and one-column layout tables', 'docx-tables.docx'),
    $docx('docx: code-styled paragraphs keep their lines and tabs', 'docx-code.docx'),
    $docx('docx: a package without its main document part is an error', 'docx-no-document.docx'),
    $docx('docx: a part that is not well-formed XML is an error', 'docx-malformed.docx'),
    $docx('docx: a part that declares a DOCTYPE is an error', 'docx-doctype.docx'),
    $docx('docx: a file that is not a zip archive is an error', 'docx-not-a-zip.docx'),
];
