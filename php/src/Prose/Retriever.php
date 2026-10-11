<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Prose;

use Phox\ContextAtlas\Config;
use Phox\ContextAtlas\Document\Document;
use Phox\ContextAtlas\Document\Unit;
use Phox\ContextAtlas\Jev\JevCaller;
use Phox\ContextAtlas\Text;
use Phox\TypeSafe\Questions\Choice;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Requests\SystemOne;
use Phox\TypeSafe\Responses\SystemOneResponse;

/**
 * Gathers passages for a question: one request ranks the candidates and, for profiles with
 * scopes, asks how much the question needs; then the links of the kept passages are followed,
 * each type with its own question.
 *
 * - `needs` links are always followed: the passage a kept one depends on comes too.
 * - `refers_to` targets are kept when the answer needs what they say.
 * - `defined_by` targets get a question of their own plus a pick of the one the answer turns
 *   on: asked whether a definition is "needed", Jev scores the right one about 0.3.
 */
final class Retriever
{
    public const string QUESTION = 'the question being asked';

    private const float KEEP = 0.4;

    private const float KEEP_SEVERAL = 0.5;

    private const int MIN_STEP_TOKENS = 25;

    private const int PASSAGE_CHARS = 2400;

    private int $wholeChunk;

    private int $passageChars = self::PASSAGE_CHARS;

    private int $charsPerToken;

    public function __construct(
        private readonly JevCaller $jev,
        Config $config = new Config(),
    ) {
        $this->wholeChunk = max(self::PASSAGE_CHARS, 4 * $config->maxTokens);
        $this->charsPerToken = $config->charsPerToken;
    }

    /**
     * When the best candidates come from sections of different profiles (a policy's rules and
     * its rate table, minutes' discussion and decisions), each profile gathers from its own
     * candidates and the results are merged; otherwise one profile gathers from them all.
     *
     * @param  list<array{document: Document, unit: Unit}>  $candidates  best first
     * @return array{units: list<array{document: Document, unit: Unit}>, trace: array<string, mixed>}|null null when Jev did not answer
     */
    public function retrieve(string $query, array $candidates): ?array
    {
        $candidates = self::diverse($candidates);
        $profiles = self::profiles($candidates);
        if (count($profiles) === 1) {
            return $this->retrieveAs($profiles[0], $query, $candidates);
        }

        $units = [];
        $traces = [];
        foreach ($profiles as $profile) {
            $result = $this->retrieveAs($profile, $query, array_values(array_filter($candidates, static fn (array $c): bool => self::profileOf($c) === $profile)));
            if ($result !== null) {
                array_push($units, ...$result['units']);
                $traces[$profile] = $result['trace'];
            }
        }

        return $traces === [] ? null : ['units' => self::inDocumentOrder(self::unique($units)), 'trace' => ['profiles' => $traces]];
    }

    /**
     * @param  list<array{document: Document, unit: Unit}>  $candidates  best first
     * @return array{units: list<array{document: Document, unit: Unit}>, trace: array<string, mixed>}|null
     */
    private function retrieveAs(string $profile, string $query, array $candidates): ?array
    {
        if ($profile === Profiles::REFERENCE) {
            return $this->reference($query, $candidates);
        }
        if ($profile === Profiles::CONVERSATIONAL) {
            return (new ConversationRetriever($this->jev, $this->charsPerToken))->retrieve($query, $candidates);
        }
        $size = Profiles::POOL[$profile];
        $this->passageChars = $profile === Profiles::NARRATIVE ? $this->wholeChunk : self::PASSAGE_CHARS;
        $ranked = $this->rank($query, array_slice($candidates, 0, $size), Profiles::SCOPES[$profile], $profile === Profiles::PROCEDURAL);

        if ($ranked === null) {
            return null;
        }
        if ($ranked['kept'] === []) {
            return ['units' => [], 'trace' => ['profile' => $profile, 'scores' => $ranked['scores']]];
        }

        $gathered = match ($profile) {
            Profiles::NARRATIVE => self::narrative($ranked),
            Profiles::PROCEDURAL => $this->procedural($query, $ranked),
            default => self::sections($ranked),
        };

        if ($ranked['scope'] === 'several places' && in_array($profile, [Profiles::NORMATIVE, Profiles::GUIDANCE], true) && $this->complete($query, $gathered) === false) {
            array_push($gathered, ...$this->more($query, array_slice($candidates, $size, $size)));
        }

        $gathered = $this->expand($query, self::unique($gathered));
        if ($profile === Profiles::MARKUP) {
            $gathered = $this->references($query, $gathered);
        }

        return ['units' => self::inDocumentOrder($gathered), 'trace' => ['profile' => $profile, 'scope' => $ranked['scope'], 'scores' => $ranked['scores']]];
    }

    /**
     * Reference lookups. Entries the question names join the pool, with a second question
     * each: asked only whether it answers, the entry a comparison names scores low ("does
     * Path.walk answer?" 0.08), while "is it one the question names?" finds it. A question
     * about every entry with a property is answered by a filter over the entries' fields.
     *
     * @param  list<array{document: Document, unit: Unit}>  $candidates
     * @return array{units: list<array{document: Document, unit: Unit}>, trace: array<string, mixed>}|null
     */
    private function reference(string $query, array $candidates): ?array
    {
        $pool = array_slice($candidates, 0, Profiles::POOL[Profiles::REFERENCE]);
        $named = self::namedEntries($query, $candidates);
        $namedKeys = array_flip(array_map(self::key(...), $named));
        $poolKeys = array_flip(array_map(self::key(...), $pool));
        $labels = [];
        $isNamed = [];
        foreach ([...$pool, ...array_values(array_filter($named, static fn (array $c): bool => ! isset($poolKeys[self::key($c)])))] as $i => $candidate) {
            $label = self::entryLabel($candidate, $i);
            $labels[$label] = $candidate;
            $isNamed[$label] = isset($namedKeys[self::key($candidate)]);
        }

        $response = $this->jev->send(fn (SystemOne $request): SystemOne => $this->referenceRequest($request, $query, $labels, $isNamed));
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
        $shapes = Profiles::SCOPES[Profiles::REFERENCE];
        $shape = isset($shapes[(string) JevCaller::choice($response, 'scope')]) ? (string) JevCaller::choice($response, 'scope') : 'one entry';
        $keptUnits = array_map(static fn (string|int $label): array => $labels[(string) $label], array_keys($kept));
        $trace = ['profile' => Profiles::REFERENCE, 'scope' => $shape, 'scores' => $scores];

        $gathered = match ($shape) {
            'named entries' => [...array_map(static fn (string $label): array => $labels[$label], array_keys(array_filter($isNamed, static fn (bool $n, string $label): bool => $n && (JevCaller::noul($response, "n_{$label}") ?? 0.0) >= 0.5, ARRAY_FILTER_USE_BOTH))), ...array_slice($keptUnits, 0, 3)],
            'all entries with a property' => $this->entriesWithProperty($query, $candidates, $trace),
            default => array_slice($keptUnits, 0, 3),
        };

        if ($gathered === [] && $shape === 'all entries with a property') {
            $gathered = array_slice($keptUnits, 0, 3);
        }

        return ['units' => self::inDocumentOrder(self::unique($gathered)), 'trace' => $trace];
    }

    /**
     * @param  array<string, array{document: Document, unit: Unit}>  $labels
     * @param  array<string, bool>  $isNamed
     */
    private function referenceRequest(SystemOne $request, string $query, array $labels, array $isNamed): SystemOne
    {
        $request->state([self::QUESTION => $query, 'the entries the search found' => array_map(static fn (array $c): string => Text::head($c['unit']->text, self::PASSAGE_CHARS), $labels)]);
        foreach (array_keys($labels) as $label) {
            $request->ask("c_{$label}", self::answers($label));
            if ($isNamed[$label]) {
                $request->ask("n_{$label}", Noul::ask("Is `{$label}` one of the entries the question names or asks about?")
                    ->yes("the question refers to the entry `{$label}` describes, by its name or unmistakably")
                    ->no('the question is about a different entry that merely has a similar name'));
            }
        }
        $request->ask('best', Choice::between(array_keys($labels))->instructions('Which single one answers the question most directly?'));
        $request->ask('scope', Choice::between(Profiles::SCOPES[Profiles::REFERENCE])->instructions('What does a full answer to the question need?'));

        return $request;
    }

    /**
     * Entries of the candidates' documents whose name the question contains as a whole word,
     * longest names first, at most 6. They get the "named" question whether or not the search
     * found them; those it did not find join the pool.
     *
     * @param  list<array{document: Document, unit: Unit}>  $candidates
     * @return list<array{document: Document, unit: Unit}>
     */
    private static function namedEntries(string $query, array $candidates): array
    {
        $found = [];
        foreach (self::documents($candidates) as $document) {
            foreach ($document->units as $unit) {
                $entry = ['document' => $document, 'unit' => $unit];
                if ($unit->kind !== 'entry') {
                    continue;
                }
                foreach (self::aliases((string) ($unit->meta['name'] ?? '')) as $alias) {
                    if (preg_match('/(?<![\w.])' . preg_quote($alias, '/') . '(?![\w(])/iu', $query) === 1) {
                        $found[self::key($entry)] = [mb_strlen($alias), $entry];
                        break;
                    }
                }
            }
        }
        uasort($found, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return array_slice(array_values(array_map(static fn (array $f): array => $f[1], $found)), 0, 6);
    }

    /**
     * The names an entry is asked about by: its name, and for a call signature the name before
     * the bracket. Names shorter than three characters are left out.
     *
     * @return list<string>
     */
    private static function aliases(string $name): array
    {
        $aliases = [Text::trim($name)];
        if (preg_match('/^([\w.]+)\(/u', $name, $match) === 1) {
            $aliases[] = $match[1];
        }

        return array_values(array_filter(array_unique($aliases), static fn (string $a): bool => mb_strlen($a) >= 3));
    }

    /**
     * Every entry with a field value the question contains, offered as filters with their
     * counts; Jev picks the one that selects what the question asks for. Without a fitting
     * filter, the candidates are asked one by one whether they are among the entries meant.
     *
     * @param  list<array{document: Document, unit: Unit}>  $candidates
     * @param  array<string, mixed>  $trace
     * @return list<array{document: Document, unit: Unit}>
     */
    private function entriesWithProperty(string $query, array $candidates, array &$trace): array
    {
        $question = mb_strtolower(str_replace('∶', ':', $query));
        $options = [];
        foreach (self::documents($candidates) as $document) {
            foreach ($document->units as $unit) {
                foreach ((array) ($unit->meta['fields'] ?? []) as $pair) {
                    $field = (string) ((array) $pair)['field'];
                    foreach ((array) ((array) $pair)['values'] as $value) {
                        $value = Text::trim((string) $value);
                        $needle = mb_strtolower(str_replace('∶', ':', $value));
                        if (mb_strlen($needle) < 2 || preg_match('/(?<![\p{L}\p{N}_])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}_])/u', $question) !== 1) {
                            continue;
                        }
                        $group = $unit->meta['row'] ?? false ? (string) ($unit->meta['group'] ?? '') : '';
                        $key = $field . "\0" . $group . "\0" . $value;
                        $options[$key] ??= ['field' => $field, 'group' => $group, 'value' => $value, 'entries' => []];
                        $options[$key]['entries'][] = ['document' => $document, 'unit' => $unit];
                    }
                }
            }
        }

        if ($options !== []) {
            $chosen = $this->pickFilter($query, array_values($options));
            if ($chosen !== null) {
                $trace['filter'] = ['field' => $chosen['field'], 'group' => $chosen['group'], 'value' => $chosen['value']];

                return array_slice(self::unique($chosen['entries']), 0, 30);
            }
        }

        return $this->entriesMeant($query, array_slice($candidates, 0, 24));
    }

    /**
     * @param  list<array{field: string, group: string, value: string, entries: list<array{document: Document, unit: Unit}>}>  $options
     * @return array{field: string, group: string, value: string, entries: list<array{document: Document, unit: Unit}>}|null
     */
    private function pickFilter(string $query, array $options): ?array
    {
        $labels = [];
        foreach ($options as $i => $option) {
            $count = count(self::unique($option['entries']));
            $labels["f{$i}"] = $option['group'] === ''
                ? "entries whose `{$option['field']}` is {$option['value']} ({$count} entries)"
                : "rows of `{$option['group']}` whose `{$option['field']}` is {$option['value']} ({$count} entries)";
        }

        $response = $this->jev->send(static fn (SystemOne $request): SystemOne => $request
            ->state([self::QUESTION => $query])
            ->ask('filter', Choice::between($labels + ['none' => 'none of these selects the entries the question asks for'])
                ->instructions('Which filter selects exactly the entries the question asks for? Pick `none` if none of them does.')));

        $pick = $response instanceof SystemOneResponse ? JevCaller::choice($response, 'filter') : null;

        return $pick !== null && isset($labels[$pick]) ? $options[(int) substr($pick, 1)] : null;
    }

    /**
     * @param  list<array{document: Document, unit: Unit}>  $candidates
     * @return list<array{document: Document, unit: Unit}>
     */
    private function entriesMeant(string $query, array $candidates): array
    {
        $out = [];
        foreach (array_chunk($candidates, 12) as $batch) {
            $labels = [];
            foreach ($batch as $i => $candidate) {
                $labels[self::entryLabel($candidate, $i)] = $candidate;
            }
            $response = $this->jev->send(static function (SystemOne $request) use ($labels, $query): SystemOne {
                $request->state([self::QUESTION => $query, 'the entries' => array_map(static fn (array $c): string => Text::head($c['unit']->text, self::PASSAGE_CHARS), $labels)]);
                foreach (array_keys($labels) as $label) {
                    $request->ask("c_{$label}", Noul::ask("Is `{$label}` one of the entries the question asks for?")
                        ->yes("`{$label}` has what the question asks about")
                        ->no("`{$label}` lacks it, or is not the kind of entry the question asks about"));
                }

                return $request;
            });
            if (! $response instanceof SystemOneResponse) {
                continue;
            }
            foreach ($labels as $label => $candidate) {
                if ((JevCaller::noul($response, "c_{$label}") ?? 0.0) >= 0.5) {
                    $out[] = $candidate;
                }
            }
        }

        return $out;
    }

    /**
     * A readable label: the entry's name (or the passage's heading path) as a slug, with its
     * position on the end.
     *
     * @param  array{document: Document, unit: Unit}  $candidate
     */
    private static function entryLabel(array $candidate, int $i): string
    {
        $name = (string) ($candidate['unit']->meta['name'] ?? implode(' ', $candidate['unit']->path));
        $slug = Text::slug($name, 40);

        return ($slug === '' ? 'passage' : $slug) . '-' . $i;
    }

    /**
     * @param  list<array{document: Document, unit: Unit}>  $candidates
     * @return list<Document>
     */
    private static function documents(array $candidates): array
    {
        $documents = [];
        foreach ($candidates as $candidate) {
            $documents[$candidate['document']->id] ??= $candidate['document'];
        }

        return array_values($documents);
    }

    /**
     * @param  array{kept: list<array{document: Document, unit: Unit}>, scope: string}  $ranked
     * @return list<array{document: Document, unit: Unit}>
     */
    private static function sections(array $ranked): array
    {
        return match ($ranked['scope']) {
            'a whole section' => self::section($ranked['kept'][0]),
            'several places' => array_slice($ranked['kept'], 0, 6),
            default => array_slice($ranked['kept'], 0, 3),
        };
    }

    /**
     * Narrative answers spread across distant paragraphs: for several places every passage
     * scoring KEEP_SEVERAL or more is kept, up to 8, falling back to the best 3 when fewer than
     * 2 do.
     *
     * @param  array{kept: list<array{document: Document, unit: Unit}>, kept_labels: list<string>, scope: string, scores: array<string, float>, labels: array<string, array{document: Document, unit: Unit}>}  $ranked
     * @return list<array{document: Document, unit: Unit}>
     */
    private static function narrative(array $ranked): array
    {
        if ($ranked['scope'] !== 'several places') {
            return array_slice($ranked['kept'], 0, 3);
        }

        $strong = [];
        foreach ($ranked['kept_labels'] as $label) {
            if ($ranked['scores'][$label] >= self::KEEP_SEVERAL) {
                $strong[] = $ranked['labels'][$label];
            }
        }

        return count($strong) >= 2 ? array_slice($strong, 0, 8) : array_slice($ranked['kept'], 0, 3);
    }

    /**
     * Procedures: the whole section of the best passage for all the steps; for the step after
     * or before a named one, the step next to the passage Jev names as that step, plus the
     * neighbours of the best passages that Jev confirms.
     *
     * @param  array{kept: list<array{document: Document, unit: Unit}>, scope: string, anchor: array{document: Document, unit: Unit}|null, direction: string}  $ranked
     * @return list<array{document: Document, unit: Unit}>
     */
    private function procedural(string $query, array $ranked): array
    {
        $gathered = array_slice($ranked['kept'], 0, $ranked['scope'] === 'several places' ? 6 : 3);

        if ($ranked['scope'] === 'all the steps') {
            array_push($gathered, ...self::section($ranked['kept'][0]));
        }

        if ($ranked['scope'] === 'the next or previous step') {
            $step = $ranked['direction'] === 'before' ? -1 : 1;
            if ($ranked['anchor'] !== null && ($next = self::neighbour($ranked['anchor'], $step)) !== null) {
                $gathered[] = $next;
            }
            $around = [];
            foreach (array_slice($ranked['kept'], 0, 2) as $candidate) {
                foreach ([-2, -1, 1, 2] as $offset) {
                    $neighbour = self::neighbour($candidate, $offset);
                    if ($neighbour !== null) {
                        $around[] = $neighbour;
                    }
                }
            }
            array_push($gathered, ...$this->confirmSteps($query, self::unique($around)));
        }

        return $gathered;
    }

    /**
     * The content passage `$offset` steps away in its document, skipping heading-only passages.
     *
     * @param  array{document: Document, unit: Unit}  $candidate
     * @return array{document: Document, unit: Unit}|null
     */
    private static function neighbour(array $candidate, int $offset): ?array
    {
        $units = array_values(array_filter($candidate['document']->units, static fn (Unit $u): bool => $u->kind === 'passage' && ($u->id === $candidate['unit']->id || Text::tokens(Links::body($u->text), 4) >= self::MIN_STEP_TOKENS)));
        foreach ($units as $i => $unit) {
            if ($unit->id === $candidate['unit']->id) {
                $target = $units[$i + $offset] ?? null;

                return $target === null ? null : ['document' => $candidate['document'], 'unit' => $target];
            }
        }

        return null;
    }

    /**
     * @param  list<array{document: Document, unit: Unit}>  $candidates
     * @return list<array{document: Document, unit: Unit}>
     */
    private function confirmSteps(string $query, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $labels = [];
        foreach ($candidates as $i => $candidate) {
            $labels["step-{$i}"] = $candidate;
        }

        $response = $this->jev->send(fn (SystemOne $request): SystemOne => $this->stepRequest($request, $query, $labels));

        if (! $response instanceof SystemOneResponse) {
            return [];
        }

        return array_values(array_filter($labels, static fn (array $c, string $label): bool => (JevCaller::noul($response, "c_{$label}") ?? 0.0) >= 0.5, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * @param  array<string, array{document: Document, unit: Unit}>  $labels
     */
    private function stepRequest(SystemOne $request, string $query, array $labels): SystemOne
    {
        $request->state([self::QUESTION => $query, 'the passages around the steps found' => array_map(fn (array $c): string => Text::head($c['unit']->text, $this->passageChars), $labels)]);
        foreach (array_keys($labels) as $label) {
            $request->ask("c_{$label}", Noul::ask("Is `{$label}` the step the question asks for, the one done right after or right before the step it names?")
                ->yes("`{$label}` describes the step the question asks for")
                ->no("`{$label}` describes a different step, or no step"));
        }

        return $request;
    }

    /**
     * Candidates reordered so that no document has more than three before every other document's
     * candidates have had their turn: near-copies of one document do not fill the ranking.
     *
     * @param  list<array{document: Document, unit: Unit}>  $candidates
     * @return list<array{document: Document, unit: Unit}>
     */
    private static function diverse(array $candidates): array
    {
        $first = [];
        $later = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $id = $candidate['document']->id;
            $seen[$id] = ($seen[$id] ?? 0) + 1;
            if ($seen[$id] <= 3) {
                $first[] = $candidate;
            } else {
                $later[] = $candidate;
            }
        }

        return [...$first, ...$later];
    }

    /**
     * The profiles of the first five candidates, most common first (ties: first seen).
     *
     * @param  list<array{document: Document, unit: Unit}>  $candidates
     * @return non-empty-list<string>
     */
    private static function profiles(array $candidates): array
    {
        $votes = [];
        foreach (array_slice($candidates, 0, 5) as $candidate) {
            $votes[self::profileOf($candidate)] = ($votes[self::profileOf($candidate)] ?? 0) + 1;
        }
        arsort($votes);
        $profiles = array_map('strval', array_keys($votes));

        return $profiles === [] ? [Profiles::GUIDANCE] : $profiles;
    }

    /**
     * @param  array{document: Document, unit: Unit}  $candidate
     */
    private static function profileOf(array $candidate): string
    {
        $profile = (string) ($candidate['unit']->meta['profile'] ?? Profiles::GUIDANCE);

        return Profiles::exists($profile) ? $profile : Profiles::GUIDANCE;
    }

    /**
     * @param  list<array{document: Document, unit: Unit}>  $pool
     * @param  array<string, string>  $scopes
     * @return array{kept: list<array{document: Document, unit: Unit}>, kept_labels: list<string>, scope: string, scores: array<string, float>, labels: array<string, array{document: Document, unit: Unit}>, anchor: array{document: Document, unit: Unit}|null, direction: string}|null
     */
    private function rank(string $query, array $pool, array $scopes, bool $steps = false): ?array
    {
        $labels = [];
        foreach ($pool as $i => $candidate) {
            $labels['p' . $i] = $candidate;
        }
        $chars = $this->passageChars;

        $response = $this->jev->send(static function (SystemOne $request) use ($labels, $query, $scopes, $chars, $steps): SystemOne {
            $request->state([self::QUESTION => $query, 'the passages the search found' => array_map(static fn (array $c): string => Text::head($c['unit']->text, $chars), $labels)]);
            foreach (array_keys($labels) as $label) {
                $request->ask("c_{$label}", self::answers($label));
            }
            $request->ask('best', Choice::between(array_keys($labels))->instructions('Which single one answers the question most directly?'));
            if ($scopes !== []) {
                $request->ask('scope', Choice::between($scopes)->instructions('What does a full answer to the question need?'));
            }
            if ($steps) {
                $request->ask('anchor', Choice::between([...array_keys($labels), 'none'])->instructions('If the question asks what to do after or before a step it names, which passage describes that named step? Pick `none` if the question does not name a step.'));
                $request->ask('direction', Choice::between(['after' => 'the question asks for the step done right after the one it names', 'before' => 'the question asks for the step done right before the one it names'])->instructions('Does the question ask for the step after or the step before the one it names?'));
            }

            return $request;
        });

        if (! $response instanceof SystemOneResponse) {
            return null;
        }

        $scores = [];
        foreach (array_keys($labels) as $label) {
            $scores[$label] = JevCaller::noul($response, "c_{$label}") ?? 0.0;
        }
        $kept = array_filter($scores, static fn (float $score): bool => $score >= self::KEEP);
        arsort($kept);
        $best = JevCaller::choice($response, 'best');
        if ($best !== null && isset($kept[$best])) {
            $kept = [$best => $kept[$best]] + $kept;
        }

        $anchor = JevCaller::choice($response, 'anchor');

        return [
            'kept' => array_map(static fn (string $label): array => $labels[$label], array_map('strval', array_keys($kept))),
            'kept_labels' => array_map('strval', array_keys($kept)),
            'scope' => isset($scopes[(string) JevCaller::choice($response, 'scope')]) ? (string) JevCaller::choice($response, 'scope') : 'one passage',
            'scores' => $scores,
            'labels' => $labels,
            'anchor' => $anchor !== null ? ($labels[$anchor] ?? null) : null,
            'direction' => JevCaller::choice($response, 'direction') === 'before' ? 'before' : 'after',
        ];
    }

    /**
     * Every passage of the document under the same headings as this one.
     *
     * @param  array{document: Document, unit: Unit}  $candidate
     * @return list<array{document: Document, unit: Unit}>
     */
    private static function section(array $candidate): array
    {
        $out = [];
        foreach ($candidate['document']->units as $unit) {
            if ($unit->kind === 'passage' && $unit->path === $candidate['unit']->path) {
                $out[] = ['document' => $candidate['document'], 'unit' => $unit];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{document: Document, unit: Unit}>  $gathered
     */
    private function complete(string $query, array $gathered): ?bool
    {
        $response = $this->jev->send(static fn (SystemOne $request): SystemOne => $request
            ->state([self::QUESTION => $query, 'the passages gathered' => array_map(static fn (array $c): string => Text::head($c['unit']->text, 1200), $gathered)])
            ->ask('complete', Noul::ask('Do the passages gathered contain everything needed to answer the question in full?')
                ->yes('every part of the question is answered by something in the passages')
                ->no('some part of the question is not covered by any of the passages')));

        $score = $response instanceof SystemOneResponse ? JevCaller::noul($response, 'complete') : null;

        return $score === null ? null : $score >= 0.5;
    }

    /**
     * Further candidates that answer part of the question.
     *
     * @param  list<array{document: Document, unit: Unit}>  $candidates
     * @return list<array{document: Document, unit: Unit}>
     */
    private function more(string $query, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        return $this->rank($query, $candidates, [])['kept'] ?? [];
    }

    /**
     * @param  list<array{document: Document, unit: Unit}>  $gathered
     * @return list<array{document: Document, unit: Unit}>
     */
    private function expand(string $query, array $gathered): array
    {
        $needed = [];
        $references = [];
        $definitions = [];
        $have = array_flip(array_map(self::key(...), $gathered));

        foreach ($gathered as $candidate) {
            foreach ($candidate['document']->links as $link) {
                if ($link->from !== $candidate['unit']->id) {
                    continue;
                }
                $target = $candidate['document']->unit($link->to);
                if ($target === null || isset($have[$candidate['document']->id . '#' . $target->id])) {
                    continue;
                }
                $entry = ['document' => $candidate['document'], 'unit' => $target];
                match ($link->type) {
                    'needs' => $needed[self::key($entry)] = $entry,
                    'refers_to' => $references[self::key($entry)] = $entry,
                    'defined_by' => $definitions[self::key($entry)] = $entry,
                    default => null,
                };
            }
        }

        return self::unique([
            ...$gathered,
            ...array_values($needed),
            ...$this->keep($query, $gathered, array_values($references), 'reference'),
            ...$this->keep($query, $gathered, array_values($definitions), 'definition'),
        ]);
    }

    /**
     * Follows `ref` links from the gathered subtrees both ways, two hops at most, keeping what
     * the answer turns on: a schema reached through `$ref` holds the required fields an endpoint
     * only names, and the endpoint that uses a schema is what a question about the schema's
     * operation asks for.
     *
     * @param  list<array{document: Document, unit: Unit}>  $gathered
     * @return list<array{document: Document, unit: Unit}>
     */
    private function references(string $query, array $gathered): array
    {
        $frontier = $gathered;
        for ($hop = 0; $hop < 2 && $frontier !== []; $hop++) {
            $have = array_flip(array_map(self::key(...), $gathered));
            $targets = [];
            foreach ($frontier as $candidate) {
                foreach ($candidate['document']->links as $link) {
                    $other = match (true) {
                        $link->type !== 'ref' => null,
                        $link->from === $candidate['unit']->id => $link->to,
                        $link->to === $candidate['unit']->id => $link->from,
                        default => null,
                    };
                    $unit = $other === null ? null : $candidate['document']->unit($other);
                    $target = $unit === null ? null : ['document' => $candidate['document'], 'unit' => $unit];
                    if ($target !== null && ! isset($have[self::key($target)])) {
                        $targets[self::key($target)] ??= ['target' => $target, 'from' => $candidate['unit'], 'uses' => $other === $link->from];
                    }
                }
            }
            $frontier = $this->referenced($query, $gathered, array_slice(array_values($targets), 0, 25));
            $gathered = [...$gathered, ...$frontier];
        }

        return $gathered;
    }

    /**
     * @param  list<array{document: Document, unit: Unit}>  $gathered
     * @param  list<array{target: array{document: Document, unit: Unit}, from: Unit, uses: bool}>  $targets
     * @return list<array{document: Document, unit: Unit}>
     */
    private function referenced(string $query, array $gathered, array $targets): array
    {
        if ($targets === []) {
            return [];
        }
        $labels = [];
        foreach ($targets as $i => $target) {
            $labels["linked-{$i}"] = $target;
        }

        $response = $this->jev->send(static function (SystemOne $request) use ($labels, $gathered, $query): SystemOne {
            $request->state([
                self::QUESTION => $query,
                'the parts found so far' => array_map(static fn (array $c): string => Text::head($c['unit']->text, 1600), $gathered),
                'linked parts' => array_map(static fn (array $t): string => Text::head($t['target']['unit']->text, 1200), $labels),
            ]);
            foreach ($labels as $label => $target) {
                $from = $target['from']->path === [] ? '(top level)' : implode(' > ', $target['from']->path);
                $request->ask("c_{$label}", $target['uses']
                    ? Noul::ask("`{$label}` points to `{$from}` (it uses it by reference). Is `{$label}` the place the question asks about, such as the endpoint or service that uses that definition?")
                        ->yes("the answer names or needs `{$label}`, the user of the definition")
                        ->no("`{$label}` is another user of the definition that the question is not about")
                    : Noul::ask("`{$from}` takes part of its content from `{$label}` by reference. Does the answer turn on what `{$label}` defines, such as which fields are required, their types, default values or settings?")
                        ->yes("a required field, type, default or setting the answer needs is defined in `{$label}`")
                        ->no("the answer does not need anything defined in `{$label}`"));
            }

            return $request;
        });

        if (! $response instanceof SystemOneResponse) {
            return [];
        }

        return array_values(array_map(static fn (array $t): array => $t['target'], array_filter($labels, static fn (array $t, string $label): bool => (JevCaller::noul($response, "c_{$label}") ?? 0.0) >= self::KEEP, ARRAY_FILTER_USE_BOTH)));
    }

    /**
     * @param  list<array{document: Document, unit: Unit}>  $gathered
     * @param  list<array{document: Document, unit: Unit}>  $candidates
     * @return list<array{document: Document, unit: Unit}>
     */
    private function keep(string $query, array $gathered, array $candidates, string $kind): array
    {
        if ($candidates === []) {
            return [];
        }

        $labels = [];
        foreach ($candidates as $i => $candidate) {
            $labels["{$kind}-{$i}"] = $candidate;
        }

        $response = $this->jev->send(static function (SystemOne $request) use ($labels, $gathered, $query, $kind): SystemOne {
            $request->state([
                self::QUESTION => $query,
                'the passages gathered' => array_map(static fn (array $c): string => Text::head($c['unit']->text, 800), $gathered),
                $kind === 'definition' ? 'definitions' : 'referenced passages' => array_map(static fn (array $c): string => Text::head($c['unit']->text, 800), $labels),
            ]);
            foreach (array_keys($labels) as $label) {
                $request->ask("c_{$label}", $kind === 'definition'
                    ? Noul::ask("Does `{$label}` give the meaning of a term that the answer to the question turns on?")
                        ->yes("whether the answer applies depends on what a term defined in `{$label}` means")
                        ->no("`{$label}` defines terms the answer does not depend on")
                    : Noul::ask("The gathered passages send the reader to `{$label}`. Does the answer to the question need what `{$label}` says?")
                        ->yes("`{$label}` states a rule, condition or detail the answer depends on")
                        ->no("the answer is complete without what `{$label}` says"));
            }
            if ($kind === 'definition') {
                $request->ask('definition', Choice::between([...array_keys($labels), 'none'])->instructions('Which single definition does the answer depend on most? Pick `none` if the answer depends on none of them.'));
            }

            return $request;
        });

        if (! $response instanceof SystemOneResponse) {
            return [];
        }

        $pick = $kind === 'definition' ? JevCaller::choice($response, 'definition') : null;

        return array_values(array_filter($labels, static fn (array $c, string $label): bool => (JevCaller::noul($response, "c_{$label}") ?? 0.0) >= 0.5 || $pick === $label, ARRAY_FILTER_USE_BOTH));
    }

    public static function answers(string $label): Noul
    {
        return Noul::ask("Does `{$label}` answer the question?")
            ->yes("`{$label}` states the answer, or a part of it.")
            ->no("`{$label}` is on the same subject but does not answer the question, or only asserts that it does.");
    }

    /**
     * @param  array{document: Document, unit: Unit}  $candidate
     */
    public static function key(array $candidate): string
    {
        return $candidate['document']->id . '#' . $candidate['unit']->id;
    }

    /**
     * @param  list<array{document: Document, unit: Unit}>  $candidates
     * @return list<array{document: Document, unit: Unit}>
     */
    public static function unique(array $candidates): array
    {
        $out = [];
        foreach ($candidates as $candidate) {
            $out[self::key($candidate)] ??= $candidate;
        }

        return array_values($out);
    }

    /**
     * @param  list<array{document: Document, unit: Unit}>  $candidates
     * @return list<array{document: Document, unit: Unit}>
     */
    public static function inDocumentOrder(array $candidates): array
    {
        $position = [];
        foreach ($candidates as $candidate) {
            foreach ($candidate['document']->units as $i => $unit) {
                if ($unit->id === $candidate['unit']->id) {
                    $position[self::key($candidate)] = $i;
                }
            }
        }

        usort($candidates, static fn (array $a, array $b): int => strcmp($a['document']->id, $b['document']->id) ?: $position[self::key($a)] <=> $position[self::key($b)]);

        return $candidates;
    }
}
