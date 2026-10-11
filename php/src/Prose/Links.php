<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Prose;

use Phox\ContextAtlas\Document\Link;
use Phox\ContextAtlas\Document\Unit;
use Phox\ContextAtlas\Index\Lexical;
use Phox\ContextAtlas\Jev\JevCaller;
use Phox\ContextAtlas\Text;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Requests\SystemOne;
use Phox\TypeSafe\Responses\SystemOneResponse;

/**
 * Builds the typed links between passages that retrieval follows.
 *
 * - `needs`: a passage that depends on the one before it to be understood, such as a worked
 *   example after its rule. Asked of every consecutive pair.
 * - `defined_by`: a passage that uses a term another passage defines. Jev marks definition
 *   passages, then is asked only about passages sharing a distinctive term with one: asking
 *   about every pair links nearly every passage to every definition.
 * - `refers_to`: an explicit cross-reference such as "Section 3.1", "regulation 34(9)" or a
 *   wiki-style "#Heading", parsed without Jev.
 *
 * A failed request leaves its links out; the document is still usable without them.
 */
final readonly class Links
{
    private const int PAIR_BATCH = 30;

    private const float DISTINCTIVE = 0.2;

    private const string REFERENCE = '/\b(?i:sections?|clauses?|articles?|regulations?|rules?|paragraphs?|schedules?|parts?)\s+((?:(?:\d+[A-Z]?|[A-Z]\b)(?:\.\d+)*(?:\([a-z0-9]+\))*(?:\s*(?:,|and|or|to)\s*)?)+)/';

    public function __construct(
        private ?JevCaller $jev,
    ) {}

    /**
     * @param  list<Unit>  $units  in document order
     * @return list<Link>
     */
    public function needs(array $units): array
    {
        $pairs = self::consecutive($units);
        $links = [];
        foreach ($this->ask($pairs, static fn (string $key): Noul => Noul::ask("In pair {$key}, does the second passage depend on the first to be understood?")
            ->yes('it refers back to the first, continues its list or example, or answers it, so it makes little sense read alone')
            ->no('it can be understood without the first'), static fn (Unit $a, Unit $b): array => ['end_of_first' => Text::end($a->text, 300), 'start_of_second' => Text::head(self::body($b->text), 300)]) as $key) {
            $links[] = new Link('needs', $pairs[$key][1]->id, $pairs[$key][0]->id);
        }

        return $links;
    }

    /**
     * @param  list<Unit>  $units
     * @return array<string, array{Unit, Unit}>
     */
    private static function consecutive(array $units): array
    {
        $pairs = [];
        for ($i = 1, $n = count($units); $i < $n; $i++) {
            $pairs['n' . ($i - 1)] = [$units[$i - 1], $units[$i]];
        }

        return $pairs;
    }

    /**
     * @param  list<Unit>  $units
     * @return list<Link>
     */
    public function definitions(array $units): array
    {
        $definitions = $this->definitionUnits($units);

        if ($definitions === []) {
            return [];
        }

        $pairs = self::sharingTerms($units, $definitions);
        $links = [];
        foreach ($this->ask($pairs, static fn (string $key): Noul => Noul::ask("In pair {$key}, does the passage use a term whose meaning the definitions state?")
            ->yes('a term in the passage is one the definitions give the meaning of')
            ->no('none of the defined terms appear in the passage'), static fn (Unit $a, Unit $b): array => ['passage' => Text::head($a->text, 500), 'definitions' => Text::head($b->text, 700)], 25) as $key) {
            $links[] = new Link('defined_by', $pairs[$key][0]->id, $pairs[$key][1]->id);
        }

        return $links;
    }

    /**
     * Passage and definition pairs that share a distinctive term: one found in at most a
     * DISTINCTIVE share of the document's passages (and at least two).
     *
     * @param  list<Unit>  $units
     * @param  list<Unit>  $definitions
     * @return array<string, array{Unit, Unit}>
     */
    private static function sharingTerms(array $units, array $definitions): array
    {
        $frequency = self::documentFrequency($units);
        $limit = max(2, (int) floor(self::DISTINCTIVE * count($units)));
        $pairs = [];

        foreach ($units as $unit) {
            $terms = array_flip(self::terms($unit->text));
            foreach ($definitions as $definition) {
                if ($definition->id === $unit->id) {
                    continue;
                }
                foreach (self::terms($definition->text) as $term) {
                    if (isset($terms[$term]) && ($frequency[$term] ?? 0) <= $limit) {
                        $pairs['u' . count($pairs)] = [$unit, $definition];
                        break;
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * Explicit references to numbered sections, resolved against the numbers that open each
     * passage's headings or text. A reference to "34(9)" falls back to "34" when no passage is
     * numbered "34(9)".
     *
     * @param  list<Unit>  $units
     * @return list<Link>
     */
    public static function references(array $units): array
    {
        $numbered = [];
        foreach ($units as $unit) {
            foreach (self::numbers($unit) as $number) {
                $numbered[$number] ??= $unit->id;
            }
        }

        $anchors = self::anchors($units);
        $links = [];
        foreach ($units as $unit) {
            preg_match_all(self::REFERENCE, self::body($unit->text), $matches);
            $targets = [];
            foreach ($matches[1] as $list) {
                preg_match_all('/(?:\d+[A-Z]?|\b[A-Z]\b)(?:\.\d+)*(?:\([a-z0-9]+\))*/', $list, $numbers);
                foreach ($numbers[0] as $number) {
                    $target = $numbered[$number] ?? $numbered[(string) preg_replace('/\(.*$/', '', $number)] ?? null;
                    if ($target !== null && $target !== $unit->id) {
                        $targets[$target] = true;
                    }
                }
            }
            preg_match_all('/#(\p{Lu}[^#.,;:()\n]{2,60})/u', self::body($unit->text), $hashes);
            foreach ($hashes[1] as $text) {
                foreach (self::anchorFor(mb_strtolower(Text::trim($text)), $anchors) as $target) {
                    if ($target !== $unit->id) {
                        $targets[$target] = true;
                    }
                }
            }
            foreach (array_keys($targets) as $target) {
                $links[] = new Link('refers_to', $unit->id, (string) $target);
            }
        }

        return $links;
    }

    /**
     * Each heading, lowercased, mapped to the first two passages under it, for wiki-style
     * "#Heading" references.
     *
     * @param  list<Unit>  $units
     * @return array<string, list<string>>
     */
    private static function anchors(array $units): array
    {
        $anchors = [];
        foreach ($units as $unit) {
            $heading = mb_strtolower(Text::trim($unit->path[count($unit->path) - 1] ?? ''));
            if ($heading !== '' && count($anchors[$heading] ?? []) < 2) {
                $anchors[$heading][] = $unit->id;
            }
        }

        return $anchors;
    }

    /**
     * The targets of the longest heading the text after `#` starts with, followed by the end or
     * a space, so "#Prepare first" finds the heading "Prepare".
     *
     * @param  array<string, list<string>>  $anchors
     * @return list<string>
     */
    private static function anchorFor(string $text, array $anchors): array
    {
        $best = null;
        foreach (array_keys($anchors) as $heading) {
            $heading = (string) $heading;
            $fits = str_starts_with($text, $heading) && (mb_strlen($text) === mb_strlen($heading) || mb_substr($text, mb_strlen($heading), 1) === ' ');
            if ($fits && ($best === null || mb_strlen($heading) > mb_strlen($best))) {
                $best = $heading;
            }
        }

        return $best === null ? [] : $anchors[$best];
    }

    /**
     * The section numbers a passage carries: from each heading in its path ("3.1 Distribution",
     * "Section 6 – Term", "Regulation 12") and from the start of its own text ("10.—(1)").
     *
     * @return list<string>
     */
    private static function numbers(Unit $unit): array
    {
        $numbers = [];

        foreach ([...$unit->path, Text::head(self::body($unit->text), 40)] as $line) {
            if (preg_match('/^(?:(?i:section|clause|article|regulation|rule|paragraph|schedule|part)\s+)?(\d+[A-Z]?(?:\.\d+)*|[A-Z](?:\.\d+)*(?=\.\s))(?=[\s.:)—–-]|$)/u', Text::trim($line), $match) === 1) {
                $numbers[] = $match[1];
            }
        }

        return array_values(array_unique($numbers));
    }

    /**
     * @param  list<Unit>  $units
     * @return list<Unit>
     */
    private function definitionUnits(array $units): array
    {
        $pairs = [];
        foreach ($units as $i => $unit) {
            $pairs['d' . $i] = [$unit, $unit];
        }

        return array_map(static fn (string $key): Unit => $pairs[$key][0], $this->ask($pairs, static fn (string $key): Noul => Noul::ask("Does `{$key}` define what a term means, for use elsewhere in the document?")
            ->yes("`{$key}` states the meaning of one or more terms")
            ->no("`{$key}` uses terms without defining them"), static fn (Unit $a): string => Text::head($a->text, 500), state: 'passages'));
    }

    /**
     * Asks one noul per pair, in batches, and returns the keys answered yes (0.5 or more).
     *
     * @param  array<string, array{Unit, Unit}>  $pairs
     * @param  callable(string): Noul  $question
     * @param  callable(Unit, Unit): (array<string, string>|string)  $show
     * @return list<string>
     */
    private function ask(array $pairs, callable $question, callable $show, int $batch = self::PAIR_BATCH, string $state = 'pairs'): array
    {
        if ($this->jev === null || $pairs === []) {
            return [];
        }

        $yes = [];
        foreach (array_chunk($pairs, max(1, $batch), true) as $chunk) {
            $response = $this->jev->send(static function (SystemOne $request) use ($chunk, $question, $show, $state): SystemOne {
                $request->state([$state => array_map(static fn (array $pair): array|string => $show($pair[0], $pair[1]), $chunk)]);
                foreach (array_keys($chunk) as $key) {
                    $request->ask((string) $key, $question((string) $key));
                }

                return $request;
            });
            if (! $response instanceof SystemOneResponse) {
                continue;
            }
            foreach (array_keys($chunk) as $key) {
                if ((JevCaller::noul($response, (string) $key) ?? 0.0) >= 0.5) {
                    $yes[] = (string) $key;
                }
            }
        }

        return $yes;
    }

    /**
     * @param  list<Unit>  $units
     * @return array<string, int>
     */
    private static function documentFrequency(array $units): array
    {
        $frequency = [];
        foreach ($units as $unit) {
            foreach (array_unique(self::terms($unit->text)) as $term) {
                $frequency[$term] = ($frequency[$term] ?? 0) + 1;
            }
        }

        return $frequency;
    }

    /**
     * Terms of four letters or more; shorter ones are rarely the defined term.
     *
     * @return list<string>
     */
    private static function terms(string $text): array
    {
        return array_values(array_unique(array_filter(Lexical::terms(self::body($text)), static fn (string $t): bool => mb_strlen($t) >= 4)));
    }

    /**
     * A passage's text without its "[heading > path]" prefix.
     */
    public static function body(string $text): string
    {
        return (string) preg_replace('/^\[[^\]\n]*\]\s*/u', '', $text);
    }
}
