<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Chunking;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\BlockType;
use Phox\ContextAtlas\Config;
use Phox\ContextAtlas\Text;

/**
 * Finds where a long stretch of text changes topic, so it can be chunked there
 * instead of wherever the size limit falls.
 *
 * Headings already split sections, so two kinds of stretch are asked about:
 * the body blocks of a section holding more than `minTokens`, and the
 * sentences of a paragraph longer than `splitLongParagraphAt`. Block seams are
 * keyed `b{index}`, sentence seams `b{index}s{sentence}`, after the unit that
 * opens the seam's second side.
 */
final readonly class TopicShifts
{
    public function __construct(
        private SeamScorer $scorer,
        private CutPlanner $planner,
        private Config $config,
    ) {}

    /**
     * @param  list<Block>  $blocks
     * @param  list<int>  $tokens  token count of each block
     * @param  list<array{path: list<string>, section: int, level: int|null}>  $crumbs
     * @return array{blocks: array<int, true>, sentences: array<int, array<int, true>>}|null indexes of the
     *     blocks that open a new topic, and of the sentences that do inside long paragraphs; null when
     *     nothing was worth asking about or Jev did not answer
     */
    public function find(array $blocks, array $tokens, array $crumbs, bool $quietSpeech = false): ?array
    {
        $stretches = [...$this->sectionStretches($blocks, $tokens, $crumbs), ...$this->sentenceStretches($blocks, $tokens)];
        if ($quietSpeech) {
            $stretches = array_values(array_filter($stretches, static fn (array $units): bool => ! self::spoken($blocks, $units)));
        }
        $pairs = self::pairs($stretches);

        if ($pairs === []) {
            return null;
        }

        $scores = $this->scorer->score($pairs);

        if ($scores === null) {
            return null;
        }

        $shifts = ['blocks' => [], 'sentences' => []];

        foreach ($stretches as $units) {
            foreach ($this->planner->cuts($units, $scores) as $key) {
                if (preg_match('/^b(\d+)(?:s(\d+))?$/', $key, $m) !== 1) {
                    continue;
                }
                if (isset($m[2])) {
                    $shifts['sentences'][(int) $m[1]][(int) $m[2]] = true;
                } else {
                    $shifts['blocks'][(int) $m[1]] = true;
                }
            }
        }

        return $shifts;
    }

    /**
     * Whether the blocks of a stretch that name a speaker hold at least half its text: the
     * stretch is part of a conversation, which is not chunked.
     *
     * @param  list<Block>  $blocks
     * @param  list<array{key: string, text: string, tokens: int}>  $units
     */
    private static function spoken(array $blocks, array $units): bool
    {
        $total = 0;
        $spoken = 0;
        foreach ($units as $unit) {
            $block = $blocks[(int) substr($unit['key'], 1)];
            $length = mb_strlen(Text::trim($unit['text']));
            $total += $length;
            if (is_string($block->meta['speaker'] ?? null) && $block->meta['speaker'] !== '') {
                $spoken += $length;
            }
        }

        return $spoken > 0 && 2 * $spoken >= $total;
    }

    /**
     * @param  list<list<array{key: string, text: string, tokens: int}>>  $stretches
     * @return array<string, array{0: string, 1: string}>
     */
    private static function pairs(array $stretches): array
    {
        $pairs = [];

        foreach ($stretches as $units) {
            for ($i = 1, $n = count($units); $i < $n; $i++) {
                $pairs[$units[$i]['key']] = [$units[$i - 1]['text'], $units[$i]['text']];
            }
        }

        return $pairs;
    }

    /**
     * @param  list<Block>  $blocks
     * @param  list<int>  $tokens
     * @param  list<array{path: list<string>, section: int, level: int|null}>  $crumbs
     * @return list<list<array{key: string, text: string, tokens: int}>>
     */
    private function sectionStretches(array $blocks, array $tokens, array $crumbs): array
    {
        $bySection = [];

        foreach ($blocks as $index => $block) {
            if (! $block->isHeading()) {
                $bySection[$crumbs[$index]['section']][] = ['key' => 'b' . $index, 'text' => $block->text, 'tokens' => $tokens[$index]];
            }
        }

        $stretches = [];
        foreach ($bySection as $units) {
            if (count($units) > 1 && array_sum(array_column($units, 'tokens')) > $this->config->minTokens) {
                $stretches[] = $units;
            }
        }

        return $stretches;
    }

    /**
     * @param  list<Block>  $blocks
     * @param  list<int>  $tokens
     * @return list<list<array{key: string, text: string, tokens: int}>>
     */
    private function sentenceStretches(array $blocks, array $tokens): array
    {
        $stretches = [];

        foreach ($blocks as $index => $block) {
            if ($block->type !== BlockType::Paragraph || $tokens[$index] <= $this->config->splitLongParagraphAt) {
                continue;
            }
            $units = [];
            foreach (Text::sentences($block->text) as $i => $sentence) {
                $units[] = ['key' => 'b' . $index . 's' . $i, 'text' => $sentence, 'tokens' => Text::tokens($sentence, $this->config->charsPerToken)];
            }
            if (count($units) > 1) {
                $stretches[] = $units;
            }
        }

        return $stretches;
    }
}
