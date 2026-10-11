<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\BlockType;
use Phox\ContextAtlas\Exceptions\ContextAtlasException;
use Phox\ContextAtlas\Text;
use stdClass;

/**
 * Reads a Jupyter notebook (nbformat 4 JSON) cell by cell: markdown cells through
 * MarkdownParser, code cells as code followed by their text outputs, raw cells as paragraphs.
 * Images and other rich outputs are dropped. The rules are stated in spec/formats/notebook.md.
 */
final class NotebookParser
{
    private const string NOT_A_NOTEBOOK = 'A notebook must be a JSON object with a "cells" list.';

    private const string ANSI = '/\x1B\[[0-9;?]*[A-Za-z]/';

    /**
     * @return list<Block>
     *
     * @throws ContextAtlasException
     */
    public static function parse(string $text): array
    {
        $notebook = json_decode($text);
        if (! $notebook instanceof stdClass || ! is_array($notebook->cells ?? null)) {
            throw new ContextAtlasException(self::NOT_A_NOTEBOOK);
        }

        $blocks = [];
        foreach ($notebook->cells as $cell) {
            if ($cell instanceof stdClass && is_string($cell->cell_type ?? null)) {
                array_push($blocks, ...self::cell($cell));
            }
        }

        return $blocks;
    }

    /**
     * @return list<Block>
     */
    private static function cell(stdClass $cell): array
    {
        $source = self::joined($cell->source ?? '');

        return match ($cell->cell_type) {
            'markdown' => MarkdownParser::parse($source),
            'raw' => self::paragraphs($source),
            'code' => [...self::code($source), ...self::outputs($cell->outputs ?? [])],
            default => [],
        };
    }

    /**
     * @return list<Block>
     */
    private static function paragraphs(string $source): array
    {
        $blocks = [];
        foreach (preg_split('/\n[ \t]*\n/', self::lines($source)) ?: [] as $piece) {
            $text = Text::squash($piece);
            if ($text !== '') {
                $blocks[] = Block::paragraph($text);
            }
        }

        return $blocks;
    }

    /**
     * @return list<Block>
     */
    private static function code(string $text, string $prefix = ''): array
    {
        $text = self::lines($text);

        return Text::trim($text) === '' ? [] : [new Block(BlockType::Code, $prefix . $text)];
    }

    /**
     * @return list<Block>
     */
    private static function outputs(mixed $outputs): array
    {
        $blocks = [];
        foreach (is_array($outputs) ? $outputs : [] as $output) {
            $type = $output instanceof stdClass ? ($output->output_type ?? null) : null;
            if ($type === 'stream') {
                array_push($blocks, ...self::output(self::joined($output->text ?? '')));
            } elseif ($type === 'execute_result' || $type === 'display_data') {
                $data = $output->data ?? null;
                array_push($blocks, ...self::output($data instanceof stdClass ? self::joined($data->{'text/plain'} ?? '') : ''));
            } elseif ($type === 'error') {
                array_push($blocks, ...self::error(self::joined($output->ename ?? ''), self::joined($output->evalue ?? '')));
            }
        }

        return $blocks;
    }

    /**
     * @return list<Block>
     */
    private static function error(string $name, string $value): array
    {
        $name = Text::trim((string) preg_replace(self::ANSI, '', $name));
        $value = Text::trim((string) preg_replace(self::ANSI, '', self::lines($value)));

        return self::code($value === '' ? $name : ($name === '' ? $value : $name . ': ' . $value));
    }

    /**
     * @return list<Block>
     */
    private static function output(string $text): array
    {
        $text = (string) preg_replace('/\s+$/u', '', (string) preg_replace(self::ANSI, '', self::lines($text)));

        return self::code($text, "Output:\n");
    }

    /**
     * A source or text field: a string, or a list of strings joined with nothing between them.
     */
    private static function joined(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_array($value) ? implode('', array_filter($value, is_string(...))) : '';
    }

    private static function lines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
