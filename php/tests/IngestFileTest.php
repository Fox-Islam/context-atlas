<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tests;

use Phox\ContextAtlas\Processor;
use PHPUnit\Framework\TestCase;

final class IngestFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/context-atlas-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    public function test_a_chat_export_saved_as_json_is_read_as_a_conversation(): void
    {
        $path = $this->write('export.json', '{"messages": [{"role": "user", "content": "Where is the staging config?"}, {"role": "assistant", "content": "In deploy/staging.yaml."}]}');

        $document = (new Processor())->ingestFile($path, 'export');

        self::assertSame('conversational', $document->profile);
        self::assertSame(['message', 'message'], array_map(static fn ($unit): string => $unit->kind, $document->units));
    }

    public function test_chat_lines_saved_as_jsonl_are_read_as_a_conversation(): void
    {
        $path = $this->write('session.jsonl', "{\"role\": \"user\", \"content\": \"Run the tests\"}\n{\"role\": \"assistant\", \"content\": \"All 12 pass.\"}\n");

        self::assertSame('conversational', (new Processor())->ingestFile($path, 'session')->profile);
    }

    public function test_json_the_chat_reader_does_not_recognise_stays_data(): void
    {
        $path = $this->write('config.json', '{"service": "api", "replicas": 3, "data": [{"region": "eu-west-1"}]}');

        self::assertSame('markup', (new Processor())->ingestFile($path, 'config')->profile);
    }

    public function test_a_single_message_stays_data(): void
    {
        $path = $this->write('one.json', '{"messages": [{"role": "user", "content": "Hello"}]}');

        self::assertSame('markup', (new Processor())->ingestFile($path, 'one')->profile);
    }

    private function write(string $name, string $contents): string
    {
        file_put_contents($this->directory . '/' . $name, $contents);

        return $this->directory . '/' . $name;
    }
}
