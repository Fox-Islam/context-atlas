<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Chunking;

use Phox\ContextAtlas\Config;
use Phox\ContextAtlas\Jev\JevCaller;
use Phox\ContextAtlas\Text;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Requests\SystemOne;
use Phox\TypeSafe\Responses\SystemOneResponse;

/**
 * Asks Jev, for each seam between two neighbouring passages, how likely the
 * second continues the first one's subject and how likely it depends on the
 * first to be understood at all.
 *
 * The two differ where it matters: a worked example after the rule it
 * illustrates can read as a change of subject while being useless alone.
 * Only the end of the first passage and the start of the second are sent,
 * because a topic continues or not at the seam.
 *
 * Returns null, never a partial result, when any batch goes unanswered.
 */
final readonly class SeamScorer
{
    public const string CONTINUES = 'p_';

    public const string REFERS_BACK = 'r_';

    public function __construct(
        private JevCaller $jev,
        private Config $config,
    ) {}

    /**
     * @param  array<string, array{0: string, 1: string}>  $pairs  seam key => [first passage, second passage]
     * @return array<string, array{continues: float, refers_back: float}>|null
     */
    public function score(array $pairs): ?array
    {
        $pairs = array_slice($pairs, 0, $this->config->maxPairs, true);
        $scores = [];

        foreach (array_chunk($pairs, max(1, $this->config->batchSize), true) as $batch) {
            $response = $this->jev->send(fn (SystemOne $request): SystemOne => $this->build($request, $batch));

            if (! $response instanceof SystemOneResponse) {
                return null;
            }

            foreach (array_keys($batch) as $key) {
                $continues = JevCaller::noul($response, self::CONTINUES . $key);
                if ($continues !== null) {
                    $scores[(string) $key] = ['continues' => $continues, 'refers_back' => JevCaller::noul($response, self::REFERS_BACK . $key) ?? 0.0];
                }
            }
        }

        return $scores;
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $batch
     */
    private function build(SystemOne $request, array $batch): SystemOne
    {
        $seams = [];

        foreach ($batch as $key => [$first, $second]) {
            $seams[(string) $key] = [
                'end_of_first' => Text::end($first, $this->config->snippetChars),
                'start_of_second' => Text::head($second, $this->config->snippetChars),
            ];
        }

        $request->state(['seams' => $seams]);

        foreach (array_keys($seams) as $key) {
            $request->ask(
                self::CONTINUES . $key,
                Noul::ask("In seam {$key}, is the second passage about the same specific subject as the first, rather than just the same document?")
                    ->yes('the same specific subject: a reader looking for one would want the other')
                    ->no('a different subject, even though both belong to the same document'),
            );
            $request->ask(
                self::REFERS_BACK . $key,
                Noul::ask("In seam {$key}, does the second passage depend on the first to be understood?")
                    ->yes('it refers back to the first, continues its list or example, or answers it, so it makes little sense read alone')
                    ->no('it can be understood without the first'),
            );
        }

        return $request;
    }
}
