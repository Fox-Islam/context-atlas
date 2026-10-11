<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Prose;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Document\Link;
use Phox\ContextAtlas\Document\Unit;
use Phox\ContextAtlas\Index\Lexical;
use Phox\ContextAtlas\Jev\JevCaller;
use Phox\ContextAtlas\Text;
use Phox\TypeSafe\Questions\Choice;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Requests\SystemOne;
use Phox\TypeSafe\Responses\SystemOneResponse;

/**
 * Conversations: transcripts, minutes, chat logs, discussion pages. Blocks carry who spoke in
 * `meta.speaker`, and optionally `turn`, `time` and `reply_to` (the turn a message answers).
 *
 * Each turn becomes message units of at most PART_TOKENS tokens, headed
 * `[section | speaker, time]`. Links: `reply_to` (from the source, else a Jev choice over the
 * REPLY_WINDOW messages before), and,
 * judged per message part because a long speech hides its outcome at the end, `decides` (an
 * outcome to what it settles) and `corrects` (a later message to the earlier one it updates).
 *
 * A block with `meta.attachment` (a tool result, reasoning) is a turn of its own: `output` units
 * cut by lines, linked `output_of` the turn it belongs to, and left out of every Jev question.
 */
final readonly class Conversation
{
    private const int PART_TOKENS = 400;

    private const int REPLY_WINDOW = 6;

    public function __construct(
        private ?JevCaller $jev,
        private int $charsPerToken = 4,
        private bool $replyDetection = true,
        private bool $outcomeLinks = true,
        private bool $correctionLinks = true,
    ) {}

    /**
     * @param  array<int, array{block: Block, path: list<string>}>  $blocks  a section's blocks in order, keyed by block index, with heading paths
     * @param  list<Unit>  $prior  the message and output units of the conversation these blocks continue, in order
     * @return array{units: list<Unit>, links: list<Link>} the new units, and links from them
     */
    public function build(array $blocks, int $offset, array $prior = []): array
    {
        [$turns, $firstPart, $bySource] = self::priorTurns($prior);
        $fresh = count($turns);
        $turns = [...$turns, ...self::turns($blocks)];
        $units = [];

        foreach ($turns as $t => $turn) {
            if (isset($firstPart[$t])) {
                continue;
            }
            $bySource[(string) ($turn['turn'] ?? $t)] = $t;
            $attachment = $turn['attachment'];
            $parts = $attachment ? self::split(implode("\n", $turn['texts']), $this->charsPerToken) : self::parts($turn['texts'], $this->charsPerToken);
            foreach ($parts as $p => $text) {
                $id = ($attachment ? 'a' : 'm') . ($offset + count($units) + 1);
                $firstPart[$t] ??= $id;
                $meta = ['speaker' => $turn['speaker'], 'time' => $turn['time'], 'turn' => $firstPart[$t], 'blocks' => $turn['blocks'], 'profile' => Profiles::CONVERSATIONAL] + ($attachment ? ['attachment' => true] : []) + ($turn['turn'] !== null ? ['source_turn' => $turn['turn']] : []);
                $units[] = new Unit($id, $attachment ? 'output' : 'message', self::header($turn) . ($p > 0 ? ' (continued)' : '') . "\n" . $text, $turn['path'], $meta);
            }
        }

        $replies = $this->replies($turns, $bySource, $firstPart, [...$prior, ...$units], $fresh);
        $isMessage = static fn (Unit $u): bool => $u->kind === 'message';
        $earlier = array_values(array_filter($prior, $isMessage));
        [$outcomes, $decisions] = $this->outcomeLinks ? $this->decisions(array_values(array_filter($units, $isMessage)), $earlier) : [[], []];
        $units = array_map(static fn (Unit $u): Unit => isset($outcomes[$u->id]) ? new Unit($u->id, $u->kind, $u->text, $u->path, [...$u->meta, 'outcome' => true]) : $u, $units);
        $corrections = $this->correctionLinks ? $this->corrections(array_values(array_filter($units, $isMessage)), $earlier, array_values(array_filter($replies, static fn (Link $l): bool => $l->type === 'reply_to'))) : [];

        return ['units' => $units, 'links' => [...$replies, ...$decisions, ...$corrections]];
    }

    /**
     * The turns of the units a conversation continues from, with their first parts and their
     * source turn ids, so new turns can link back to them.
     *
     * @param  list<Unit>  $prior
     * @return array{list<array{turn: string|null, speaker: string|null, speaker_full: string|null, time: string|null, reply_to: string|null, path: list<string>, texts: list<string>, blocks: list<int>, attachment: bool}>, array<int, string>, array<string, int>}
     */
    private static function priorTurns(array $prior): array
    {
        $turns = [];
        $firstPart = [];
        $bySource = [];
        foreach ($prior as $unit) {
            if (($unit->meta['turn'] ?? $unit->id) !== $unit->id) {
                continue;
            }
            $t = count($turns);
            $source = isset($unit->meta['source_turn']) ? (string) $unit->meta['source_turn'] : null;
            $turns[] = ['turn' => $source, 'speaker' => is_string($unit->meta['speaker'] ?? null) ? $unit->meta['speaker'] : null, 'speaker_full' => null, 'time' => null, 'reply_to' => null, 'path' => $unit->path, 'texts' => [], 'blocks' => [], 'attachment' => $unit->kind === 'output'];
            $firstPart[$t] = $unit->id;
            $bySource[$source ?? (string) $t] = $t;
        }

        return [$turns, $firstPart, $bySource];
    }

    /**
     * An attachment's text cut into parts of at most PART_TOKENS: whole lines while they fit,
     * and a line longer than that cut every PART_TOKENS × charsPerToken characters.
     *
     * @return list<string>
     */
    private static function split(string $text, int $charsPerToken): array
    {
        $width = self::PART_TOKENS * max(1, $charsPerToken);
        $parts = [];
        $buffer = [];
        $size = 0;
        foreach (explode("\n", $text) as $line) {
            $pieces = mb_strlen($line) > $width ? mb_str_split($line, $width) : [$line];
            foreach ($pieces as $piece) {
                $tokens = Text::tokens($piece, $charsPerToken);
                if ($buffer !== [] && $size + $tokens > self::PART_TOKENS) {
                    $parts[] = implode("\n", $buffer);
                    $buffer = [];
                    $size = 0;
                }
                $buffer[] = $piece;
                $size += $tokens;
            }
        }
        $parts[] = implode("\n", $buffer);

        return $parts;
    }

    /**
     * `[section | speaker, time]`, naming the speaker in full when the source does.
     *
     * @param  array{speaker: string|null, speaker_full: string|null, time: string|null, path: list<string>}  $turn
     */
    private static function header(array $turn): string
    {
        $who = ($turn['speaker_full'] ?? $turn['speaker'] ?? 'unattributed') . ($turn['time'] !== null ? ', ' . $turn['time'] : '');

        return '[' . ($turn['path'] !== [] ? implode(' > ', $turn['path']) . ' | ' : '') . $who . ']';
    }

    /**
     * Consecutive blocks with the same turn (or, without turn numbers, the same speaker) form a
     * turn.
     *
     * @param  array<int, array{block: Block, path: list<string>}>  $blocks
     * @return list<array{turn: string|null, speaker: string|null, speaker_full: string|null, time: string|null, reply_to: string|null, path: list<string>, texts: list<string>, blocks: list<int>, attachment: bool}>
     */
    private static function turns(array $blocks): array
    {
        $turns = [];
        foreach ($blocks as $i => $item) {
            if ($item['block']->isHeading()) {
                continue;
            }
            $meta = $item['block']->meta;
            $speaker = is_string($meta['speaker'] ?? null) ? $meta['speaker'] : null;
            $turn = isset($meta['turn']) ? (string) $meta['turn'] : null;
            $attachment = ($meta['attachment'] ?? false) === true;
            $last = $turns === [] ? null : $turns[count($turns) - 1];
            $same = $last !== null && ! $attachment && ! $last['attachment'] && $last['path'] === $item['path'] && ($turn !== null ? $last['turn'] === $turn : $last['speaker'] === $speaker);
            if ($same) {
                $turns[count($turns) - 1]['texts'][] = Text::trim($item['block']->text);
                $turns[count($turns) - 1]['blocks'][] = $i;

                continue;
            }
            $turns[] = [
                'turn' => $turn,
                'speaker' => $speaker,
                'speaker_full' => is_string($meta['speaker_full'] ?? null) ? $meta['speaker_full'] : null,
                'time' => isset($meta['time']) ? (string) $meta['time'] : null,
                'reply_to' => isset($meta['reply_to']) ? (string) $meta['reply_to'] : null,
                'path' => $item['path'],
                'texts' => [Text::trim($item['block']->text)],
                'blocks' => [$i],
                'attachment' => $attachment,
            ];
        }

        return $turns;
    }

    /**
     * A turn's text split into parts of at most PART_TOKENS, at block boundaries.
     *
     * @param  list<string>  $texts
     * @return list<string>
     */
    private static function parts(array $texts, int $charsPerToken): array
    {
        $parts = [];
        $buffer = [];
        $size = 0;
        foreach ($texts as $text) {
            $tokens = Text::tokens($text, $charsPerToken);
            if ($buffer !== [] && $size + $tokens > self::PART_TOKENS) {
                $parts[] = implode("\n", $buffer);
                $buffer = [];
                $size = 0;
            }
            $buffer[] = $text;
            $size += $tokens;
        }
        if ($buffer !== []) {
            $parts[] = implode("\n", $buffer);
        }

        return $parts;
    }

    /**
     * @param  list<array{turn: string|null, speaker: string|null, speaker_full: string|null, time: string|null, reply_to: string|null, path: list<string>, texts: list<string>, blocks: list<int>, attachment: bool}>  $turns
     * @param  array<string, int>  $bySource
     * @param  array<int, string>  $firstPart
     * @param  list<Unit>  $units
     * @param  int  $fresh  the number of turns at the start that already exist and get no new links
     * @return list<Link>
     */
    private function replies(array $turns, array $bySource, array $firstPart, array $units, int $fresh): array
    {
        $links = [];
        $ask = [];
        $messages = [];
        foreach ($turns as $t => $turn) {
            if ($t < $fresh) {
                if (! $turn['attachment']) {
                    $messages[] = $t;
                }

                continue;
            }
            $parent = $turn['reply_to'] !== null ? ($bySource[$turn['reply_to']] ?? null) : null;
            if ($parent !== null && $parent < $t) {
                $links[$t] = new Link($turn['attachment'] ? 'output_of' : 'reply_to', $firstPart[$t], $firstPart[$parent]);
            } elseif ($turn['attachment'] && $messages !== []) {
                $links[$t] = new Link('output_of', $firstPart[$t], $firstPart[$messages[count($messages) - 1]]);
            } elseif ($this->replyDetection && ! $turn['attachment'] && $turn['reply_to'] === null && $messages !== [] && $turn['speaker'] !== null) {
                $ask[$t] = array_slice($messages, -self::REPLY_WINDOW);
            }
            if (! $turn['attachment']) {
                $messages[] = $t;
            }
        }

        $byId = [];
        foreach ($units as $unit) {
            $byId[$unit->id] = $unit->text;
        }
        $text = array_map(static fn (string $id): string => $byId[$id], $firstPart);

        foreach (array_chunk($ask, 6, true) as $batch) {
            $window = [];
            $questions = [];
            foreach ($batch as $t => $earlier) {
                foreach ([...$earlier, $t] as $m) {
                    $window["m{$m}"] = Text::head($text[$m], 400);
                }
                $questions["r{$t}"] = array_map(static fn (int $m): string => "m{$m}", $earlier);
            }
            ksort($window, SORT_NATURAL);
            $response = $this->jev?->send(static function (SystemOne $request) use ($window, $questions): SystemOne {
                $request->state(['messages in order' => $window]);
                foreach ($questions as $key => $options) {
                    $self = 'm' . substr($key, 1);
                    $request->ask($key, Choice::between([...$options, 'none'])->instructions("Which earlier message is `{$self}` a direct reply to? A reply answers, agrees or disagrees with, or takes up a point from that message. Pick `none` if `{$self}` starts a new point rather than replying."));
                }

                return $request;
            });
            if (! $response instanceof SystemOneResponse) {
                continue;
            }
            foreach ($questions as $key => $options) {
                $pick = JevCaller::choice($response, $key);
                if ($pick !== null && in_array($pick, $options, true)) {
                    $t = (int) substr($key, 1);
                    $links[$t] = new Link('reply_to', $firstPart[$t], $firstPart[(int) substr($pick, 1)]);
                }
            }
        }

        ksort($links);

        return array_values($links);
    }

    /**
     * Message parts that record an outcome, each linked to the earlier message it settles.
     *
     * @param  list<Unit>  $units  the message parts to judge
     * @param  list<Unit>  $prior  message parts before them, which settle candidates may come from
     * @return array{array<string, true>, list<Link>} the outcome units' ids, and the links
     */
    private function decisions(array $units, array $prior = []): array
    {
        $outcomes = [];
        foreach (array_chunk($units, 20) as $batch) {
            $labels = [];
            foreach ($batch as $unit) {
                $labels[$unit->id] = $unit;
            }
            $response = $this->jev?->send(static function (SystemOne $request) use ($labels): SystemOne {
                $request->state(['messages' => array_map(static fn (Unit $u): string => self::headAndEnd($u->text, 600), $labels)]);
                foreach (array_keys($labels) as $label) {
                    $request->ask($label, Noul::ask("Does `{$label}` record an outcome of the discussion?")
                        ->yes("`{$label}` states what was decided, agreed, accepted, rejected or concluded, such as a closing statement, a vote result or a resolution")
                        ->no("`{$label}` argues, asks, proposes or comments without recording an outcome"));
                }

                return $request;
            });
            if (! $response instanceof SystemOneResponse) {
                continue;
            }
            foreach (array_keys($labels) as $label) {
                if ((JevCaller::noul($response, (string) $label) ?? 0.0) >= 0.5) {
                    $outcomes[$label] = $labels[$label];
                }
            }
        }

        $links = [];
        foreach ($outcomes as $outcome) {
            $earlier = self::earlier([...$prior, ...$units], $outcome);
            if ($earlier === []) {
                continue;
            }
            $firstOfSection = [];
            foreach ($earlier as $unit) {
                $firstOfSection[implode(' > ', $unit->path)] ??= $unit;
            }
            $candidates = self::unique([...array_values($firstOfSection), ...self::similar($outcome, $earlier, 5)]);
            $labels = [];
            foreach ($candidates as $unit) {
                $labels[$unit->id] = $unit;
            }
            $response = $this->jev?->send(static fn (SystemOne $request): SystemOne => $request
                ->state(['outcome' => self::headAndEnd($outcome->text, 800), 'earlier messages' => array_map(static fn (Unit $u): string => Text::head($u->text, 400), $labels)])
                ->ask('settles', Choice::between([...array_keys($labels), 'none'])->instructions('Which earlier message states the proposal, motion or question that the outcome settles? Pick `none` if none of them does.')));
            $pick = $response instanceof SystemOneResponse ? JevCaller::choice($response, 'settles') : null;
            if ($pick !== null && isset($labels[$pick])) {
                $links[] = new Link('decides', $outcome->id, $pick);
            }
        }

        return [array_map(static fn (): bool => true, $outcomes), $links];
    }

    /**
     * Pairs of each message part with its reply parent and its three most similar earlier
     * parts; Jev keeps those where the later corrects or updates the earlier.
     *
     * @param  list<Unit>  $units  the message parts to judge
     * @param  list<Unit>  $prior  message parts before them, which pairs may take the earlier part from
     * @param  list<Link>  $replies
     * @return list<Link>
     */
    private function corrections(array $units, array $prior, array $replies): array
    {
        $parent = [];
        foreach ($replies as $link) {
            $parent[$link->from] = $link->to;
        }

        $pairs = [];
        foreach ($units as $unit) {
            $earlier = self::earlier([...$prior, ...$units], $unit);
            if ($earlier === [] || $unit->meta['speaker'] === null) {
                continue;
            }
            $candidates = self::similar($unit, $earlier, 3);
            if (isset($parent[$unit->id])) {
                foreach ($earlier as $e) {
                    if ($e->id === $parent[$unit->id]) {
                        array_unshift($candidates, $e);
                    }
                }
            }
            foreach (self::unique($candidates) as $candidate) {
                $pairs[] = [$candidate, $unit];
            }
        }
        $keyed = [];
        foreach ($pairs as $i => $pair) {
            $keyed["k{$i}"] = $pair;
        }

        $links = [];
        foreach (array_chunk($keyed, 15, true) as $batch) {
            $response = $this->jev?->send(static function (SystemOne $request) use ($batch): SystemOne {
                $request->state(['pairs' => array_map(static fn (array $p): array => ['earlier' => self::headAndEnd($p[0]->text, 500), 'later' => self::headAndEnd($p[1]->text, 500)], $batch)]);
                foreach (array_keys($batch) as $key) {
                    $request->ask((string) $key, Noul::ask("In pair {$key}, does `later` correct or update something stated in `earlier`?")
                        ->yes('`later` says a fact, figure, claim or position in `earlier` is wrong, incomplete or out of date, gives a different figure for the same thing, or the earlier speaker takes it back')
                        ->no('`later` agrees with, adds to, answers or is unrelated to `earlier` without changing what it states'));
                }

                return $request;
            });
            if (! $response instanceof SystemOneResponse) {
                continue;
            }
            foreach ($batch as $key => [$earlier, $later]) {
                if ((JevCaller::noul($response, (string) $key) ?? 0.0) >= 0.5) {
                    $links[] = new Link('corrects', $later->id, $earlier->id);
                }
            }
        }

        return $links;
    }

    /**
     * The units before this one.
     *
     * @param  list<Unit>  $units
     * @return list<Unit>
     */
    private static function earlier(array $units, Unit $unit): array
    {
        $out = [];
        foreach ($units as $u) {
            if ($u->id === $unit->id) {
                break;
            }
            $out[] = $u;
        }

        return $out;
    }

    /**
     * The units most similar to this one by term overlap (cosine of term counts), most
     * similar first, ties in document order.
     *
     * @param  list<Unit>  $units
     * @return list<Unit>
     */
    public static function similar(Unit $unit, array $units, int $limit): array
    {
        $vector = self::termCounts($unit->text);
        $scores = [];
        foreach ($units as $i => $other) {
            $scores[$i] = self::cosine($vector, self::termCounts($other->text));
        }
        arsort($scores);

        return array_map(static fn (int $i): Unit => $units[$i], array_slice(array_keys(array_filter($scores, static fn (float $s): bool => $s > 0.0)), 0, $limit));
    }

    /**
     * @return array<string, int>
     */
    public static function termCounts(string $text): array
    {
        return array_count_values(Lexical::terms(self::body($text)));
    }

    /**
     * A message's text without its `[section | speaker, time]` header line.
     */
    public static function body(string $text): string
    {
        return (string) preg_replace('/^\[[^\]\n]*\]( \(continued\))?\n/u', '', $text);
    }

    /**
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0;
        foreach ($a as $term => $count) {
            $dot += $count * ($b[$term] ?? 0);
        }
        $norm = sqrt(array_sum(array_map(static fn (int $c): int => $c * $c, $a))) * sqrt(array_sum(array_map(static fn (int $c): int => $c * $c, $b)));

        return $norm > 0 ? $dot / $norm : 0.0;
    }

    /**
     * A long message shown as its start and its end: outcomes and corrections often close it.
     */
    private static function headAndEnd(string $text, int $chars): string
    {
        $text = Text::trim($text);

        return mb_strlen($text) <= $chars ? $text : mb_substr($text, 0, intdiv($chars, 2)) . ' … ' . mb_substr($text, -intdiv($chars, 2));
    }

    /**
     * @param  list<Unit>  $units
     * @return list<Unit>
     */
    private static function unique(array $units): array
    {
        $out = [];
        foreach ($units as $unit) {
            $out[$unit->id] ??= $unit;
        }

        return array_values($out);
    }
}
