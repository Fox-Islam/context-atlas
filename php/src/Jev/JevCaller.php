<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Jev;

use Closure;
use Phox\TypeSafe\Client;
use Phox\TypeSafe\Requests\SystemOne;
use Phox\TypeSafe\Responses\ChoiceAnswer;
use Phox\TypeSafe\Responses\NoulAnswer;
use Phox\TypeSafe\Responses\SystemOneResponse;
use Throwable;

/**
 * Sends one System One request through phox/typesafe-sdk-php.
 *
 * Takes either a Client, or a closure that is handed the request builder and
 * sends it itself: `fn (Closure $build): ?SystemOneResponse`, where `$build`
 * takes a fresh `SystemOne` and returns it filled in. The closure is how a
 * caller puts its own caching, feature switches or logging around the call.
 *
 * Any failure is an unanswered call, so the caller falls back to what it does
 * without Jev.
 */
final readonly class JevCaller
{
    /**
     * @param  Client|Closure(Closure(SystemOne): SystemOne): ?SystemOneResponse  $jev
     */
    public function __construct(
        private Client|Closure $jev,
    ) {}

    /**
     * The probability of a noul's answer, or null when it is missing or came
     * back as another answer type.
     */
    public static function noul(SystemOneResponse $response, string $name): ?float
    {
        $answer = $response->answers()[$name] ?? null;

        return $answer instanceof NoulAnswer ? $answer->noul() : null;
    }

    /**
     * The label of a choice's answer, or null when it is missing or came back
     * as another answer type.
     */
    public static function choice(SystemOneResponse $response, string $name): ?string
    {
        $answer = $response->answers()[$name] ?? null;

        return $answer instanceof ChoiceAnswer ? $answer->choice() : null;
    }

    /**
     * @param  Closure(SystemOne): SystemOne  $build
     */
    public function send(Closure $build): ?SystemOneResponse
    {
        try {
            return $this->jev instanceof Client
                ? $build($this->jev->systemOne())->send()
                : ($this->jev)($build);
        } catch (Throwable) {
            return null;
        }
    }
}
