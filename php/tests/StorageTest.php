<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tests;

use Phox\ContextAtlas\Document\Document;
use Phox\ContextAtlas\Document\Unit;
use Phox\ContextAtlas\Index\Collection;
use Phox\ContextAtlas\Index\FileStore;
use Phox\ContextAtlas\Index\Lexical;
use Phox\ContextAtlas\Processor;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/stp-store-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach ([...(glob($this->dir . '/index/*') ?: []), ...(glob($this->dir . '/*.json') ?: [])] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach ([$this->dir . '/index/.lock.d', $this->dir . '/index', $this->dir] as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    public function test_the_file_store_answers_like_a_collection_after_puts_and_removes(): void
    {
        $processor = new Processor();
        $documents = [
            $processor->ingestCsv("Supplier,Amount\nCafé Ltd,1200\nRingway,300\n", 'spend', 'Spend'),
            $processor->ingest("# Fees\n\nThe fee is 2006 pounds.\n\n## Refunds\n\nRefunds take 30 days.", 'fees', 'markdown'),
            $processor->ingest("# Other\n\nRefunds are not offered here.", 'other', 'markdown'),
        ];
        $store = new FileStore($this->dir);
        $collection = new Collection();
        foreach ($documents as $document) {
            $store->put($document);
            $collection->put($document);
        }
        $store->put($documents[1]);
        $terms = ['refunds', '2006', 'supplier', 'missing'];
        $sorted = static function (Collection $c) use ($documents): Collection {
            $ids = array_map(static fn ($d): string => $d->id, $documents);
            usort($ids, static fn (string $a, string $b): int => strcmp(hash('sha256', $a), hash('sha256', $b)));

            return new Collection(array_values(array_filter(array_map(static fn (string $id) => $c->document($id), $ids))));
        };

        self::assertSame($sorted($collection)->postings($terms), $store->postings($terms));
        self::assertSame($collection->stats(), $store->stats());

        $store->remove('fees');
        $collection->remove('fees');
        $fresh = new FileStore($this->dir);
        self::assertSame($sorted($collection)->postings($terms), $fresh->postings($terms));
        self::assertSame($collection->stats(), $fresh->stats());
    }

    public function test_a_store_written_without_an_index_is_indexed_on_first_use(): void
    {
        $document = (new Processor())->ingest("# Fees\n\nThe fee is 20 pounds.", 'fees', 'markdown');
        $store = new FileStore($this->dir);
        $store->put($document);
        foreach (glob($this->dir . '/index/*') ?: [] as $file) {
            unlink($file);
        }

        self::assertSame((new Collection([$document]))->postings(['fee']), (new FileStore($this->dir))->postings(['fee']));
    }

    public function test_a_stored_document_reads_back_unchanged(): void
    {
        $document = (new Processor())->ingestCsv("Supplier,Amount\nCafé Ltd,\"£1,200\"\nRingway,£300\n", 'spend', 'Spend');
        $store = new FileStore($this->dir);
        $store->put($document);

        self::assertSame(json_encode($document->toArray()), json_encode($store->document('spend')?->toArray()));
        self::assertCount(1, glob($this->dir . '/*.json') ?: []);

        $store->remove('spend');
        self::assertNull($store->document('spend'));
    }

    public function test_artifacts_round_trip_through_json_into_a_collection(): void
    {
        $document = (new Processor())->ingestCsv("Region,Sales\nNorth,10\nSouth,20\n", 'sales', 'Sales');
        $json = json_decode((string) json_encode([$document->toArray()]), true);

        $collection = Collection::fromArray($json);

        self::assertSame(['units' => 1, 'length' => $document->lexical['length']['t1']], $collection->stats());
        self::assertSame('table', $collection->document('sales')?->unit('t1')?->kind);
    }

    public function test_exact_term_search_ranks_the_unit_holding_the_rare_term_first(): void
    {
        $units = [
            new Unit('a', 'passage', 'Invoices are due monthly. Invoices are paid by transfer.'),
            new Unit('b', 'passage', 'Invoices for the Kahre account are disputed.'),
            new Unit('c', 'passage', 'Cancellation needs thirty days notice.'),
        ];
        $collection = new Collection([new Document('d', 'guidance', $units, [], Lexical::build($units))]);

        $hits = Lexical::search($collection, 'Kahre invoices', 3);

        self::assertSame(['b', 'a'], array_column($hits, 'unit'));
        self::assertSame([], Lexical::search($collection, 'Kahre', 3, ['table']));
    }

    public function test_terms_are_letter_and_digit_runs_of_two_or_more(): void
    {
        self::assertSame(['café', 'ltd', 'paid', 'in', '2026', 'q1'], Lexical::terms('Café Ltd paid £5 in 2026 (Q1)!'));
    }
}
