<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing\Html;

use Phox\ContextAtlas\Text;

/**
 * Which elements are read and how their text is taken: the drop rules, block elements,
 * speaker markers, and the line and raw text of a subtree. spec/formats/html.md states them.
 */
final class Content
{
    public const array BLOCK = [
        'address', 'article', 'blockquote', 'body', 'caption', 'center', 'dd', 'details', 'dialog', 'dir', 'div',
        'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'header', 'hgroup', 'hr', 'html', 'legend', 'li',
        'main', 'p', 'search', 'section', 'summary', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr',
    ];

    public const array LISTS = ['menu', 'ol', 'ul'];

    public const array HEADINGS = ['h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6];

    private const array DROPPED = [
        'aside', 'audio', 'button', 'canvas', 'head', 'iframe', 'map', 'nav', 'noscript', 'object',
        'script', 'select', 'style', 'svg', 'template', 'textarea', 'title', 'video',
    ];

    private const array DROPPED_CLASSES = ['catlinks', 'mw-editsection', 'mw-jump-link', 'navbox', 'noprint', 'printfooter'];

    private const array HIDDEN_CLASSES = ['d-none', 'hidden', 'hide', 'invisible', 'is-hidden', 'js-hidden', 'u-hidden'];

    private const array PRINT_CLASSES = ['print-only', 'printonly', 'show-for-print', 'visible-print', 'visible-print-block', 'visible-print-inline'];

    private const string RESPONSIVE_CLASS = '/:|^d-(?:sm|md|lg|xl|xxl)-/';

    private const array HIDING_STYLES = ['display:none', 'display:none!important', 'visibility:hidden', 'visibility:hidden!important'];

    private const array FURNITURE = ['footer', 'header'];

    private const array DROPPED_ROLES = ['complementary', 'navigation', 'search', 'tooltip'];

    private const array FURNITURE_ROLES = ['banner', 'contentinfo'];

    private const array PERMALINKS = ['#', '§', '¶'];

    private const string SPEAKER_CLASS = '/(?:^|[-_])(?:speakers?|orateur|oratore|orador|redner|sprecher|発言者)(?:[-_]+(?:name|nom|nome|nombre))?$/u';

    private const string SCRIPT_TEXT = '/^[-+−]?(?:[0-9][0-9.,]*|\p{L})$/u';

    private const string TEX_DISPLAY = '/^\{\\\\displaystyle\s*(.*)\}$/s';

    /**
     * `$keepButtons` is true inside a heading, where an accordion puts the heading's text in
     * a `button`.
     */
    public static function dropped(Element $element, bool $inArticle, bool $keepButtons = false): bool
    {
        $role = $element->token('role');

        return (in_array($element->name, self::DROPPED, true) && ! self::isNote($element) && ! ($keepButtons && $element->name === 'button'))
            || ($element->name === 'form' && ! self::holdsContent($element))
            || $element->attribute('hidden') !== null
            || $element->attribute('popover') !== null
            || $element->token('aria-hidden') === 'true'
            || in_array($role, self::DROPPED_ROLES, true)
            || self::hiddenByClass($element->tokens('class'))
            || (self::hiddenByStyle($element->attribute('style')) && self::find($element, static fn (Element $e): bool => $e->name === 'math') === null)
            || (! $inArticle && (in_array($element->name, self::FURNITURE, true) || in_array($role, self::FURNITURE_ROLES, true)))
            || self::isPermalink($element);
    }

    public static function opensArticle(Element $element): bool
    {
        return $element->name === 'article' || $element->name === 'main' || $element->token('role') === 'main';
    }

    public static function isBlock(string $name): bool
    {
        return isset(self::HEADINGS[$name]) || in_array($name, ['pre', 'table', ...self::LISTS, ...self::BLOCK], true);
    }

    /**
     * An element naming who speaks next: a class token ending in `speaker`, `speakers`,
     * `speaker-name` or `speaker_name` after a `-` or `_` or at its start, or an `itemprop`
     * of `author`.
     */
    public static function isSpeaker(Element $element): bool
    {
        if (in_array('author', $element->tokens('itemprop'), true)) {
            return true;
        }

        foreach ($element->tokens('class') as $token) {
            if (preg_match(self::SPEAKER_CLASS, $token) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The heading level of an `h1`-`h6`, or of an element with role `heading`: its
     * `aria-level` when that is 1-6, else 2.
     */
    public static function headingLevel(Element $element): ?int
    {
        if (isset(self::HEADINGS[$element->name])) {
            return self::HEADINGS[$element->name];
        }
        if ($element->token('role') !== 'heading') {
            return null;
        }

        $level = $element->token('aria-level') ?? '';

        return preg_match('/^[1-6]$/', $level) === 1 ? (int) $level : 2;
    }

    /**
     * The text of an element's subtree for one line: a `br` or the edge of a block element
     * adds a space, `math` reads as its text. Callers squash it.
     */
    public static function line(Element $parent, bool $inArticle, bool $keepButtons = false): string
    {
        $text = '';

        foreach ($parent->children as $child) {
            if (is_string($child)) {
                $text .= $child;
            } elseif (! self::dropped($child, $inArticle, $keepButtons)) {
                $inside = $inArticle || self::opensArticle($child);
                $text .= match (true) {
                    $child->name === 'br' => ' ',
                    $child->name === 'math' => self::math($child),
                    self::isBlock($child->name) => ' ' . self::line($child, $inside, $keepButtons) . ' ',
                    default => self::script($child, $text) . self::line($child, $inside, $keepButtons),
                };
            }
        }

        return $text;
    }

    /**
     * The mark written before a `sup` (`^`) or `sub` (`_`) whose text is a number or one letter
     * and which follows a letter or digit, so `10<sup>23</sup>` reads `10^23` and `H<sub>2</sub>O`
     * reads `H_2O`; nothing otherwise, and nothing for a footnote reference, a `sup` holding
     * only a link whose `href` has a `#`.
     */
    public static function script(Element $element, string $before): string
    {
        if (($element->name !== 'sup' && $element->name !== 'sub') || preg_match('/[\p{L}\p{N}]$/u', $before) !== 1) {
            return '';
        }

        $content = array_values(array_filter($element->children, static fn (Element|string $c): bool => ! is_string($c) || Text::trim($c) !== ''));
        $reference = count($content) === 1 && $content[0] instanceof Element && $content[0]->name === 'a'
            && str_contains($content[0]->attribute('href') ?? '', '#');

        return match (true) {
            $reference, preg_match(self::SCRIPT_TEXT, Text::squash(self::line($element, true))) !== 1 => '',
            $element->name === 'sup' => '^',
            default => '_',
        };
    }

    /**
     * A `math` element's `alttext` (trimmed, without a TeX `{\displaystyle ...}` wrapper), else
     * its text without `annotation` and `annotation-xml` elements, squashed.
     */
    public static function math(Element $math): string
    {
        $alt = Text::trim($math->attribute('alttext') ?? '');
        if ($alt !== '') {
            return preg_match(self::TEX_DISPLAY, $alt, $match) === 1 ? Text::trim($match[1]) : $alt;
        }

        return Text::squash(self::mathText($math));
    }

    private static function mathText(Element $parent): string
    {
        $text = '';

        foreach ($parent->children as $child) {
            if (is_string($child)) {
                $text .= $child;
            } elseif ($child->name !== 'annotation' && $child->name !== 'annotation-xml') {
                $text .= self::mathText($child);
            }
        }

        return $text;
    }

    /**
     * The text of a subtree as written, a `br` as a line break.
     */
    public static function raw(Element $parent, bool $inArticle): string
    {
        $text = '';

        foreach ($parent->children as $child) {
            $text .= match (true) {
                is_string($child) => $child,
                self::dropped($child, $inArticle) => '',
                $child->name === 'br' => "\n",
                default => self::raw($child, $inArticle || self::opensArticle($child)),
            };
        }

        return $text;
    }

    /**
     * The first element in document order that `$match` accepts, not looking inside the
     * elements named in `$skip`.
     *
     * @param  callable(Element): bool  $match
     * @param  list<string>  $skip
     */
    public static function find(Element $parent, callable $match, array $skip = []): ?Element
    {
        foreach ($parent->children as $child) {
            if (! $child instanceof Element) {
                continue;
            }
            if ($match($child)) {
                return $child;
            }
            if (! in_array($child->name, $skip, true)) {
                $found = self::find($child, $match, $skip);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Furniture classes, print-only classes, and utility classes that hide an element unless a
     * responsive class (Tailwind `md:block`, Bootstrap `d-md-block`) may show it again.
     *
     * @param  list<string>  $classes
     */
    private static function hiddenByClass(array $classes): bool
    {
        if (array_intersect($classes, [...self::DROPPED_CLASSES, ...self::PRINT_CLASSES]) !== []) {
            return true;
        }
        if (array_intersect($classes, self::HIDDEN_CLASSES) === []) {
            return false;
        }

        return preg_grep(self::RESPONSIVE_CLASS, $classes) === [];
    }

    /**
     * An inline style with a `display:none` or `visibility:hidden` declaration, compared with
     * ASCII whitespace removed and ASCII letters lowercased.
     */
    private static function hiddenByStyle(?string $style): bool
    {
        if ($style === null) {
            return false;
        }

        $declarations = explode(';', strtolower(str_replace([' ', "\t", "\n", "\f", "\r"], '', $style)));

        return array_intersect($declarations, self::HIDING_STYLES) !== [];
    }

    /**
     * An `aside` holding footnotes or a note, which is content instead of a sidebar.
     */
    private static function isNote(Element $element): bool
    {
        if ($element->name !== 'aside') {
            return false;
        }

        foreach ($element->tokens('class') as $token) {
            if (str_contains($token, 'footnote')) {
                return true;
            }
        }

        return $element->token('role') === 'note';
    }

    /**
     * A form wrapping a page's content instead of holding controls.
     */
    private static function holdsContent(Element $form): bool
    {
        return self::find($form, static fn (Element $e): bool => in_array($e->name, ['article', 'main'], true) || isset(self::HEADINGS[$e->name])) !== null;
    }

    /**
     * A link to a place on the same page whose whole text is a permalink sign.
     */
    private static function isPermalink(Element $element): bool
    {
        return $element->name === 'a'
            && str_starts_with($element->attribute('href') ?? '', '#')
            && in_array(Text::squash(self::raw($element, true)), self::PERMALINKS, true);
    }
}
