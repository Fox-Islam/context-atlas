import { squash, trim } from '../../text.js';
import { asciiLower, type Element } from './element.js';

export const BLOCK: ReadonlySet<string> = new Set([
    'address', 'article', 'blockquote', 'body', 'caption', 'center', 'dd', 'details', 'dialog', 'dir', 'div',
    'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'header', 'hgroup', 'hr', 'html', 'legend', 'li',
    'main', 'p', 'search', 'section', 'summary', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr',
]);

export const LISTS: ReadonlySet<string> = new Set(['menu', 'ol', 'ul']);

export const HEADINGS: ReadonlyMap<string, number> = new Map([['h1', 1], ['h2', 2], ['h3', 3], ['h4', 4], ['h5', 5], ['h6', 6]]);

const DROPPED = new Set([
    'aside', 'audio', 'button', 'canvas', 'head', 'iframe', 'map', 'nav', 'noscript', 'object',
    'script', 'select', 'style', 'svg', 'template', 'textarea', 'title', 'video',
]);

const DROPPED_CLASSES = new Set(['catlinks', 'mw-editsection', 'mw-jump-link', 'navbox', 'noprint', 'printfooter']);

const HIDDEN_CLASSES = new Set(['d-none', 'hidden', 'hide', 'invisible', 'is-hidden', 'js-hidden', 'u-hidden']);

const PRINT_CLASSES = new Set(['print-only', 'printonly', 'show-for-print', 'visible-print', 'visible-print-block', 'visible-print-inline']);

const RESPONSIVE_CLASS = /:|^d-(?:sm|md|lg|xl|xxl)-/;

const HIDING_STYLES = new Set(['display:none', 'display:none!important', 'visibility:hidden', 'visibility:hidden!important']);

const FURNITURE = new Set(['footer', 'header']);

const DROPPED_ROLES = new Set(['complementary', 'navigation', 'search', 'tooltip']);

const FURNITURE_ROLES = new Set(['banner', 'contentinfo']);

const PERMALINKS = new Set(['#', '§', '¶']);

const SPEAKER_CLASS = /(?:^|[-_])(?:speakers?|orateur|oratore|orador|redner|sprecher|発言者)(?:[-_]+(?:name|nom|nome|nombre))?$/u;

const SCRIPT_TEXT = /^[-+−]?(?:[0-9][0-9.,]*|\p{L})$/u;

/** PCRE without the `u` flag and with `s`: ASCII `\s`, and `.` matching a line feed. */
const TEX_DISPLAY = /^\{\\displaystyle[ \t\n\v\f\r]*([\s\S]*)\}$/;

/**
 * Whether an element and everything in it is skipped (spec/formats/html.md, Dropped elements).
 * `keepButtons` is true inside a heading, where an accordion puts the heading's text in a
 * `button`.
 */
export function dropped(element: Element, inArticle: boolean, keepButtons = false): boolean {
    const role = element.token('role');

    return (DROPPED.has(element.name) && !isNote(element) && !(keepButtons && element.name === 'button'))
        || (element.name === 'form' && !holdsContent(element))
        || element.attribute('hidden') !== null
        || element.attribute('popover') !== null
        || element.token('aria-hidden') === 'true'
        || (role !== null && DROPPED_ROLES.has(role))
        || hiddenByClass(element.tokens('class'))
        || (hiddenByStyle(element.attribute('style')) && findElement(element, (e) => e.name === 'math') === null)
        || (!inArticle && (FURNITURE.has(element.name) || (role !== null && FURNITURE_ROLES.has(role))))
        || isPermalink(element);
}

export function opensArticle(element: Element): boolean {
    return element.name === 'article' || element.name === 'main' || element.token('role') === 'main';
}

export function isBlock(name: string): boolean {
    return HEADINGS.has(name) || name === 'pre' || name === 'table' || LISTS.has(name) || BLOCK.has(name);
}

/**
 * An element naming who speaks next: a class token ending, after a `-` or `_` or at its start,
 * in a speaker word (`speaker`, `orateur`, `redner`...) optionally followed by a name word
 * (`name`, `nom`...), or an `itemprop` of `author`.
 */
export function isSpeaker(element: Element): boolean {
    return element.tokens('itemprop').includes('author') || element.tokens('class').some((token) => SPEAKER_CLASS.test(token));
}

/** The heading level of an `h1`-`h6`, or of an element with role `heading`: its `aria-level` when that is 1-6, else 2. */
export function headingLevel(element: Element): number | null {
    const level = HEADINGS.get(element.name);

    if (level !== undefined) {
        return level;
    }
    if (element.token('role') !== 'heading') {
        return null;
    }

    const aria = element.token('aria-level') ?? '';

    return /^[1-6]$/.test(aria) ? Number(aria) : 2;
}

/**
 * The text of an element's subtree for one line: a `br` or the edge of a block element adds a
 * space, `math` reads as its text. Callers squash it.
 */
export function lineText(parent: Element, inArticle: boolean, keepButtons = false): string {
    let text = '';

    for (const child of parent.children) {
        if (typeof child === 'string') {
            text += child;
        } else if (!dropped(child, inArticle, keepButtons)) {
            const inside = inArticle || opensArticle(child);

            if (child.name === 'br') {
                text += ' ';
            } else if (child.name === 'math') {
                text += mathText(child);
            } else if (isBlock(child.name)) {
                text += ` ${lineText(child, inside, keepButtons)} `;
            } else {
                text += scriptMark(child, text) + lineText(child, inside, keepButtons);
            }
        }
    }

    return text;
}

/**
 * The mark written before a `sup` (`^`) or `sub` (`_`) whose text is a number or one letter and
 * which follows a letter or digit, so `10<sup>23</sup>` reads `10^23`; nothing otherwise, and
 * nothing for a footnote reference, a `sup` holding only a link whose `href` has a `#`. PCRE's
 * `$` also matches before a final line feed, so a letter followed by one counts.
 */
export function scriptMark(element: Element, before: string): string {
    if ((element.name !== 'sup' && element.name !== 'sub') || !/[\p{L}\p{N}]\n?$/u.test(before)) {
        return '';
    }

    const content = element.children.filter((child) => typeof child !== 'string' || trim(child) !== '');
    const only = content.length === 1 ? content[0] : undefined;
    const reference = only !== undefined && typeof only !== 'string' && only.name === 'a' && (only.attribute('href') ?? '').includes('#');

    if (reference || !SCRIPT_TEXT.test(squash(lineText(element, true)))) {
        return '';
    }

    return element.name === 'sup' ? '^' : '_';
}

/** A `math` element's `alttext` (trimmed, without a TeX `{\displaystyle ...}` wrapper), else its text without `annotation` and `annotation-xml` elements, squashed. */
export function mathText(math: Element): string {
    const alt = trim(math.attribute('alttext') ?? '');

    if (alt !== '') {
        const display = TEX_DISPLAY.exec(alt);

        return display === null ? alt : trim(display[1]!);
    }

    return squash(mathContent(math));
}

function mathContent(parent: Element): string {
    let text = '';

    for (const child of parent.children) {
        if (typeof child === 'string') {
            text += child;
        } else if (child.name !== 'annotation' && child.name !== 'annotation-xml') {
            text += mathContent(child);
        }
    }

    return text;
}

/** The text of a subtree as written, a `br` as a line break. */
export function rawText(parent: Element, inArticle: boolean): string {
    let text = '';

    for (const child of parent.children) {
        if (typeof child === 'string') {
            text += child;
        } else if (dropped(child, inArticle)) {
            continue;
        } else if (child.name === 'br') {
            text += '\n';
        } else {
            text += rawText(child, inArticle || opensArticle(child));
        }
    }

    return text;
}

/** The first element in document order that `match` accepts, not looking inside the elements named in `skip`. */
export function findElement(parent: Element, match: (element: Element) => boolean, skip: readonly string[] = []): Element | null {
    for (const child of parent.children) {
        if (typeof child === 'string') {
            continue;
        }
        if (match(child)) {
            return child;
        }
        if (!skip.includes(child.name)) {
            const found = findElement(child, match, skip);

            if (found !== null) {
                return found;
            }
        }
    }

    return null;
}

/** Furniture classes, print-only classes, and utility classes that hide an element unless a responsive class (Tailwind `md:block`, Bootstrap `d-md-block`) may show it again. */
function hiddenByClass(classes: readonly string[]): boolean {
    if (classes.some((token) => DROPPED_CLASSES.has(token) || PRINT_CLASSES.has(token))) {
        return true;
    }
    if (!classes.some((token) => HIDDEN_CLASSES.has(token))) {
        return false;
    }

    return !classes.some((token) => RESPONSIVE_CLASS.test(token));
}

/** An inline style with a `display:none` or `visibility:hidden` declaration, compared with ASCII whitespace removed and ASCII letters lowercased. */
function hiddenByStyle(style: string | null): boolean {
    if (style === null) {
        return false;
    }

    return asciiLower(style.replace(/[ \t\n\f\r]/g, '')).split(';').some((declaration) => HIDING_STYLES.has(declaration));
}

/** An `aside` holding footnotes or a note, which is content instead of a sidebar. */
function isNote(element: Element): boolean {
    if (element.name !== 'aside') {
        return false;
    }

    return element.tokens('class').some((token) => token.includes('footnote')) || element.token('role') === 'note';
}

/** A form wrapping a page's content instead of holding controls. */
function holdsContent(form: Element): boolean {
    return findElement(form, (e) => e.name === 'article' || e.name === 'main' || HEADINGS.has(e.name)) !== null;
}

/** A link to a place on the same page whose whole text is a permalink sign. */
function isPermalink(element: Element): boolean {
    return element.name === 'a'
        && (element.attribute('href') ?? '').startsWith('#')
        && PERMALINKS.has(squash(rawText(element, true)));
}
