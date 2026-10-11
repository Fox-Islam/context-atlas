<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Prose;

use Phox\ContextAtlas\Document\Document;
use Phox\ContextAtlas\Document\Unit;
use Phox\ContextAtlas\Jev\JevCaller;
use Phox\ContextAtlas\Text;
use Phox\TypeSafe\Questions\Choice;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Requests\SystemOne;
use Phox\TypeSafe\Responses\SystemOneResponse;

/**
 * Gathers messages for a question about a conversation. One request ranks the candidates and
 * asks what shape a full answer has and whose words it is about; the shape decides which links
 * are followed:
 *
 * - every kept message shorter than SHORT_TOKENS tokens brings the message it replies to, since
 *   "I agree" means nothing alone;
 * - every kept output (a tool result or other attachment) brings the message it is `output_of`;
 * - `exchange` adds the message replied to and the two replies closest to the question;
 * - `correction` adds what the message corrects and what corrects it;
 * - `outcome` adds what a kept message decides or is decided by, and the outcome closest to the
 *   question with what it settles;
 * - `one person` asks of up to 12 more of that speaker's messages whether they add to the answer;
 * - `overview` asks the same of the next 18 candidates.
 *
 * "Closest to the question" is term overlap with the question, so retrieval needs no vectors.
 */
final readonly class ConversationRetriever
{
    private const float KEEP = 0.4;

    private const int MESSAGE_CHARS = 1600;

    private const int CHECK_CHARS = 1200;

    private const int SHORT_TOKENS = 60;

    private const string NOBODY = 'nobody in particular';

    public function __construct(
        private JevCaller $jev,
        private int $charsPerToken = 4,
    ) {}

    /**
     * @param  list<array{document: Document, unit: Unit}>  $candidates  best first
     * @return array{units: list<array{document: Document, unit: Unit}>, trace: array<string, mixed>}|null null when Jev did not answer
     */
    public function retrieve(string $query, array $candidates): ?array
    {
        $pool = array_slice($candidates, 0, Profiles::POOL[Profiles::CONVERSATIONAL]);
        $labels = [];
        foreach ([...$pool, ...self::outcomesToAdd($query, $pool)] as $i => $candidate) {
            $labels["message-{$i}"] = $candidate;
        }
        $speakers = self::speakers($labels);

        $response = $this->jev->send(static fn (SystemOne $request): SystemOne => self::rankRequest($request, $query, $labels, $speakers));
        if (! $response instanceof SystemOneResponse) {
            return null;
        }

        $scores = [];
        foreach (array_keys($labels) as $label) {
            $scores[$label] = JevCaller::noul($response, "c_{$label}") ?? 0.0;
        }
        $kept = array_filter($scores, static fn (float $s): bool => $s >= self::KEEP);
        arsort($kept);
        $best = JevCaller::choice($response, 'best');
        if ($best !== null && isset($kept[$best])) {
            $kept = [$best => $kept[$best]] + $kept;
        }
        $shapes = Profiles::SCOPES[Profiles::CONVERSATIONAL];
        $shape = isset($shapes[(string) JevCaller::choice($response, 'scope')]) ? (string) JevCaller::choice($response, 'scope') : 'one message';
        $speaker = (string) JevCaller::choice($response, 'speaker');
        $speaker = isset($speakers[$speaker]) ? $speaker : self::NOBODY;
        $trace = ['profile' => Profiles::CONVERSATIONAL, 'scope' => $shape, 'speaker' => $speaker, 'scores' => $scores];

        if ($kept === []) {
            return ['units' => [], 'trace' => $trace];
        }

        $kept = array_map(static fn (string|int $label): array => $labels[(string) $label], array_slice(array_keys($kept), 0, $shape === 'overview' ? 6 : 3));

        return ['units' => Retriever::inDocumentOrder(Retriever::unique($this->expand($query, $kept, $candidates, $shape, $speaker))), 'trace' => $trace];
    }

    /**
     * The two outcome parts of the pool's documents closest to the question, when the search
     * missed them: a closing statement shares few words with "what was decided?".
     *
     * @param  list<array{document: Document, unit: Unit}>  $pool
     * @return list<array{document: Document, unit: Unit}>
     */
    private static function outcomesToAdd(string $query, array $pool): array
    {
        $have = array_flip(array_map(Retriever::key(...), $pool));

        return array_slice(array_values(array_filter(self::closest($query, self::outcomes($pool)), static fn (array $c): bool => ! isset($have[Retriever::key($c)]))), 0, 2);
    }

    /**
     * @param  list<array{document: Document, unit: Unit}>  $candidates
     * @return list<array{document: Document, unit: Unit}>
     */
    private static function outcomes(array $candidates): array
    {
        $out = [];
        foreach (self::documents($candidates) as $document) {
            foreach ($document->units as $unit) {
                if (($unit->meta['outcome'] ?? false) === true) {
                    $out[] = ['document' => $document, 'unit' => $unit];
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, array{document: Document, unit: Unit}>  $labels
     * @param  array<string, string>  $speakers
     */
    private static function rankRequest(SystemOne $request, string $query, array $labels, array $speakers): SystemOne
    {
        $request->state([Retriever::QUESTION => $query, 'the messages the search found' => array_map(static fn (array $c): string => Text::head($c['unit']->text, self::MESSAGE_CHARS), $labels)]);
        foreach (array_keys($labels) as $label) {
            $request->ask("c_{$label}", Retriever::answers($label));
        }
        $request->ask('best', Choice::between(array_keys($labels))->instructions('Which single one answers the question most directly?'));
        $request->ask('scope', Choice::between(Profiles::SCOPES[Profiles::CONVERSATIONAL])->instructions('What does a full answer to the question need?'));
        if ($speakers !== []) {
            $request->ask('speaker', Choice::between($speakers + [self::NOBODY => 'the question does not single out one person'])->instructions('Whose words is the question about?'));
        }

        return $request;
    }

    /**
     * The speakers of the candidates' documents, keyed by slug, with the name the source gives
     * them in full.
     *
     * @param  array<string, array{document: Document, unit: Unit}>  $labels
     * @return array<string, string>
     */
    private static function speakers(array $labels): array
    {
        $speakers = [];
        foreach (self::documents(array_values($labels)) as $document) {
            foreach ($document->units as $unit) {
                $speaker = $unit->meta['speaker'] ?? null;
                if (is_string($speaker) && $speaker !== '') {
                    $speakers[Text::slug($speaker, 30) ?: 'speaker'] ??= self::fullName($unit) ?? $speaker;
                }
            }
        }

        return $speakers;
    }

    private static function fullName(Unit $unit): ?string
    {
        return preg_match('/^\[(?:[^\]\n]* \| )?([^\]\n]*?)(?:, [^,\]\n]*)?\]/u', $unit->text, $match) === 1 && $match[1] !== '' ? $match[1] : null;
    }

    /**
     * @param  list<array{document: Document, unit: Unit}>  $kept
     * @param  list<array{document: Document, unit: Unit}>  $candidates
     * @return list<array{document: Document, unit: Unit}>
     */
    private function expand(string $query, array $kept, array $candidates, string $shape, string $speaker): array
    {
        $add = [];
        foreach ($kept as $candidate) {
            if ($candidate['unit']->kind === 'output') {
                $add = [...$add, ...array_map(static fn (array $owner): array => self::closestPart($query, $owner), self::linkedFrom($candidate, 'output_of'))];

                continue;
            }
            $parent = self::parent($candidate);
            if ($parent !== null && ($shape === 'exchange' || $this->turnTokens($candidate) < self::SHORT_TOKENS)) {
                $add[] = self::closestPart($query, $parent);
            }
            $add = [...$add, ...match ($shape) {
                'exchange' => array_map(static fn (array $reply): array => self::closestPart($query, $reply), array_slice(self::closest($query, self::replies($candidate)), 0, 2)),
                'correction' => self::linked($candidate, 'corrects'),
                'outcome' => self::linked($candidate, 'decides'),
                default => [],
            }];
        }

        if ($shape === 'outcome') {
            $add = [...$add, ...self::nearestOutcome($query, $kept)];
        }
        if ($shape === 'one person' && $speaker !== self::NOBODY) {
            $add = [...$add, ...$this->check($query, array_slice(self::closest($query, self::spokenBy($kept, $speaker)), 0, 12))];
        }
        if ($shape === 'overview') {
            $have = array_flip(array_map(Retriever::key(...), $kept));
            $add = [...$add, ...$this->check($query, array_values(array_filter(array_slice($candidates, 0, 18), static fn (array $c): bool => ! isset($have[Retriever::key($c)]))))];
        }

        return [...$kept, ...$add];
    }

    /**
     * The first part of the turn a message replies to.
     *
     * @param  array{document: Document, unit: Unit}  $candidate
     * @return array{document: Document, unit: Unit}|null
     */
    private static function parent(array $candidate): ?array
    {
        $first = self::turn($candidate)[0] ?? null;
        foreach ($candidate['document']->links as $link) {
            if ($first !== null && $link->type === 'reply_to' && $link->from === $first['unit']->id) {
                $unit = $candidate['document']->unit($link->to);

                return $unit === null ? null : ['document' => $candidate['document'], 'unit' => $unit];
            }
        }

        return null;
    }

    /**
     * The first parts of the turns that reply to this message's turn.
     *
     * @param  array{document: Document, unit: Unit}  $candidate
     * @return list<array{document: Document, unit: Unit}>
     */
    private static function replies(array $candidate): array
    {
        $first = self::turn($candidate)[0] ?? null;
        $out = [];
        foreach ($candidate['document']->links as $link) {
            $unit = $link->type === 'reply_to' && $first !== null && $link->to === $first['unit']->id ? $candidate['document']->unit($link->from) : null;
            if ($unit !== null) {
                $out[] = ['document' => $candidate['document'], 'unit' => $unit];
            }
        }

        return $out;
    }

    /**
     * Units that the first part of this unit's turn links to with the given type.
     *
     * @param  array{document: Document, unit: Unit}  $candidate
     * @return list<array{document: Document, unit: Unit}>
     */
    private static function linkedFrom(array $candidate, string $type): array
    {
        $first = self::turn($candidate)[0]['unit']->id ?? $candidate['unit']->id;
        $out = [];
        foreach ($candidate['document']->links as $link) {
            $unit = $link->type === $type && $link->from === $first ? $candidate['document']->unit($link->to) : null;
            if ($unit !== null) {
                $out[] = ['document' => $candidate['document'], 'unit' => $unit];
            }
        }

        return $out;
    }

    /**
     * Units linked to any part of this message's turn by the given type, in either direction.
     *
     * @param  array{document: Document, unit: Unit}  $candidate
     * @return list<array{document: Document, unit: Unit}>
     */
    private static function linked(array $candidate, string $type): array
    {
        $parts = array_flip(array_map(static fn (array $c): string => $c['unit']->id, self::turn($candidate)));
        $out = [];
        foreach ($candidate['document']->links as $link) {
            $other = match (true) {
                $link->type !== $type => null,
                isset($parts[$link->from]) => $link->to,
                isset($parts[$link->to]) => $link->from,
                default => null,
            };
            $unit = $other === null ? null : $candidate['document']->unit($other);
            if ($unit !== null) {
                $out[] = ['document' => $candidate['document'], 'unit' => $unit];
            }
        }

        return $out;
    }

    /**
     * Of the outcome messages in the kept messages' documents, the one closest to the question,
     * with the messages it settles.
     *
     * @param  list<array{document: Document, unit: Unit}>  $kept
     * @return list<array{document: Document, unit: Unit}>
     */
    private static function nearestOutcome(string $query, array $kept): array
    {
        $top = self::closest($query, self::outcomes($kept))[0] ?? null;
        if ($top === null) {
            return [];
        }
        $settled = [];
        foreach ($top['document']->links as $link) {
            $unit = $link->type === 'decides' && $link->from === $top['unit']->id ? $top['document']->unit($link->to) : null;
            if ($unit !== null) {
                $settled[] = ['document' => $top['document'], 'unit' => $unit];
            }
        }

        return [$top, ...$settled];
    }

    /**
     * Every message part of the kept messages' documents by this speaker, except those kept.
     *
     * @param  list<array{document: Document, unit: Unit}>  $kept
     * @return list<array{document: Document, unit: Unit}>
     */
    private static function spokenBy(array $kept, string $speaker): array
    {
        $have = array_flip(array_map(Retriever::key(...), $kept));
        $out = [];
        foreach (self::documents($kept) as $document) {
            foreach ($document->units as $unit) {
                $entry = ['document' => $document, 'unit' => $unit];
                $name = $unit->meta['speaker'] ?? null;
                if (is_string($name) && (Text::slug($name, 30) ?: 'speaker') === $speaker && ! isset($have[Retriever::key($entry)])) {
                    $out[] = $entry;
                }
            }
        }

        return $out;
    }

    /**
     * The parts of the turn a message part belongs to, in order.
     *
     * @param  array{document: Document, unit: Unit}  $candidate
     * @return list<array{document: Document, unit: Unit}>
     */
    private static function turn(array $candidate): array
    {
        $turn = $candidate['unit']->meta['turn'] ?? null;
        if ($turn === null) {
            return [];
        }
        $out = [];
        foreach ($candidate['document']->units as $unit) {
            if ($unit->kind === $candidate['unit']->kind && ($unit->meta['turn'] ?? null) === $turn) {
                $out[] = ['document' => $candidate['document'], 'unit' => $unit];
            }
        }

        return $out;
    }

    /**
     * @param  array{document: Document, unit: Unit}  $candidate
     */
    private function turnTokens(array $candidate): int
    {
        return array_sum(array_map(fn (array $c): int => Text::tokens(Conversation::body($c['unit']->text), $this->charsPerToken), self::turn($candidate)));
    }

    /**
     * Of the parts of the turn this message part starts, the one closest to the question.
     *
     * @param  array{document: Document, unit: Unit}  $candidate
     * @return array{document: Document, unit: Unit}
     */
    private static function closestPart(string $query, array $candidate): array
    {
        return self::closest($query, self::turn($candidate))[0] ?? $candidate;
    }

    /**
     * Candidates by term overlap with the question, closest first; those sharing no term last,
     * ties in the order given.
     *
     * @param  list<array{document: Document, unit: Unit}>  $candidates
     * @return list<array{document: Document, unit: Unit}>
     */
    private static function closest(string $query, array $candidates): array
    {
        $question = Conversation::termCounts($query);
        $scores = [];
        foreach ($candidates as $i => $candidate) {
            $scores[$i] = Conversation::cosine($question, Conversation::termCounts($candidate['unit']->text));
        }
        arsort($scores);

        return array_map(static fn (int $i): array => $candidates[$i], array_keys($scores));
    }

    /**
     * Messages that add to the answer.
     *
     * @param  list<array{document: Document, unit: Unit}>  $candidates
     * @return list<array{document: Document, unit: Unit}>
     */
    private function check(string $query, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }
        $labels = [];
        foreach ($candidates as $i => $candidate) {
            $labels["message-{$i}"] = $candidate;
        }
        $response = $this->jev->send(static function (SystemOne $request) use ($labels, $query): SystemOne {
            $request->state([Retriever::QUESTION => $query, 'messages' => array_map(static fn (array $c): string => Text::head($c['unit']->text, self::CHECK_CHARS), $labels)]);
            foreach (array_keys($labels) as $label) {
                $request->ask("c_{$label}", Noul::ask("Does `{$label}` contain part of what the question asks for?")
                    ->yes("`{$label}` states something a full answer would include")
                    ->no("`{$label}` adds nothing the answer needs"));
            }

            return $request;
        });
        if (! $response instanceof SystemOneResponse) {
            return [];
        }

        return array_values(array_filter($labels, static fn (array $c, string $label): bool => (JevCaller::noul($response, "c_{$label}") ?? 0.0) >= 0.5, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * @param  list<array{document: Document, unit: Unit}>  $candidates
     * @return list<Document>
     */
    private static function documents(array $candidates): array
    {
        $out = [];
        foreach ($candidates as $candidate) {
            $out[$candidate['document']->id] ??= $candidate['document'];
        }

        return array_values($out);
    }
}
