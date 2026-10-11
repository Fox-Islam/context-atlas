<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing\Docx;

use Closure;
use DOMElement;
use Phox\ContextAtlas\Text;

/**
 * The text of a paragraph's runs: `w:t` text, tabs and breaks as characters, field instructions
 * dropped and field results kept, deleted text dropped, note references as `[n]`. Text boxes met
 * on the way are handed to the body reader and returned beside the text. Field state carries
 * across paragraphs, as fields may. The rules are stated in spec/formats/docx.md.
 */
final class Runs
{
    private const string MC = 'http://schemas.openxmlformats.org/markup-compatibility/2006';

    private const array MATH = ['http://schemas.openxmlformats.org/officeDocument/2006/math', 'http://purl.oclc.org/ooxml/officeDocument/math'];

    private const string TOC = '/^\s*TOC(?![A-Za-z])/i';

    /** @var list<array{bool, string}> per open field: whether text is dropped (instruction or table-of-contents result), and its instruction */
    private array $fields = [];

    /** @var array<string, DOMElement> "footnote:<id>" or "endnote:<id>" => note */
    private array $notes = [];

    /** @var array<string, int> note key => its number */
    private array $numbers = [];

    private bool $inNote = false;

    /** @var list<array<string, mixed>> */
    private array $boxes = [];

    /**
     * @param  Closure(DOMElement): list<array<string, mixed>>  $body  reads a text box's content
     */
    public function __construct(?DOMElement $footnotes, ?DOMElement $endnotes, private readonly Closure $body)
    {
        foreach (['footnote' => $footnotes, 'endnote' => $endnotes] as $kind => $root) {
            foreach (($root === null ? [] : $root->childNodes) as $note) {
                if (Package::isW($note, $kind) && $note instanceof DOMElement && ! in_array(Package::attribute($note, 'type'), ['separator', 'continuationSeparator', 'continuationNotice'], true)) {
                    $this->notes[$kind . ':' . (Package::attribute($note, 'id') ?? '')] ??= $note;
                }
            }
        }
    }

    /**
     * @return array{string, list<array<string, mixed>>} the paragraph's text and the items of the
     *                                                   text boxes inside it
     */
    public function read(DOMElement $paragraph): array
    {
        $boxes = $this->boxes;
        $this->boxes = [];
        $text = $this->walk($paragraph);
        $found = $this->boxes;
        $this->boxes = $boxes;

        return [$text, $found];
    }

    /**
     * The referenced notes in marker order, each `[n] text` with its paragraphs' text squashed and
     * joined by spaces; a note whose text is empty gives `[n]`.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        $this->inNote = true;
        $this->fields = [];
        $lines = [];
        foreach ($this->numbers as $key => $number) {
            $parts = [];
            foreach ($this->notes[$key]->childNodes as $child) {
                if (Package::isW($child, 'p') && $child instanceof DOMElement) {
                    $parts[] = Text::squash($this->read($child)[0]);
                }
            }
            $lines[] = Text::trim("[{$number}] " . implode(' ', array_filter($parts, static fn (string $part): bool => $part !== '')));
        }

        return $lines;
    }

    private function walk(DOMElement $element): string
    {
        $out = '';
        foreach ($element->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if (in_array($node->namespaceURI, Package::W, true)) {
                $out .= $this->word($node);
            } elseif ($node->namespaceURI === self::MC && $node->localName === 'AlternateContent') {
                $out .= $this->alternate($node);
            } elseif (in_array($node->namespaceURI, self::MATH, true) && $node->localName === 't') {
                $out .= $this->dropping() ? '' : $node->textContent;
            } else {
                $out .= $this->walk($node);
            }
        }

        return $out;
    }

    private function word(DOMElement $node): string
    {
        $name = (string) $node->localName;

        return match (true) {
            $name === 't' => $this->dropping() ? '' : $node->textContent,
            $name === 'tab', $name === 'ptab' => $this->dropping() ? '' : "\t",
            $name === 'br', $name === 'cr' => $this->dropping() ? '' : "\n",
            $name === 'noBreakHyphen' => $this->dropping() ? '' : '-',
            $name === 'sym' => $this->dropping() ? '' : self::symbol($node),
            $name === 'fldChar' => $this->field(Package::attribute($node, 'fldCharType')),
            $name === 'footnoteReference', $name === 'endnoteReference' => $this->reference($node, $name === 'footnoteReference' ? 'footnote' : 'endnote'),
            $name === 'ruby' => $this->ruby($node),
            $name === 'txbxContent' => $this->box($node),
            $name === 'instrText' => $this->instruction($node->textContent),
            $name === 'fldSimple' && preg_match(self::TOC, Package::attribute($node, 'instr') ?? '') === 1 => '',
            $name === 'sdt' && self::isContents($node) => '',
            in_array($name, ['del', 'moveFrom', 'delText', 'delInstrText', 'softHyphen'], true), str_ends_with($name, 'Pr') => '',
            default => $this->walk($node),
        };
    }

    private function ruby(DOMElement $node): string
    {
        $base = Package::child($node, 'rubyBase');

        return $base === null ? '' : $this->walk($base);
    }

    private function alternate(DOMElement $node): string
    {
        $fallback = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->namespaceURI === self::MC) {
                if ($child->localName === 'Choice') {
                    return $this->walk($child);
                }
                $fallback ??= $child->localName === 'Fallback' ? $child : null;
            }
        }

        return $fallback === null ? '' : $this->walk($fallback);
    }

    private function dropping(): bool
    {
        return in_array(true, array_column($this->fields, 0), true);
    }

    private function field(?string $type): string
    {
        if ($type === 'begin') {
            $this->fields[] = [true, ''];
        } elseif ($type === 'separate' && $this->fields !== []) {
            [, $instruction] = (array) array_pop($this->fields);
            $this->fields[] = [preg_match(self::TOC, (string) $instruction) === 1, (string) $instruction];
        } elseif ($type === 'end') {
            array_pop($this->fields);
        }

        return '';
    }

    private function instruction(string $text): string
    {
        if ($this->fields !== []) {
            [$dropped, $instruction] = (array) array_pop($this->fields);
            $this->fields[] = [(bool) $dropped, $instruction . $text];
        }

        return '';
    }

    /**
     * Whether a content control is a table of contents: its `w:sdtPr/w:docPartObj/w:docPartGallery`
     * value is `Table of Contents`.
     */
    public static function isContents(DOMElement $sdt): bool
    {
        return Package::value(Package::child(Package::child($sdt, 'sdtPr'), 'docPartObj'), 'docPartGallery') === 'Table of Contents';
    }

    private function reference(DOMElement $node, string $kind): string
    {
        $key = $kind . ':' . (Package::attribute($node, 'id') ?? '');
        if ($this->inNote || $this->dropping() || ! isset($this->notes[$key])) {
            return '';
        }
        $this->numbers[$key] ??= count($this->numbers) + 1;

        return '[' . $this->numbers[$key] . ']';
    }

    private function box(DOMElement $node): string
    {
        if (! $this->inNote) {
            array_push($this->boxes, ...($this->body)($node));
        }

        return '';
    }

    private static function symbol(DOMElement $node): string
    {
        $font = mb_strtolower(Package::attribute($node, 'font') ?? '');
        $char = Package::attribute($node, 'char') ?? '';
        if (preg_match('/^[0-9A-Fa-f]{1,6}$/D', $char) !== 1 || preg_match('/symbol|wingdings|webdings/', $font) === 1) {
            return '';
        }
        $code = (int) hexdec($char);
        $code = $code >= 0xF000 ? $code - 0xF000 : $code;
        $valid = $code >= 0x20 && $code <= 0x10FFFF && ($code < 0xD800 || $code > 0xDFFF);

        return $valid ? (string) mb_chr($code, 'UTF-8') : '';
    }
}
