<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tests;

use Phox\ContextAtlas\Processor;
use Phox\ContextAtlas\Tabular\Executor;
use Phox\ContextAtlas\Tabular\TabularProfile;
use PHPUnit\Framework\TestCase;

final class TabularExecutorTest extends TestCase
{
    public function test_an_ungrouped_change_over_no_records_is_no_data(): void
    {
        $document = (new Processor())->ingestCsv("Country,Year,Value\nKenya,2019,10\nKenya,2020,12\n", 'pop', 'Population');
        $table = TabularProfile::table($document->data['tables'][0]);

        $result = Executor::run($table, ['measure' => 'Value', 'operation' => 'change', 'group' => null, 'filters' => ['Country' => ['in', ['Nowhere']], 'Year' => ['range', 2019.0, 2020.0]], 'drop_blank' => []]);

        self::assertStringContainsString('= no data (2019: no data; 2020: no data)', $result['text']);
    }
}
