import type { Block } from '../block.js';
import { squash } from '../text.js';

export interface Crumb {
    path: string[];
    section: number;
    level: number | null;
}

/**
 * The heading path, section number and heading level of every block. The path
 * holds only real headings keyed by level, so a heading that skips a level
 * sits directly under its parent. A section starts at each heading.
 */
export function breadcrumbs(blocks: readonly Block[]): Crumb[] {
    let path = new Map<number, string>();
    let section = 0;

    return blocks.map((block) => {
        if (block.isHeading()) {
            const level = block.level ?? 0;
            path = new Map([...path].filter(([existing]) => existing < level));
            path.set(level, squash(block.text));
            path = new Map([...path].sort(([a], [b]) => a - b));
            section++;
        }

        const levels = [...path.keys()];

        return {
            path: [...path.values()],
            section,
            level: block.isHeading() ? block.level : levels.length === 0 ? null : Math.max(...levels),
        };
    });
}
