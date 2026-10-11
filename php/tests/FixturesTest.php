<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tests;

use Phox\ContextAtlas\Tests\Support\FixtureRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * spec/fixtures.json is what the JavaScript and Python packages are held to,
 * so it has to match what this package does.
 */
final class FixturesTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function cases(): iterable
    {
        $recorded = json_decode((string) file_get_contents(__DIR__ . '/../../spec/fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
        $cases = require __DIR__ . '/Support/cases.php';

        foreach ($cases as $index => $case) {
            yield $case['name'] => [$case, $recorded['cases'][$index] ?? []];
        }
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $recorded
     */
    #[DataProvider('cases')]
    public function test_the_package_still_does_what_the_fixture_records(array $case, array $recorded): void
    {
        $actual = json_decode(json_encode(FixtureRunner::record($case), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true);

        self::assertSame($recorded, $actual, 'spec/fixtures.json is out of date: run php scripts/fixtures.php');
    }
}
