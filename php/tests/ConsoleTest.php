<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tests;

use FilesystemIterator;
use Phox\ContextAtlas\Console\Application;
use Phox\TypeSafe\Testing\FakeAnswers;
use Phox\TypeSafe\Testing\FakeTypeSafe;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ConsoleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/stp-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_chunk_reads_markdown_and_prints_json(): void
    {
        file_put_contents($this->dir . '/doc.md', "# Fees\n\nInvoices go out monthly.\n");

        [$code, $out] = $this->run_(['chunk', $this->dir . '/doc.md', '--no-jev']);

        self::assertSame(0, $code);
        self::assertSame(['Fees'], json_decode($out, true)['chunks'][0]['heading_path']);
    }

    public function test_chunk_reads_a_json_file_as_blocks(): void
    {
        file_put_contents($this->dir . '/blocks.json', '[{"type":"heading","text":"A","level":2},{"type":"paragraph","text":"Body."}]');

        [$code, $out] = $this->run_(['chunk', $this->dir . '/blocks.json', '--no-jev']);

        self::assertSame(0, $code);
        self::assertSame(2, json_decode($out, true)['outline'][0]['level']);
    }

    public function test_rank_takes_the_output_of_chunk(): void
    {
        file_put_contents($this->dir . '/chunks.json', '{"chunks":[{"text":"Delivery takes three days.","heading_path":["Shipping"]},{"text":"Returns are accepted for 30 days.","heading_path":["Returns"]}]}');
        $fake = FakeTypeSafe::make();
        $fake->reply(FakeAnswers::make()->only()->noul('c_shipping-0', 0.1)->noul('c_returns-1', 0.9)->choice('best', 'returns-1'));

        [$code, $out] = $this->run_(['rank', 'How long can I return things?', $this->dir . '/chunks.json', '--limit=1'], $fake);

        self::assertSame(0, $code);
        self::assertSame([['id' => '1', 'score' => 0.9, 'text' => 'Returns are accepted for 30 days.']], json_decode($out, true));
    }

    public function test_rank_keeps_the_ids_passages_carry(): void
    {
        file_put_contents($this->dir . '/p.json', '[{"id":"refund-policy","text":"Returns are accepted for 30 days."},{"text":"No id here."}]');
        $fake = FakeTypeSafe::make();
        $fake->reply(FakeAnswers::make()->only()->noul('c_passage-1-0', 0.9)->noul('c_passage-2-1', 0.8)->choice('best', 'passage-1-0'));

        [$code, $out] = $this->run_(['rank', 'How long can I return things?', $this->dir . '/p.json'], $fake);

        self::assertSame(0, $code);
        self::assertSame(['refund-policy', '1'], array_column(json_decode($out, true), 'id'));
    }

    public function test_an_empty_object_is_not_a_list(): void
    {
        file_put_contents($this->dir . '/empty.json', '{}');

        [$blocksCode, , $blocksErr] = $this->run_(['chunk', $this->dir . '/empty.json']);
        [$rankCode, , $rankErr] = $this->run_(['rank', 'q', $this->dir . '/empty.json']);

        self::assertSame([1, 1], [$blocksCode, $rankCode]);
        self::assertStringContainsString('must be a JSON list', $blocksErr . $rankErr);
    }

    public function test_rank_exits_2_when_jev_does_not_answer(): void
    {
        file_put_contents($this->dir . '/p.json', '[{"id":"a","text":"x"}]');
        $fake = FakeTypeSafe::make();
        $fake->fail(500);

        [$code, , $err] = $this->run_(['rank', 'q', $this->dir . '/p.json'], $fake);

        self::assertSame(2, $code);
        self::assertStringContainsString('Jev did not answer', $err);
    }

    public function test_bad_input_exits_1_with_a_message(): void
    {
        file_put_contents($this->dir . '/bad.json', '{"not":"a list"}');

        [$code, , $err] = $this->run_(['chunk', $this->dir . '/bad.json']);

        self::assertSame(1, $code);
        self::assertStringContainsString('must be a JSON list', $err);
    }

    public function test_ingest_prints_a_stored_document(): void
    {
        file_put_contents($this->dir . '/fees.md', "# Fees\n\nInvoices go out monthly.\n");

        [$code, $out] = $this->run_(['ingest', $this->dir . '/fees.md', '--no-jev']);
        $document = json_decode($out, true);

        self::assertSame(0, $code);
        self::assertSame(2, $document['format']);
        self::assertSame('fees.md', $document['id']);
        self::assertSame('guidance', $document['profile']);
    }

    public function test_ingest_saves_into_a_store_and_retrieve_reads_it(): void
    {
        file_put_contents($this->dir . '/fees.md', "# Fees\n\nInvoices go out monthly and are due within fourteen days.\n");
        $store = $this->dir . '/store';

        [$code, $out] = $this->run_(['ingest', $this->dir . '/fees.md', '--no-jev', '--id=fees', "--store={$store}"]);
        self::assertSame(0, $code);
        self::assertSame(['id' => 'fees', 'units' => 1, 'store' => $store], array_intersect_key(json_decode($out, true), ['id' => 0, 'units' => 0, 'store' => 0]));

        $fake = FakeTypeSafe::make();
        $fake->alwaysReply(static fn ($call): array => FakeAnswers::make()->only()->noul('c_p0', 0.9)->choice('best', 'p0')->choice('scope', 'one passage')->toArray($call));
        [$code, $out] = $this->run_(['retrieve', 'When are invoices due?', "--store={$store}", '--documents=fees'], $fake);
        $context = json_decode($out, true);

        self::assertSame(0, $code);
        self::assertFalse($context['abstained']);
        self::assertSame([['document' => 'fees', 'unit' => 'p1']], $context['sources']);
    }

    public function test_reading_stdin_needs_a_format_and_an_id(): void
    {
        [$code, , $err] = $this->run_(['ingest', '-', '--format=markdown']);

        self::assertSame(1, $code);
        self::assertSame("Reading stdin needs --id.\n", $err);
    }

    public function test_retrieve_exits_2_when_jev_does_not_answer_and_1_without_a_store(): void
    {
        file_put_contents($this->dir . '/fees.md', "# Fees\n\nInvoices go out monthly.\n");
        $store = $this->dir . '/store';
        $this->run_(['ingest', $this->dir . '/fees.md', '--no-jev', "--store={$store}"]);
        $fake = FakeTypeSafe::make();
        $fake->fail(500);

        [$code, , $err] = $this->run_(['retrieve', 'invoices', "--store={$store}"], $fake);
        self::assertSame(2, $code);
        self::assertStringContainsString('Jev did not answer', $err);

        [$code, , $err] = $this->run_(['retrieve', 'invoices', '--store=' . $this->dir . '/missing']);
        self::assertSame(1, $code);
        self::assertStringContainsString('is not a directory', $err);
    }

    /**
     * @param  list<string>  $argv
     * @return array{int, string, string}
     */
    private function run_(array $argv, ?FakeTypeSafe $fake = null): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);
        $code = (new Application(($fake ?? FakeTypeSafe::make())->client(), $stdout, $stderr))->run($argv);
        rewind($stdout);
        rewind($stderr);

        return [$code, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }
}
