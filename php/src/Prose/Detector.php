<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Prose;

use Phox\ContextAtlas\Chunk;
use Phox\ContextAtlas\Jev\JevCaller;
use Phox\ContextAtlas\Text;
use Phox\TypeSafe\Questions\Choice;
use Phox\TypeSafe\Requests\SystemOne;
use Phox\TypeSafe\Responses\SystemOneResponse;

/**
 * Chooses a profile per section, in one Jev request: a document that appends an FAQ to its
 * terms gets the normative profile for the terms and guidance for the FAQ.
 *
 * Sections are taken at the first heading level where the document splits into two or more,
 * so a document under a single title is split by the headings below the title.
 */
final readonly class Detector
{
    private const int OPENING = 600;

    public function __construct(
        private JevCaller $jev,
    ) {}

    /**
     * @param  list<Chunk>  $chunks
     * @return array{sections: array<string, string>, scores: array<string, float>}|null section key => profile, or null when Jev did not answer
     */
    public function detect(array $chunks): ?array
    {
        $sections = self::sections($chunks);
        $labels = [];
        foreach (array_keys($sections) as $i => $title) {
            $labels['s' . ($i + 1)] = (string) $title;
        }

        $response = $this->jev->send(function (SystemOne $request) use ($sections, $labels): SystemOne {
            $request->state(['sections' => array_map(static fn (string $title): array => $sections[$title], $labels)]);
            foreach (array_keys($labels) as $label) {
                $request->ask($label, Choice::between(Profiles::detectable())->instructions("What kind of text is section `{$label}`?"));
            }

            return $request;
        });

        if (! $response instanceof SystemOneResponse) {
            return null;
        }

        $out = ['sections' => [], 'scores' => []];
        foreach ($labels as $label => $title) {
            $answer = $response->answers()[$label] ?? null;
            $choice = JevCaller::choice($response, $label);
            $out['sections'][$title] = $choice !== null && isset(Profiles::detectable()[$choice]) ? $choice : Profiles::GUIDANCE;
            $out['scores'][$title] = $answer instanceof \Phox\TypeSafe\Responses\ChoiceAnswer ? $answer->confidence() : 0.0;
        }

        return $out;
    }

    /**
     * Sections keyed by `key()`, each described by its title (the last heading of its key),
     * its opening words and its subheadings.
     *
     * @param  list<Chunk>  $chunks
     * @return array<string, array{title: string, opening: string, headings: list<string>}>
     */
    public static function sections(array $chunks): array
    {
        $depth = self::depth($chunks);
        $sections = [];

        foreach ($chunks as $chunk) {
            $key = self::key($chunk->headingPath, $depth);
            $path = array_slice($chunk->headingPath, 0, $depth + 1);
            $sections[$key] ??= ['title' => $path === [] ? '' : $path[count($path) - 1], 'opening' => Text::head(Links::body($chunk->text), self::OPENING), 'headings' => []];
            foreach (array_slice($chunk->headingPath, $depth + 1) as $heading) {
                if (! in_array($heading, $sections[$key]['headings'], true) && count($sections[$key]['headings']) < 20) {
                    $sections[$key]['headings'][] = $heading;
                }
            }
        }

        return $sections;
    }

    /**
     * The first heading depth (from 0) at which the chunks' heading paths take two or more
     * different values; 0 when they never do.
     *
     * @param  list<Chunk>  $chunks
     */
    public static function depth(array $chunks): int
    {
        $deepest = max([0, ...array_map(static fn (Chunk $chunk): int => count($chunk->headingPath), $chunks)]);

        for ($depth = 0; $depth < $deepest; $depth++) {
            $values = [];
            foreach ($chunks as $chunk) {
                if (isset($chunk->headingPath[$depth])) {
                    $values[$chunk->headingPath[$depth]] = true;
                }
            }
            if (count($values) >= 2) {
                return $depth;
            }
        }

        return 0;
    }

    /**
     * A chunk's section key: its headings down to the section depth, joined by " > ".
     *
     * @param  list<string>  $path
     */
    public static function key(array $path, int $depth): string
    {
        return implode(' > ', array_slice($path, 0, $depth + 1));
    }
}
