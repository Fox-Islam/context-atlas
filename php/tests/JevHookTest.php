<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tests;

use Closure;
use Phox\ContextAtlas\Chunker;
use Phox\ContextAtlas\Parsing\PlainTextParser;
use Phox\ContextAtlas\Reranker;
use Phox\ContextAtlas\Retrieval\Passage;
use Phox\TypeSafe\Responses\SystemOneResponse;
use Phox\TypeSafe\Testing\FakeAnswers;
use Phox\TypeSafe\Testing\FakeTypeSafe;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JevHookTest extends TestCase
{
    public function test_a_closure_sends_the_request_itself(): void
    {
        $fake = FakeTypeSafe::make();
        $fake->reply(FakeAnswers::make()->only()->noul('c_passage-1-0', 0.9)->choice('best', 'passage-1-0'));
        $sent = 0;

        $jev = static function (Closure $build) use ($fake, &$sent): ?SystemOneResponse {
            $sent++;

            return $build($fake->client()->systemOne())->send();
        };

        $ranked = (new Reranker($jev))->rank([new Passage('a', 'Returns are accepted for 30 days.')], 'How long can I return things?');

        $state = $fake->lastCall()->state();

        self::assertSame(1, $sent);
        self::assertNotNull($ranked);
        self::assertSame('a', $ranked[0]->passage->id ?? null);
        self::assertIsArray($state);
        self::assertSame('How long can I return things?', $state['the question being asked'] ?? null);
    }

    public function test_a_closure_that_throws_is_an_unanswered_call(): void
    {
        $jev = static fn (Closure $build): SystemOneResponse => throw new RuntimeException('down');

        self::assertNull((new Reranker($jev))->rank([new Passage('a', 'text')], 'query'));
    }

    public function test_a_closure_returning_null_leaves_the_chunks_structural(): void
    {
        $jev = static fn (Closure $build): ?SystemOneResponse => null;
        $text = str_repeat('Invoices go out monthly and are due in fourteen days. ', 10) . "\n\n" . str_repeat('Either party may cancel with thirty days notice. ', 10);

        $chunked = (new Chunker($jev))->chunk(PlainTextParser::parse($text));

        self::assertFalse($chunked->usedJev);
        self::assertCount(1, $chunked->chunks);
    }
}
