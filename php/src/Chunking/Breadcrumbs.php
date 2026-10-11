<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Chunking;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Text;

/**
 * The heading path, section number and heading level of every block.
 *
 * The path holds only real headings, keyed by level, so a heading that skips
 * a level (an h1 followed by an h3) sits directly under its parent instead of
 * repeating it to fill the gap. A section starts at each heading.
 */
final class Breadcrumbs
{
    /**
     * @param  list<Block>  $blocks
     * @return list<array{path: list<string>, section: int, level: int|null}>
     */
    public static function of(array $blocks): array
    {
        $path = [];
        $section = 0;
        $crumbs = [];

        foreach ($blocks as $block) {
            if ($block->isHeading()) {
                $level = (int) $block->level;
                $path = array_filter($path, static fn (int $existing): bool => $existing < $level, ARRAY_FILTER_USE_KEY);
                $path[$level] = Text::squash($block->text);
                ksort($path);
                $section++;
            }

            $crumbs[] = [
                'path' => array_values($path),
                'section' => $section,
                'level' => self::level($block, $path),
            ];
        }

        return $crumbs;
    }

    /**
     * @param  array<int, string>  $path
     */
    private static function level(Block $block, array $path): ?int
    {
        if ($block->isHeading()) {
            return $block->level;
        }

        return $path === [] ? null : max(array_keys($path));
    }
}
