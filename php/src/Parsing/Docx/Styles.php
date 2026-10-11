<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing\Docx;

use DOMElement;

/**
 * Paragraph styles from word/styles.xml: each style's own name, and the outline level and
 * numbering it has or inherits through `w:basedOn`. The rules are stated in
 * spec/formats/docx.md.
 */
final class Styles
{
    private const int CHAIN = 20;

    /** @var array<string, array{name: string, basedOn: ?string, outline: ?int, numId: ?string, ilvl: ?int}> */
    private array $styles = [];

    private ?string $default = null;

    public function __construct(?DOMElement $root)
    {
        foreach (($root === null ? [] : $root->childNodes) as $style) {
            if (! Package::isW($style, 'style') || ! $style instanceof DOMElement || Package::attribute($style, 'type') !== 'paragraph') {
                continue;
            }
            $id = Package::attribute($style, 'styleId') ?? '';
            if (isset($this->styles[$id])) {
                continue;
            }
            $properties = Package::child($style, 'pPr');
            $numbering = Package::child($properties, 'numPr');
            $this->styles[$id] = [
                'name' => Package::value($style, 'name') ?? '',
                'basedOn' => Package::value($style, 'basedOn'),
                'outline' => self::integer(Package::value($properties, 'outlineLvl')),
                'numId' => Package::value($numbering, 'numId'),
                'ilvl' => self::integer(Package::value($numbering, 'ilvl')),
            ];
            if ($this->default === null && in_array(Package::attribute($style, 'default'), ['1', 'true', 'on'], true)) {
                $this->default = $id;
            }
        }
    }

    /**
     * The style a paragraph has: its `w:pStyle` when that names a known style, else the default
     * paragraph style.
     */
    public function resolve(?string $id): ?string
    {
        return $id !== null && isset($this->styles[$id]) ? $id : $this->default;
    }

    public function name(?string $id): string
    {
        return $id === null ? '' : ($this->styles[$id]['name'] ?? '');
    }

    /**
     * The first value of `$key` set on the style or the styles it is based on.
     *
     * @param  'outline'|'numId'|'ilvl'  $key
     */
    public function inherited(?string $id, string $key): int|string|null
    {
        for ($seen = 0; $id !== null && isset($this->styles[$id]) && $seen < self::CHAIN; $seen++) {
            if ($this->styles[$id][$key] !== null) {
                return $this->styles[$id][$key];
            }
            $id = $this->styles[$id]['basedOn'];
        }

        return null;
    }

    public static function integer(?string $value): ?int
    {
        return $value !== null && preg_match('/^-?[0-9]{1,9}$/D', $value) === 1 ? (int) $value : null;
    }
}
