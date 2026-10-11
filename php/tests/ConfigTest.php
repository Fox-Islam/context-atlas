<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tests;

use Phox\ContextAtlas\Config;
use Phox\ContextAtlas\Exceptions\ContextAtlasException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function test_snake_case_keys_round_trip(): void
    {
        $config = Config::fromArray(['max_tokens' => 500, 'cross_check' => false]);

        self::assertSame(500, $config->maxTokens);
        self::assertFalse($config->toArray()['cross_check']);
    }

    public function test_an_unknown_key_is_refused(): void
    {
        $this->expectException(ContextAtlasException::class);

        Config::fromArray(['max_token' => 500]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badValues(): iterable
    {
        yield 'a string for an integer' => [['max_tokens' => 'many'], 'Config key "max_tokens" must be an integer at least 1.'];
        yield 'a fraction for an integer' => [['max_tokens' => 2.5], 'Config key "max_tokens" must be an integer at least 1.'];
        yield 'below the minimum' => [['overlap_tokens' => -1], 'Config key "overlap_tokens" must be an integer at least 0.'];
        yield 'above the maximum' => [['seam_threshold' => 1.5], 'Config key "seam_threshold" must be a number between 0 and 1.'];
        yield 'a number for a boolean' => [['cross_check' => 1], 'Config key "cross_check" must be true or false.'];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[DataProvider('badValues')]
    public function test_a_value_outside_its_type_or_range_is_refused(array $values, string $message): void
    {
        $this->expectException(ContextAtlasException::class);
        $this->expectExceptionMessage($message);

        Config::fromArray($values);
    }

    public function test_a_whole_number_is_an_integer(): void
    {
        self::assertSame(500, Config::fromArray(['max_tokens' => 500.0])->maxTokens);
        self::assertSame(1.0, Config::fromArray(['seam_threshold' => 1])->seamThreshold);
    }

    public function test_the_schema_lists_every_key_with_its_default(): void
    {
        $schema = json_decode((string) file_get_contents(__DIR__ . '/../../spec/config.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        $defaults = array_map(static fn (array $property): mixed => $property['default'], $schema['properties']);

        self::assertSame((new Config())->toArray(), $defaults);
    }
}
