<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tabular;

use Phox\ContextAtlas\Exceptions\ContextAtlasException;
use SimpleXMLElement;
use ZipArchive;

/**
 * The cells of a spreadsheet as the file gives them: per sheet, rows keyed from 1 and cells keyed
 * from 0, with empty cells left out.
 */
final readonly class Grid
{
    /**
     * @param  list<array{name: string, hidden: bool, rows: array<int, array<int, string>>}>  $sheets
     */
    public function __construct(
        public array $sheets,
    ) {}

    /**
     * Reads CSV text. Text that is not valid UTF-8 is read as Windows-1252, the usual encoding
     * of spreadsheets exported on Windows.
     */
    public static function csv(string $text, string $name = 'Sheet1'): self
    {
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return new self([['name' => $name, 'hidden' => false, 'rows' => []]]);
        }
        fwrite($stream, $text);
        rewind($stream);
        $rows = [];
        $number = 0;

        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $number++;
            $cells = array_filter($row, static fn (?string $cell): bool => $cell !== null && trim($cell) !== '');
            if ($cells !== []) {
                $rows[$number] = $cells;
            }
        }

        fclose($stream);

        return new self([['name' => $name, 'hidden' => false, 'rows' => $rows]]);
    }

    /**
     * Reads an XLSX workbook from a file. Needs the zip extension.
     *
     * @throws ContextAtlasException
     */
    public static function xlsx(string $path): self
    {
        if (! class_exists(ZipArchive::class)) {
            throw new ContextAtlasException('Reading XLSX needs the zip extension.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new ContextAtlasException("Cannot open {$path} as an XLSX workbook.");
        }

        $strings = self::sharedStrings($zip);
        $targets = self::sheetTargets($zip);
        $workbook = self::xml($zip, 'xl/workbook.xml');
        $sheets = [];

        foreach ($workbook->sheets->sheet ?? [] as $sheet) {
            $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $sheets[] = [
                'name' => (string) $sheet['name'],
                'hidden' => in_array((string) $sheet['state'], ['hidden', 'veryHidden'], true),
                'rows' => self::cells(self::xml($zip, $targets[$id] ?? ''), $strings),
            ];
        }

        $zip->close();

        return new self($sheets);
    }

    /**
     * @return list<string>
     */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $strings = [];
        foreach ((new SimpleXMLElement($xml))->si as $item) {
            $text = (string) $item->t;
            foreach ($item->r as $run) {
                $text .= (string) $run->t;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * A target starting with `/` is from the package root, any other from `xl/`.
     *
     * @return array<string, string> relationship id => part path
     */
    private static function sheetTargets(ZipArchive $zip): array
    {
        $targets = [];

        foreach (self::xml($zip, 'xl/_rels/workbook.xml.rels')->Relationship as $relation) {
            $target = (string) $relation['Target'];
            $targets[(string) $relation['Id']] = str_starts_with($target, '/') ? substr($target, 1) : 'xl/' . $target;
        }

        return $targets;
    }

    /**
     * A row or cell without a reference takes the next position after the one before it.
     *
     * @param  list<string>  $strings
     * @return array<int, array<int, string>>
     */
    private static function cells(SimpleXMLElement $sheet, array $strings): array
    {
        $rows = [];
        $number = 0;

        foreach ($sheet->sheetData->row ?? [] as $row) {
            $number = (string) $row['r'] !== '' ? (int) $row['r'] : $number + 1;
            $column = -1;
            foreach ($row->c as $cell) {
                [$column, $number] = (string) $cell['r'] !== '' ? self::reference((string) $cell['r']) : [$column + 1, $number];
                $value = match ((string) $cell['t']) {
                    's' => $strings[(int) $cell->v] ?? '',
                    'inlineStr' => (string) $cell->is->t,
                    default => (string) $cell->v,
                };
                if (trim($value) !== '') {
                    $rows[$number][$column] = $value;
                }
            }
        }

        ksort($rows);

        return $rows;
    }

    /**
     * @return array{int, int} column from 0, row from 1
     */
    private static function reference(string $reference): array
    {
        preg_match('/^([A-Z]+)(\d+)$/', $reference, $match);
        $column = 0;

        foreach (str_split($match[1] ?? 'A') as $letter) {
            $column = $column * 26 + ord($letter) - 64;
        }

        return [$column - 1, (int) ($match[2] ?? 1)];
    }

    /**
     * @throws ContextAtlasException
     */
    private static function xml(ZipArchive $zip, string $part): SimpleXMLElement
    {
        $xml = $zip->getFromName($part);

        if ($xml === false) {
            throw new ContextAtlasException("The workbook has no {$part}.");
        }

        return new SimpleXMLElement($xml, LIBXML_NONET);
    }
}
