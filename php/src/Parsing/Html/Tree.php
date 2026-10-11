<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing\Html;

/**
 * Tokenizes HTML and builds a tree with a stack of open elements: void elements never open,
 * `/>` closes any element at once, a few start tags close the element they cannot sit in
 * (a `p` before a block, an `li` before the next `li`, table cells and rows), and an end tag
 * closes the nearest open element of its name or is ignored. Positions are byte offsets, and
 * every character the tokenizer looks for is ASCII. spec/formats/html.md states the rules.
 */
final class Tree
{
    private const string NAME = '[A-Za-z][^\t\n\f\r \/>]*';

    private const string SPACE = '[\t\n\f\r ]*';

    private const array VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'keygen', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    private const array RAW = ['iframe', 'noembed', 'noframes', 'noscript', 'script', 'style', 'textarea', 'title', 'xmp'];

    private const array ESCAPABLE_RAW = ['textarea', 'title'];

    private const array CLOSES_P = [
        'address', 'article', 'aside', 'blockquote', 'center', 'dd', 'details', 'dialog', 'dir', 'div', 'dl', 'dt',
        'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup',
        'hr', 'li', 'main', 'menu', 'nav', 'ol', 'p', 'pre', 'search', 'section', 'summary', 'table', 'ul',
    ];

    private const array HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    private const array SCOPE = ['button', 'caption', 'html', 'table', 'td', 'template', 'th'];

    private const array TABLE_PARTS = ['caption', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr'];

    private const array SECTIONS = ['tbody', 'tfoot', 'thead'];

    private readonly Element $root;

    /** @var list<Element> open elements below the document node, innermost last */
    private array $stack = [];

    private function __construct(
        private readonly string $html,
    ) {
        $this->root = new Element('#document');
    }

    /**
     * The document node of `$html`, whose line endings are already `\n`.
     */
    public static function build(string $html): Element
    {
        $tree = new self($html);
        $tree->run();

        return $tree->root;
    }

    private function run(): void
    {
        $length = strlen($this->html);
        $at = 0;

        while ($at < $length) {
            $lt = strpos($this->html, '<', $at);
            if ($lt === false) {
                $this->text(substr($this->html, $at));

                break;
            }
            if ($lt > $at) {
                $this->text(substr($this->html, $at, $lt - $at));
            }
            $at = $this->markup($lt);
        }
    }

    /**
     * Reads the markup token at `$at`, a `<`, and returns the position after it.
     */
    private function markup(int $at): int
    {
        $match = [];

        if (substr_compare($this->html, '<!--', $at, 4) === 0) {
            return $this->after('-->', $at + 4);
        }
        if (preg_match('/\G(?:<[!?]|<\/(?![A-Za-z]))/', $this->html, $match, 0, $at) === 1) {
            return $this->after('>', $at + 2);
        }
        if (preg_match('/\G<\/(' . self::NAME . ')[^>]*(?:>|\z)/', $this->html, $match, 0, $at) === 1) {
            $this->end(strtolower($match[1]));

            return $at + strlen($match[0]);
        }
        if (preg_match('/\G<(' . self::NAME . ')/', $this->html, $match, 0, $at) === 1) {
            return $this->startTag(strtolower($match[1]), $at + strlen($match[0]));
        }

        $this->text('<');

        return $at + 1;
    }

    /**
     * The position just after the next `$needle` from `$from`, or the end of the input.
     */
    private function after(string $needle, int $from): int
    {
        $found = $from <= strlen($this->html) ? strpos($this->html, $needle, $from) : false;

        return $found === false ? strlen($this->html) : $found + strlen($needle);
    }

    /**
     * Reads a start tag's attributes from `$at`, just after its name. A tag the input ends
     * inside is dropped.
     */
    private function startTag(string $name, int $at): int
    {
        $length = strlen($this->html);
        $attributes = [];
        $selfClosing = false;
        $match = [];

        while (true) {
            if (preg_match('/\G' . self::SPACE . '/', $this->html, $match, 0, $at) === 1) {
                $at += strlen($match[0]);
            }
            if ($at >= $length) {
                return $length;
            }
            if ($this->html[$at] === '>') {
                $at++;

                break;
            }
            if ($this->html[$at] === '/') {
                $at++;
                if ($at < $length && $this->html[$at] === '>') {
                    $selfClosing = true;
                    $at++;

                    break;
                }

                continue;
            }

            $attribute = preg_match('/\G[^\t\n\f\r \/>][^\t\n\f\r \/>=]*/', $this->html, $match, 0, $at) === 1 ? $match[0] : $this->html[$at];
            $at += strlen($attribute);
            $attribute = strtolower($attribute);
            $value = '';
            if (preg_match('/\G' . self::SPACE . '=' . self::SPACE . '/', $this->html, $match, 0, $at) === 1) {
                $at += strlen($match[0]);
                if (preg_match('/\G(?:"([^"]*)(?:"|\z)|\'([^\']*)(?:\'|\z)|([^\t\n\f\r >]*))/', $this->html, $match, PREG_UNMATCHED_AS_NULL, $at) === 1) {
                    $at += strlen((string) $match[0]);
                    $value = Entities::decode($match[1] ?? $match[2] ?? $match[3] ?? '');
                }
            }
            $attributes[$attribute] ??= $value;
        }

        $this->start($name, $attributes, $selfClosing);

        return in_array($name, self::RAW, true) && ! $selfClosing ? $this->rawText($name, $at) : $at;
    }

    /**
     * The content of a raw text element runs to its end tag, which is then read as a tag.
     */
    private function rawText(string $name, int $at): int
    {
        $match = [];
        $end = preg_match('/<\/' . $name . '(?=[\t\n\f\r \/>]|\z)/i', $this->html, $match, PREG_OFFSET_CAPTURE, $at) === 1
            ? $match[0][1]
            : strlen($this->html);

        if ($end > $at) {
            $raw = substr($this->html, $at, $end - $at);
            $this->current()->append(in_array($name, self::ESCAPABLE_RAW, true) ? Entities::decode($raw) : $raw);
        }

        return $end;
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function start(string $name, array $attributes, bool $selfClosing): void
    {
        if (in_array($name, self::CLOSES_P, true)) {
            $this->close(['p'], self::SCOPE);
        }

        match (true) {
            $name === 'li' => $this->close(['li'], [...self::SCOPE, 'menu', 'ol', 'ul']),
            $name === 'dd', $name === 'dt' => $this->close(['dd', 'dt'], [...self::SCOPE, 'dl']),
            in_array($name, self::HEADINGS, true) => $this->closeHeading(),
            $name === 'tr' => $this->close(['tr'], ['table', ...self::SECTIONS]),
            $name === 'td', $name === 'th' => $this->close(['td', 'th'], ['table', 'tr', ...self::SECTIONS]),
            in_array($name, self::SECTIONS, true) => $this->close(self::SECTIONS, ['table']) || $this->close(['tr'], ['table']),
            default => false,
        };

        $element = new Element($name, $attributes);
        $this->current()->append($element);

        if (! $selfClosing && ! in_array($name, self::VOID, true)) {
            $this->stack[] = $element;
        }
    }

    private function end(string $name): void
    {
        if ($name === 'br') {
            $this->start('br', [], false);

            return;
        }

        $boundaries = match (true) {
            $name === 'table' => [],
            in_array($name, self::TABLE_PARTS, true) => ['table'],
            default => self::SCOPE,
        };

        $this->close([$name], $boundaries);
    }

    /**
     * Closes the nearest open element named in `$targets`, with everything opened inside it,
     * unless an element named in `$boundaries` is reached first. The document node never closes.
     *
     * @param  list<string>  $targets
     * @param  list<string>  $boundaries
     */
    private function close(array $targets, array $boundaries): bool
    {
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            $name = $this->stack[$i]->name;
            if (in_array($name, $targets, true)) {
                array_splice($this->stack, $i);

                return true;
            }
            if (in_array($name, $boundaries, true)) {
                return false;
            }
        }

        return false;
    }

    private function closeHeading(): bool
    {
        if ($this->stack !== [] && in_array($this->current()->name, self::HEADINGS, true)) {
            array_pop($this->stack);
        }

        return true;
    }

    private function text(string $text): void
    {
        $this->current()->append(Entities::decode($text));
    }

    private function current(): Element
    {
        return $this->stack === [] ? $this->root : $this->stack[count($this->stack) - 1];
    }
}
