<?php

declare(strict_types=1);

/*
 * Writes spec/fixtures.json: every case run through the PHP package against the
 * SDK's fake transport, with each request body it sent, the response body it
 * got back, and its output. The JavaScript and Python packages replay the
 * responses and must send the same requests and print the same output.
 *
 *   php scripts/fixtures.php          rewrite the file
 *   php scripts/fixtures.php --check  exit 1 if the file is out of date
 */

require __DIR__ . '/../vendor/autoload.php';

use Phox\ContextAtlas\Tests\Support\FixtureRunner;

$cases = require __DIR__ . '/../php/tests/Support/cases.php';
$json = json_encode(
    ['version' => 1, 'cases' => array_map(static fn (array $case): array => FixtureRunner::record($case), $cases)],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
) . "\n";

$path = __DIR__ . '/../spec/fixtures.json';

if (in_array('--check', $argv, true)) {
    $current = is_file($path) ? file_get_contents($path) : '';
    fwrite(STDERR, $current === $json ? "spec/fixtures.json is up to date.\n" : "spec/fixtures.json is out of date: run php scripts/fixtures.php\n");
    exit($current === $json ? 0 : 1);
}

file_put_contents($path, $json);
fwrite(STDOUT, 'Wrote ' . count($cases) . " cases to spec/fixtures.json\n");
