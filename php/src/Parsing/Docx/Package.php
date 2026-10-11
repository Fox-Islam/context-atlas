<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing\Docx;

use DOMDocument;
use DOMElement;
use Phox\ContextAtlas\Exceptions\ContextAtlasException;
use ZipArchive;

/**
 * The parts of a DOCX package the reader uses, found through the package relationships and
 * parsed as XML. Refuses packages whose parts unpack to more than LIMIT bytes and XML that
 * declares a DOCTYPE, so no entity is expanded and nothing is fetched. The rules are stated in
 * spec/formats/docx.md.
 */
final class Package
{
    public const int LIMIT = 50 * 1024 * 1024;

    public const array W = ['http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'http://purl.oclc.org/ooxml/wordprocessingml/main'];

    private const int MAX_DEPTH = 1000;

    private const string REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const array DEFAULTS = [
        'styles' => 'word/styles.xml',
        'numbering' => 'word/numbering.xml',
        'footnotes' => 'word/footnotes.xml',
        'endnotes' => 'word/endnotes.xml',
    ];

    private int $read = 0;

    private function __construct(private readonly ZipArchive $zip, private readonly string $name) {}

    /**
     * @return array{document: DOMElement, styles: ?DOMElement, numbering: ?DOMElement, footnotes: ?DOMElement, endnotes: ?DOMElement, core: ?DOMElement}
     *
     * @throws ContextAtlasException
     */
    public static function open(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new ContextAtlasException('Reading DOCX needs the zip extension.');
        }
        $zip = new ZipArchive();
        $name = basename($path);
        if (! is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ContextAtlasException("Cannot open {$name} as a DOCX document.");
        }

        try {
            return (new self($zip, $name))->parts();
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{document: DOMElement, styles: ?DOMElement, numbering: ?DOMElement, footnotes: ?DOMElement, endnotes: ?DOMElement, core: ?DOMElement}
     */
    private function parts(): array
    {
        $root = $this->relationships('_rels/.rels', '');
        $main = $root['officeDocument'] ?? 'word/document.xml';
        $document = $this->xml($main) ?? throw new ContextAtlasException("{$this->name} has no main document part ({$main}).");
        $slash = strrpos($main, '/');
        $folder = $slash === false ? '' : substr($main, 0, $slash + 1);
        $related = $this->relationships($folder . '_rels/' . substr($main, $slash === false ? 0 : $slash + 1) . '.rels', $folder);
        $parts = ['document' => $document];

        foreach (self::DEFAULTS as $type => $default) {
            $parts[$type] = $this->xml($related[$type] ?? $default);
        }
        $parts['core'] = $this->xml($root['core-properties'] ?? 'docProps/core.xml');

        return $parts;
    }

    /**
     * Relationship type (the last segment of its Type) => part path, for internal targets: a
     * target starting with `/` is from the package root, any other from `$folder`.
     *
     * @return array<string, string>
     */
    private function relationships(string $part, string $folder): array
    {
        $rels = $this->xml($part);
        $targets = [];
        if ($rels === null) {
            return $targets;
        }

        foreach ($rels->getElementsByTagNameNS(self::REL, 'Relationship') as $relation) {
            $type = $relation->getAttribute('Type');
            $slash = strrpos($type, '/');
            $type = $slash === false ? $type : substr($type, $slash + 1);
            if ($relation->getAttribute('TargetMode') === 'External' || isset($targets[$type])) {
                continue;
            }
            $target = $relation->getAttribute('Target');
            $targets[$type] = self::normalise(str_starts_with($target, '/') ? substr($target, 1) : $folder . $target);
        }

        return $targets;
    }

    /**
     * A path with `.` segments and each `..` with the segment before it removed.
     */
    private static function normalise(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    /**
     * The root element of a part, or null when the package has no such part.
     *
     * @throws ContextAtlasException
     */
    private function xml(string $part): ?DOMElement
    {
        $stat = $this->zip->statName($part);
        if ($stat === false) {
            return null;
        }
        $this->read += (int) $stat['size'];
        if (! in_array((int) $stat['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
            throw new ContextAtlasException("{$part} in {$this->name} cannot be read.");
        }
        if ($this->read > self::LIMIT) {
            throw new ContextAtlasException("{$this->name} is too large: its parts unpack to more than 50 MB.");
        }
        $bytes = $this->zip->getFromName($part, (int) $stat['size'] + 1);
        if ($bytes === false || strlen($bytes) !== (int) $stat['size']) {
            throw new ContextAtlasException("{$part} in {$this->name} cannot be read.");
        }

        $bytes = str_starts_with($bytes, "\u{FEFF}") ? substr($bytes, 3) : $bytes;
        if (preg_match('/<!DOCTYPE/i', $bytes) === 1) {
            throw new ContextAtlasException("{$part} in {$this->name} declares a DOCTYPE, which is not read.");
        }
        $bytes = (string) preg_replace('/^\s*<\?xml[^>]*\?>/', '', $bytes);

        if (self::depth($bytes) > self::MAX_DEPTH) {
            throw new ContextAtlasException("{$part} in {$this->name} is nested more than " . self::MAX_DEPTH . ' elements deep.');
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = trim($bytes) !== '' && mb_check_encoding($bytes, 'UTF-8') && $document->loadXML($bytes, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        foreach (libxml_get_errors() as $error) {
            $loaded = $loaded && $error->level === LIBXML_ERR_WARNING;
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded || $document->documentElement === null) {
            throw new ContextAtlasException("{$part} in {$this->name} is not well-formed XML.");
        }

        return $document->documentElement;
    }

    /**
     * The deepest element nesting of a part, from a scan of its tags that skips comments, CDATA
     * sections and processing instructions, so a hostile part is refused before the parser
     * builds millions of nodes. A self-closing tag does not nest.
     */
    private static function depth(string $bytes): int
    {
        $depth = 0;
        $deepest = 0;
        $length = strlen($bytes);
        for ($at = strpos($bytes, '<'); $at !== false && $at < $length; $at = strpos($bytes, '<', $at + 1)) {
            $next = $bytes[$at + 1] ?? '';
            if (str_starts_with(substr($bytes, $at, 4), '<!--')) {
                $end = strpos($bytes, '-->', $at + 4);
                if ($end === false) {
                    break;
                }
                $at = $end + 2;
            } elseif (str_starts_with(substr($bytes, $at, 9), '<![CDATA[')) {
                $end = strpos($bytes, ']]>', $at + 9);
                if ($end === false) {
                    break;
                }
                $at = $end + 2;
            } elseif ($next === '?') {
                $end = strpos($bytes, '?>', $at + 2);
                if ($end === false) {
                    break;
                }
                $at = $end + 1;
            } elseif ($next === '/') {
                $depth = max(0, $depth - 1);
            } else {
                $close = self::tagEnd($bytes, $at + 1);
                if ($close === null) {
                    break;
                }
                if ($bytes[$close - 1] !== '/') {
                    $deepest = max($deepest, ++$depth);
                }
                $at = $close;
            }
        }

        return $deepest;
    }

    /**
     * The offset of the `>` that ends a tag, skipping any inside quoted attribute values.
     */
    private static function tagEnd(string $bytes, int $from): ?int
    {
        $quote = null;
        for ($i = $from, $n = strlen($bytes); $i < $n; $i++) {
            $char = $bytes[$i];
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '>') {
                return $i;
            }
        }

        return null;
    }

    public static function isW(mixed $node, string $name): bool
    {
        return $node instanceof DOMElement && $node->localName === $name && in_array($node->namespaceURI, self::W, true);
    }

    /**
     * A `w:` attribute's value, or null.
     */
    public static function attribute(DOMElement $element, string $name): ?string
    {
        foreach (self::W as $namespace) {
            if ($element->hasAttributeNS($namespace, $name)) {
                return $element->getAttributeNS($namespace, $name);
            }
        }

        return null;
    }

    /**
     * The first child element `w:<name>`, or null.
     */
    public static function child(?DOMElement $element, string $name): ?DOMElement
    {
        foreach (($element === null ? [] : $element->childNodes) as $node) {
            if (self::isW($node, $name)) {
                return $node instanceof DOMElement ? $node : null;
            }
        }

        return null;
    }

    /**
     * The `w:val` of the child element `w:<name>`, or null.
     */
    public static function value(?DOMElement $element, string $name): ?string
    {
        $child = self::child($element, $name);

        return $child === null ? null : self::attribute($child, 'val');
    }
}
