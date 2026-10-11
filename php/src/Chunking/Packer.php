<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Chunking;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\BlockType;
use Phox\ContextAtlas\Chunk;
use Phox\ContextAtlas\Config;
use Phox\ContextAtlas\Text;

/**
 * Packs blocks into chunks between `minTokens` and `maxTokens`.
 *
 * A heading always starts a chunk, and so does a block or sentence that opens
 * a new topic once the chunk so far holds `topicMinTokens`. The heading's text
 * is not part of the chunk's own text, since it ends the path the chunk is
 * prefixed with, and a heading with nothing under it before the next heading
 * makes no chunk. A paragraph longer
 * than `splitLongParagraphAt` is packed sentence by sentence. Each chunk is
 * then prefixed with its heading path, followed by the tail of the previous
 * chunk's own text when both sit in the same section and it does not open a
 * new topic.
 */
final class Packer
{
    /** @var list<array{blocks: list<int>, text: string, tokens: int, topic_start: bool}> */
    private array $chunks = [];

    /** @var array{blocks: list<int>, text: string, tokens: int, topic_start: bool} */
    private array $current;

    /**
     * @param  list<Block>  $blocks
     * @param  list<int>  $tokens
     * @param  list<array{path: list<string>, section: int, level: int|null}>  $crumbs
     * @param  array{blocks: array<int, true>, sentences: array<int, array<int, true>>}  $shifts
     */
    public function __construct(
        private readonly array $blocks,
        private readonly array $tokens,
        private readonly array $crumbs,
        private readonly array $shifts,
        private readonly Config $config,
    ) {
        $this->current = self::empty();
    }

    /**
     * @return list<Chunk>
     */
    public function pack(): array
    {
        foreach ($this->blocks as $index => $block) {
            $opensTopic = isset($this->shifts['blocks'][$index]) && $this->current['tokens'] >= $this->config->topicMinTokens;

            if ($block->isHeading()) {
                $this->flush(false);
                $this->current = self::empty();
                $this->current['blocks'] = [$index];

                continue;
            }
            if ($opensTopic && $this->current['text'] !== '') {
                $this->flush(true);
            }

            if ($block->type === BlockType::Paragraph && $this->tokens[$index] > $this->config->splitLongParagraphAt) {
                $this->addSentences($index, $block->text);
            } elseif (in_array($block->type, [BlockType::Code, BlockType::Table], true) && $this->tokens[$index] > $this->config->maxTokens) {
                foreach ($this->lineParts(Text::trim($block->text), $block->type === BlockType::Table) as $part) {
                    $this->addBlock($index, $part, Text::tokens($part, $this->config->charsPerToken));
                }
            } else {
                $this->addBlock($index, Text::trim($block->text), $this->tokens[$index]);
            }
        }

        if ($this->current['text'] !== '') {
            $this->flush(false);
        }

        return $this->finish();
    }

    private function addBlock(int $index, string $text, int $tokens): void
    {
        if ($this->config->maxTokens >= $this->current['tokens'] + $tokens) {
            $this->append($index, $text, $tokens);
        } elseif ($this->current['tokens'] >= $this->config->minTokens) {
            $this->flush(false);
            $this->append($index, $text, $tokens);
        } else {
            $this->append($index, $text, $tokens);
            $this->flush(false);
        }
    }

    private function addSentences(int $index, string $text): void
    {
        $parts = [];
        foreach ($this->sentenceParts($text, $this->shifts['sentences'][$index] ?? []) as $part) {
            foreach ($this->cut($part['text'], $this->config->maxTokens) as $i => $piece) {
                $parts[] = ['text' => $piece, 'topic_start' => $part['topic_start'] && $i === 0];
            }
        }
        foreach ($parts as $part) {
            $tokens = Text::tokens($part['text'], $this->config->charsPerToken);
            $full = $this->config->maxTokens < $this->current['tokens'] + $tokens && $this->current['tokens'] >= $this->config->minTokens;
            if ($this->current['text'] !== '' && ($full || $part['topic_start'])) {
                $this->flush($part['topic_start']);
            }
            $this->append($index, $part['text'], $tokens);
        }
    }

    /**
     * A code or table block over `maxTokens` cut at line boundaries into parts of at most
     * `maxTokens`; each part of a table starts with its header line (after its `Table:` caption
     * line, when it has one). A line still over the limit is cut by `cut()`.
     *
     * @return list<string>
     */
    private function lineParts(string $text, bool $table): array
    {
        $lines = explode("\n", $text);
        $head = [];
        if ($table) {
            $head[] = (string) array_shift($lines);
            if (str_starts_with($head[0], 'Table:') && $lines !== []) {
                $head[] = array_shift($lines);
            }
        }
        $headTokens = $head === [] ? 0 : Text::tokens(implode("\n", $head) . "\n", $this->config->charsPerToken);
        $limit = max(1, $this->config->maxTokens - $headTokens);
        $parts = [];
        $buffer = [];
        $tokens = 0;
        foreach ($lines as $line) {
            foreach ($this->cut($line, $limit) as $piece) {
                $pieceTokens = Text::tokens($piece . "\n", $this->config->charsPerToken);
                if ($buffer !== [] && $tokens + $pieceTokens > $limit) {
                    $parts[] = implode("\n", [...$head, ...$buffer]);
                    $buffer = [];
                    $tokens = 0;
                }
                $buffer[] = $piece;
                $tokens += $pieceTokens;
            }
        }
        if ($buffer !== [] || $parts === []) {
            $parts[] = implode("\n", [...$head, ...$buffer]);
        }

        return $parts;
    }

    /**
     * A text over `$limit` tokens cut into pieces of at most `$limit`, each ending before a space
     * when there is one in its second half, else at the limit.
     *
     * @return list<string>
     */
    private function cut(string $text, int $limit): array
    {
        $pieces = [];
        while (Text::tokens($text, $this->config->charsPerToken) > $limit) {
            $length = 1;
            while ($length < mb_strlen($text) && Text::tokens(mb_substr($text, 0, $length + 1), $this->config->charsPerToken) <= $limit) {
                $length++;
            }
            $space = mb_strrpos(mb_substr($text, 0, $length), ' ');
            $end = $space !== false && $space >= intdiv($length, 2) ? $space : $length;
            $pieces[] = rtrim(mb_substr($text, 0, $end));
            $text = ltrim(mb_substr($text, $end));
        }
        $pieces[] = $text;

        return $pieces;
    }

    /**
     * @param  array<int, true>  $shifts
     * @return list<array{text: string, topic_start: bool}>
     */
    private function sentenceParts(string $text, array $shifts): array
    {
        $parts = [];
        $buffer = '';
        $tokens = 0;
        $topicStart = false;

        foreach (Text::sentences($text) as $i => $sentence) {
            $sentenceTokens = Text::tokens($sentence, $this->config->charsPerToken);
            $opensTopic = isset($shifts[$i]) && $tokens >= $this->config->topicMinTokens;
            $full = $tokens + $sentenceTokens > $this->config->maxTokens && $tokens >= $this->config->minTokens;
            if ($buffer !== '' && ($full || $opensTopic)) {
                $parts[] = ['text' => Text::trim($buffer), 'topic_start' => $topicStart];
                $buffer = $sentence;
                $tokens = $sentenceTokens;
                $topicStart = $opensTopic;
            } else {
                $buffer = $buffer === '' ? $sentence : $buffer . ' ' . $sentence;
                $tokens += $sentenceTokens;
            }
        }

        if ($buffer !== '') {
            $parts[] = ['text' => Text::trim($buffer), 'topic_start' => $topicStart];
        }

        return $parts;
    }

    private function append(int $index, string $text, int $tokens): void
    {
        if (! in_array($index, $this->current['blocks'], true)) {
            $this->current['blocks'][] = $index;
        }
        $this->current['text'] .= ($this->current['text'] === '' ? '' : "\n\n") . $text;
        $this->current['tokens'] += $tokens;
    }

    private function flush(bool $nextOpensTopic): void
    {
        if ($this->current['text'] === '') {
            return;
        }

        $this->chunks[] = $this->current;
        $this->current = self::empty();
        $this->current['topic_start'] = $nextOpensTopic;
    }

    /**
     * @return list<Chunk>
     */
    private function finish(): array
    {
        $chunks = [];
        $previous = null;

        foreach ($this->chunks as $raw) {
            $crumb = $this->crumbs[$raw['blocks'][0]];
            $overlap = '';

            if ($previous !== null && $this->config->overlapTokens > 0 && ! $raw['topic_start']
                && $previous['section'] === $crumb['section'] && $previous['path'] === $crumb['path']) {
                $overlap = Text::tail($previous['text'], $this->config->overlapTokens, $this->config->charsPerToken);
            }

            $text = ($crumb['path'] === [] ? '' : '[' . implode(' > ', $crumb['path']) . "]\n\n")
                . ($overlap === '' ? '' : $overlap . "\n\n")
                . $raw['text'];

            $chunks[] = new Chunk(
                $text,
                $raw['blocks'],
                $crumb['path'],
                $crumb['level'],
                $crumb['section'],
                Text::tokens($text, $this->config->charsPerToken),
                $overlap === '' ? 0 : Text::tokens($overlap, $this->config->charsPerToken),
                $raw['topic_start'],
            );
            $previous = ['text' => $raw['text'], 'section' => $crumb['section'], 'path' => $crumb['path']];
        }

        return $chunks;
    }

    /**
     * @return array{blocks: list<int>, text: string, tokens: int, topic_start: bool}
     */
    private static function empty(): array
    {
        return ['blocks' => [], 'text' => '', 'tokens' => 0, 'topic_start' => false];
    }
}
