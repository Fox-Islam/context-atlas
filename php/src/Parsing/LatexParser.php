<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\BlockType;
use Phox\ContextAtlas\Text;

/**
 * Reads LaTeX source: the body of the document environment, sectioning commands as headings,
 * verbatim and display maths as code, tabulars as tables and lists as list paragraphs. Inline
 * commands are unwrapped by LatexInline. The rules are stated in spec/formats/latex.md.
 */
final class LatexParser
{
    private const string VERBATIM_BEGIN = '/\\\\begin\{(verbatim|Verbatim|lstlisting|minted)(\*?)\}/';

    private const string TOKEN = '/(?<!\\\\)\\\\(?:[vV]erb\*?([^A-Za-z \t\n*]).*?\g{-1}|(begin|end)\{([^{}]*)\}|(part|chapter|section|subsection|subsubsection|paragraph|subparagraph)(?![A-Za-z])|(item|bibitem|caption|title|maketitle|par)(?![A-Za-z])|(\[))|(\n[ \t]*\n)|\x00(\d+)\x00/u';

    private const array SECTIONS = ['part' => 0, 'chapter' => 1, 'section' => 2, 'subsection' => 3, 'subsubsection' => 4, 'paragraph' => 5, 'subparagraph' => 6];

    private const array MATH = ['equation', 'equation*', 'align', 'align*', 'gather', 'gather*', 'multline', 'multline*', 'flalign', 'flalign*', 'alignat', 'alignat*', 'eqnarray', 'eqnarray*', 'displaymath', 'math'];

    private const array LISTS = ['itemize', 'itemize*', 'enumerate', 'enumerate*', 'description', 'description*', 'thebibliography'];

    /** @var array<string, int> tabular environment => number of required arguments */
    private const array TABULARS = ['tabular' => 1, 'tabular*' => 2, 'tabularx' => 2, 'tabulary' => 2, 'longtable' => 1];

    private const array FLOATS = ['table' => 'table', 'table*' => 'table', 'figure' => 'figure', 'figure*' => 'figure'];

    private const array DROPPED = ['comment', 'tikzpicture', 'pgfpicture', 'picture', 'filecontents', 'filecontents*'];

    /** @var list<array{kind: string, block?: Block, rank?: int, text?: string}> */
    private array $events = [];

    /** @var list<string> */
    private array $codes = [];

    private string $paragraph = '';

    /** @var list<array{name: string, kind: string, count: int, caption: ?string, tables: list<list<list<string>>>}> */
    private array $frames = [];

    /** @var list<string> */
    private array $listLines = [];

    private ?string $item = null;

    private string $marker = '';

    private ?string $label = null;

    private bool $description = false;

    private string $indent = '';

    private bool $maketitle = false;

    /**
     * @return list<Block>
     */
    public static function parse(string $text): array
    {
        return (new self())->read($text);
    }

    /**
     * @return list<Block>
     */
    private function read(string $text): array
    {
        $text = str_replace(["\r\n", "\r", "\x00", "\x01", "\x02", "\x03"], ["\n", "\n", '', '', '', ''], $text);
        $clean = $this->clean(str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text);
        $begin = strpos($clean, '\\begin{document}');
        $body = $clean;

        if ($begin !== false) {
            $body = substr($clean, $begin + strlen('\\begin{document}'));
            $end = strpos($body, '\\end{document}');
            $body = $end === false ? $body : substr($body, 0, $end);
        }

        $this->walk($body);

        return $this->blocks(self::title($clean));
    }

    /**
     * Removes comments and lifts verbatim environments out as numbered placeholder lines.
     */
    private function clean(string $text): string
    {
        $out = [];
        $code = [];
        $verbatim = null;

        foreach (explode("\n", $text) as $line) {
            $rest = $line;
            $fresh = true;

            while (true) {
                if ($verbatim !== null) {
                    $close = '\\end{' . $verbatim . '}';
                    $end = strpos($rest, $close);
                    if ($end === false) {
                        $code[] = $rest;
                        break;
                    }
                    if (Text::trim(substr($rest, 0, $end)) !== '') {
                        $code[] = substr($rest, 0, $end);
                    }
                    $out[] = "\x00" . count($this->codes) . "\x00";
                    $this->codes[] = implode("\n", $code);
                    $code = [];
                    $verbatim = null;
                    $rest = substr($rest, $end + strlen($close));
                    $fresh = false;

                    continue;
                }

                [$stripped, $commented] = self::uncomment($rest);
                $match = [];

                if (preg_match(self::VERBATIM_BEGIN, $stripped, $match, PREG_OFFSET_CAPTURE) === 1) {
                    $before = substr($stripped, 0, $match[0][1]);
                    if (Text::trim($before) !== '') {
                        $out[] = $before;
                    }
                    $verbatim = $match[1][0] . $match[2][0];
                    $rest = self::skipVerbatimArguments(substr($rest, $match[0][1] + strlen($match[0][0])), $match[1][0]);
                    $fresh = false;
                    if (Text::trim($rest) === '') {
                        break;
                    }

                    continue;
                }

                if (Text::trim($stripped) !== '' || (! $commented && $fresh)) {
                    $out[] = $stripped;
                }
                break;
            }
        }

        if ($verbatim !== null) {
            $out[] = "\x00" . count($this->codes) . "\x00";
            $this->codes[] = implode("\n", $code);
        }

        return implode("\n", $out);
    }

    /**
     * @return array{string, bool} the line before its first unescaped `%`, and whether there was one
     */
    private static function uncomment(string $line): array
    {
        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            if ($line[$i] === '\\') {
                if (preg_match('/\G\\\\[vV]erb\*?([^A-Za-z \t\n*])/u', $line, $verb, 0, $i) === 1) {
                    $close = strpos($line, $verb[1], $i + strlen($verb[0]));
                    $i = $close === false ? $n : $close;
                } else {
                    $i++;
                }
            } elseif ($line[$i] === '%') {
                return [substr($line, 0, $i), true];
            }
        }

        return [$line, false];
    }

    private static function skipVerbatimArguments(string $rest, string $name): string
    {
        $i = LatexInline::space($rest, 0);
        if (($rest[$i] ?? '') === '[') {
            $i = LatexInline::space($rest, LatexInline::optional($rest, $i)[1]);
        }
        if ($name === 'minted' && ($rest[$i] ?? '') === '{') {
            $i = LatexInline::group($rest, $i)[1];
        }

        return substr($rest, $i);
    }

    private static function title(string $clean): string
    {
        if (preg_match('/(?<!\\\\)\\\\title(?![A-Za-z])/', $clean, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }
        [$argument] = LatexInline::argument($clean, $match[0][1] + strlen($match[0][0]));

        return $argument === null ? '' : Text::squash(LatexInline::convert($argument));
    }

    private function walk(string $body): void
    {
        $at = 0;
        $n = strlen($body);

        while ($at < $n && preg_match(self::TOKEN, $body, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $at) === 1) {
            $this->append(substr($body, $at, (int) $match[0][1] - $at));
            $at = (int) $match[0][1] + strlen((string) $match[0][0]);

            if ($match[1][0] !== null) {
                $this->append((string) $match[0][0]);
            } elseif ($match[2][0] !== null) {
                $at = $match[2][0] === 'begin' ? $this->begin($body, (string) $match[3][0], $at) : $this->end((string) $match[3][0], $at);
            } elseif ($match[4][0] !== null) {
                $at = $this->section($body, self::SECTIONS[$match[4][0]], $at);
            } elseif ($match[5][0] !== null) {
                $at = $this->command($body, $match[5][0], $at);
            } elseif ($match[6][0] !== null) {
                $end = strpos($body, '\\]', $at);
                $this->math($end === false ? substr($body, $at) : substr($body, $at, $end - $at));
                $at = $end === false ? $n : $end + 2;
            } elseif ($match[7][0] !== null) {
                $this->breakParagraph();
            } else {
                $this->code($this->codes[(int) $match[8][0]]);
            }
        }

        if ($at < $n) {
            $this->append(substr($body, $at));
        }
        $this->closeLists();
        $this->flush();
    }

    private function begin(string $body, string $name, int $at): int
    {
        if (in_array($name, self::MATH, true) || in_array($name, self::DROPPED, true)) {
            $at = str_starts_with($name, 'alignat') && ($body[$at] ?? '') === '{' ? LatexInline::group($body, $at)[1] : $at;
            [$content, $at] = self::capture($body, $name, $at);
            if (in_array($name, self::MATH, true)) {
                $this->math($content);
            }

            return $at;
        }
        if (isset(self::TABULARS[$name])) {
            return $this->tabular($body, $name, $at);
        }

        $kind = in_array($name, self::LISTS, true) ? 'list' : (self::FLOATS[$name] ?? 'other');
        if ($kind === 'list') {
            $this->listOpening();
            $at = $name === 'thebibliography' ? LatexInline::argument($body, $at)[1] : $at;
        } else {
            if ($this->listDepth() === 0) {
                $this->flush();
            }
            $at = LatexInline::directArguments($body, $at)[1];
        }
        $this->frames[] = ['name' => $name, 'kind' => $kind, 'count' => 0, 'caption' => null, 'tables' => []];

        return $at;
    }

    private function end(string $name, int $at): int
    {
        $found = array_keys(array_column($this->frames, 'name'), $name, true);
        if ($found === []) {
            return $at;
        }
        $found = $found[count($found) - 1];

        while (count($this->frames) > $found) {
            $frame = array_pop($this->frames);
            if ($frame['kind'] === 'list') {
                $this->finishItem();
                if ($this->listDepth() === 0) {
                    $this->emitList();
                }
            } elseif ($frame['kind'] === 'table') {
                $this->flush();
                $this->emitFloat($frame['caption'], $frame['tables']);
            } elseif ($this->listDepth() === 0) {
                $this->flush();
            }
        }

        return $at;
    }

    private function section(string $body, int $rank, int $at): int
    {
        $at = ($body[$at] ?? '') === '*' ? $at + 1 : $at;
        [$argument, $at] = LatexInline::argument($body, $at);
        $this->closeLists();
        $this->flush();
        $text = $argument === null ? '' : Text::squash(LatexInline::convert($argument));
        if ($text !== '') {
            $this->events[] = ['kind' => 'heading', 'rank' => $rank, 'text' => $text];
        }

        return $at;
    }

    private function command(string $body, string $name, int $at): int
    {
        if ($name === 'par') {
            $this->breakParagraph();
        } elseif ($name === 'maketitle') {
            $this->maketitle = true;
        } elseif ($name === 'title') {
            $at = LatexInline::argument($body, $at)[1];
        } elseif ($name === 'caption') {
            $at = ($body[$at] ?? '') === '*' ? $at + 1 : $at;
            [$argument, $at] = LatexInline::argument($body, $at);
            $this->caption($argument === null ? '' : Text::squash(LatexInline::convert($argument)));
        } else {
            $at = $this->item($body, $name, $at);
        }

        return $at;
    }

    private function item(string $body, string $name, int $at): int
    {
        $label = null;
        $i = LatexInline::space($body, $at);
        if (($body[$i] ?? '') === '[') {
            [$raw, $at] = LatexInline::optional($body, $i);
            $label = Text::squash(LatexInline::convert($raw));
        }
        if ($name === 'bibitem') {
            $at = LatexInline::argument($body, $at)[1];
        }

        $index = $this->listIndex();
        if ($index < 0) {
            return $at;
        }

        $this->finishItem();
        $list = $this->frames[$index]['name'];
        if ($label === null && in_array($list, ['enumerate', 'enumerate*'], true)) {
            $this->frames[$index]['count']++;
        }
        $this->indent = str_repeat('  ', $this->listDepth() - 1);
        $this->description = in_array($list, ['description', 'description*'], true);
        $this->label = $label;
        $this->marker = in_array($list, ['enumerate', 'enumerate*'], true) ? $this->frames[$index]['count'] . '.' : '-';
        $this->item = '';

        return $at;
    }

    private function caption(string $text): void
    {
        for ($i = count($this->frames) - 1; $i >= 0; $i--) {
            if ($this->frames[$i]['kind'] === 'table') {
                $this->frames[$i]['caption'] = $text;

                return;
            }
            if ($this->frames[$i]['kind'] === 'figure') {
                $this->interrupt();
                $this->emitParagraph($text === '' ? '' : 'Figure: ' . $text);

                return;
            }
        }

        $this->interrupt();
        $this->emitParagraph($text);
    }

    private function tabular(string $body, string $name, int $at): int
    {
        $i = $at;
        for ($required = self::TABULARS[$name]; $required > 0;) {
            $i = LatexInline::space($body, $i, false);
            if (($body[$i] ?? '') === '[') {
                $i = LatexInline::optional($body, $i)[1];
            } elseif (($body[$i] ?? '') === '{') {
                $i = LatexInline::group($body, $i)[1];
                $required--;
            } else {
                break;
            }
        }

        [$content, $at] = self::capture($body, $name, $i);
        [$caption, $rows] = LatexTable::read($content, $name === 'longtable');

        for ($f = count($this->frames) - 1; $f >= 0; $f--) {
            if ($this->frames[$f]['kind'] === 'table') {
                $this->frames[$f]['tables'][] = $rows;
                if ($caption !== null) {
                    $this->frames[$f]['caption'] = $caption;
                }

                return $at;
            }
        }

        $this->interrupt();
        $this->emitTable($caption ?? '', $rows);

        return $at;
    }

    /**
     * @return array{string, int} the environment's content and the offset after its `\end`
     */
    private static function capture(string $body, string $name, int $at): array
    {
        $open = '\\begin{' . $name . '}';
        $close = '\\end{' . $name . '}';
        $depth = 1;
        $from = $at;

        while (true) {
            $nextOpen = strpos($body, $open, $from);
            $nextClose = strpos($body, $close, $from);
            if ($nextClose === false) {
                return [substr($body, $at), strlen($body)];
            }
            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $depth++;
                $from = $nextOpen + strlen($open);

                continue;
            }
            if (--$depth === 0) {
                return [substr($body, $at, $nextClose - $at), $nextClose + strlen($close)];
            }
            $from = $nextClose + strlen($close);
        }
    }

    private function math(string $content): void
    {
        $content = (string) preg_replace('/\\\\label\{[^{}]*\}/', '', $content);
        $lines = array_values(array_filter(array_map(Text::trim(...), explode("\n", $content)), static fn (string $line): bool => $line !== ''));
        if ($lines !== []) {
            $this->code(implode("\n", $lines));
        }
    }

    private function code(string $code): void
    {
        $this->interrupt();
        if (Text::trim($code) !== '') {
            $this->events[] = ['kind' => 'block', 'block' => new Block(BlockType::Code, $code)];
        }
    }

    private function append(string $raw): void
    {
        if ($this->listDepth() === 0) {
            $this->paragraph .= $raw;
        } elseif ($this->item !== null) {
            $this->item .= $raw;
        }
    }

    private function breakParagraph(): void
    {
        if ($this->listDepth() === 0) {
            $this->flush();
        } else {
            $this->append(' ');
        }
    }

    private function flush(): void
    {
        $this->emitParagraph(Text::squash(LatexInline::paragraph($this->paragraph)));
        $this->paragraph = '';
    }

    private function emitParagraph(string $text): void
    {
        if ($text !== '') {
            $this->events[] = ['kind' => 'block', 'block' => Block::paragraph($text)];
        }
    }

    /**
     * Closes the open paragraph, and the list lines so far, before a block that interrupts them;
     * an open item goes on after the block as an unmarked line.
     */
    private function interrupt(): void
    {
        $this->flush();
        if ($this->listDepth() > 0) {
            $open = $this->item !== null;
            $this->finishItem();
            $this->emitList();
            if ($open) {
                $this->item = '';
                $this->marker = '';
                $this->label = null;
                $this->description = false;
            }
        }
    }

    private function listOpening(): void
    {
        if ($this->listDepth() === 0) {
            $this->flush();
        } else {
            $this->finishItem();
        }
    }

    private function finishItem(): void
    {
        if ($this->item === null) {
            return;
        }

        $text = Text::squash(LatexInline::convert($this->item));
        $this->item = null;

        if ($this->description) {
            $parts = array_values(array_filter([$this->label ?? '', $text], static fn (string $part): bool => $part !== ''));
            $line = $parts === [] ? '' : '- ' . implode(': ', $parts);
        } elseif ($this->label === null) {
            $line = $text === '' ? '' : Text::trim($this->marker . ' ' . $text);
        } else {
            $line = implode(' ', array_values(array_filter([$this->label, $text], static fn (string $part): bool => $part !== '')));
        }

        if ($line !== '') {
            $this->listLines[] = $this->indent . $line;
        }
    }

    private function emitList(): void
    {
        if ($this->listLines !== []) {
            $this->events[] = ['kind' => 'block', 'block' => Block::paragraph(implode("\n", $this->listLines))];
        }
        $this->listLines = [];
    }

    private function closeLists(): void
    {
        if ($this->listDepth() > 0) {
            $this->finishItem();
            $this->emitList();
            $this->frames = array_values(array_filter($this->frames, static fn (array $frame): bool => $frame['kind'] !== 'list'));
        }
    }

    /**
     * @param  list<list<list<string>>>  $tables
     */
    private function emitFloat(?string $caption, array $tables): void
    {
        if ($tables === []) {
            $this->emitParagraph($caption === null || $caption === '' ? '' : 'Table: ' . $caption);

            return;
        }
        foreach ($tables as $rows) {
            $this->emitTable($caption ?? '', $rows);
        }
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function emitTable(string $caption, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $lines = array_map(static fn (array $row): string => implode(' | ', $row), $rows);
        if ($caption !== '') {
            array_unshift($lines, 'Table: ' . $caption);
        }
        $meta = ['caption' => $caption, 'header' => $rows[0], 'rows' => array_slice($rows, 1)];

        $this->events[] = ['kind' => 'block', 'block' => new Block(BlockType::Table, implode("\n", $lines), null, $meta)];
    }

    private function listDepth(): int
    {
        return count(array_filter($this->frames, static fn (array $frame): bool => $frame['kind'] === 'list'));
    }

    private function listIndex(): int
    {
        for ($i = count($this->frames) - 1; $i >= 0; $i--) {
            if ($this->frames[$i]['kind'] === 'list') {
                return $i;
            }
        }

        return -1;
    }

    /**
     * @return list<Block>
     */
    private function blocks(string $title): array
    {
        $ranks = [];
        foreach ($this->events as $event) {
            if (isset($event['rank'])) {
                $ranks[] = $event['rank'];
            }
        }
        $withTitle = $title !== '' && ($this->maketitle || $ranks === []);
        $top = $ranks === [] ? 0 : min($ranks);
        $blocks = $withTitle ? [Block::heading($title, 1)] : [];

        foreach ($this->events as $event) {
            if (isset($event['block'])) {
                $blocks[] = $event['block'];
            } elseif (isset($event['rank'], $event['text'])) {
                $blocks[] = Block::heading($event['text'], $event['rank'] - $top + ($withTitle ? 2 : 1));
            }
        }

        return $blocks;
    }
}
