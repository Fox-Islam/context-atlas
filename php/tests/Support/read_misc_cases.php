<?php

declare(strict_types=1);

/*
 * Reader fixtures for LaTeX, Jupyter notebooks, WebVTT, SRT and MDX: each case reads one small
 * document into blocks, or records the error every package must give.
 */

$read = static fn (string $name, string $format, string $text): array => ['name' => $name, 'kind' => 'read', 'input' => ['format' => $format, 'text' => $text]];

$notebook = static fn (array $cells): string => (string) json_encode(['cells' => $cells, 'metadata' => [], 'nbformat' => 4, 'nbformat_minor' => 5]);

return [
    $read('latex: the document body, a title with maketitle, and headings relative to the highest level used', 'latex', <<<'TEX'
        \documentclass{article}
        \usepackage{amsmath}
        \title{Fee \emph{Schedule}\thanks{Draft.}}
        \author{A. Writer}
        \begin{document}
        \maketitle
        \section{Charges}
        Invoices are issued monthly.
        \subsection*{Late payment}
        Interest accrues daily.
        \end{document}
        Text after the end is ignored.
        TEX),
    $read('latex: comments, escaped percent, inline commands, accents, dashes, quotes and inline maths', 'latex', <<<'TEX'
        A rate of 5\% applies % unless waived
        % a whole comment line keeps the paragraph together
        to \textbf{all} \emph{late} invoices~\cite[p.~2]{terms, annex} (see Section~\ref{sec:fees}\label{x}).
        Pages 3--5 --- ``in full''. Caf\'e \"{o}ffnung \c{c}a, \LaTeX{} and $a_{n} + 50\%$ stay.
        Contact \href{mailto:fees@example.com}{the fees team} or \url{https://example.com/a_b}\footnote{Office hours only.}.
        Unknown \textcolor{red}{kept text} and \vspace{1em}\noindent{\bf grouped} words.

        Second \verb|\section{not a heading}| paragraph.\par Third paragraph.
        TEX),
    $read('latex: a title without maketitle is a heading only when there is no other heading', 'latex', "\\title{Only Title}\n\\begin{document}\nBody text.\n\\end{document}"),
    $read('latex: verbatim, lstlisting and minted keep their lines; display maths becomes code', 'latex', <<<'TEX'
        Before the code.
        \begin{verbatim}
        total = 5 % not a comment
          indented
        \end{verbatim}
        \begin{lstlisting}[language=Python]
        print("hi")
        \end{lstlisting}
        \begin{minted}[linenos]{php}
        echo $x;
        \end{minted}
        After the code
        \begin{align*}
          a &= b \label{eq:a} \\
          c &= d
        \end{align*}
        continues here, and \[ x^2 \] ends.
        TEX),
    $read('latex: itemize, enumerate with a labelled item, nested lists and description', 'latex', <<<'TEX'
        \begin{itemize}
          \item First point
          \item Second point
          \begin{enumerate}
            \item Nested one
            \item[(b)] Labelled
            \item Nested two
          \end{enumerate}
          \item Third point
          \begin{itemize}
            \item Inner bullet
          \end{itemize}
          \item Fourth point
        \end{itemize}
        \begin{description}
          \item[Fee] An amount charged.
          \item[Term]
        \end{description}
        TEX),
    $read('latex: a tabular in a table float takes the caption; rules dropped, multicolumn unwrapped', 'latex', <<<'TEX'
        \begin{table}[h]
        \centering
        \begin{tabular}{|l|r|}
        \hline
        \textbf{Service} & \textbf{Fee} \\ \hline
        Audit \& review & \pounds 200 \\
        \multicolumn{2}{c}{Prices exclude VAT} \\[2pt]
        \bottomrule
        \end{tabular}
        \caption{Standard fees}
        \label{tab:fees}
        \end{table}
        TEX),
    $read('latex: a longtable drops its repeated header and footer and reads its caption row', 'latex', <<<'TEX'
        \begin{longtable}{ll}
        \caption{Paper sizes} \\
        Size & Millimetres \\ \midrule
        \endfirsthead
        Size & Millimetres \\
        \endhead
        \multicolumn{2}{r}{continued} \\
        \endfoot
        A4 & 210 x 297 \\
        A5 & 148 x 210 \\
        \end{longtable}
        TEX),
    $read('latex: figure captions, dropped environments and a code block inside a list item', 'latex', <<<'TEX'
        \begin{figure}
          \includegraphics[width=\linewidth]{chart.png}
          \caption{Monthly totals}
        \end{figure}
        \begin{tikzpicture}
          \draw (0,0) -- (1,1);
        \end{tikzpicture}
        \begin{itemize}
        \item Run this:
        \begin{verbatim}
        make all
        \end{verbatim}
        then check the log.
        \item Done.
        \end{itemize}
        TEX),
    $read('latex: a paragraph of one-word leftover arguments is dropped; dimension arguments never become text', 'latex', <<<'TEX'
        Fees are due monthly.

        \pgfsetlayers{main}\mylayout{t}

        \gls{API} keys are \textcolor{red}{secret} \raisebox{1em}{and} \myskip{2.5cm}rotated\mywidth{0.5\linewidth}.

        \doccmd{sidenote[offset]{text}}
        TEX),
    $read('latex: siunitx and units quantities become number and unit symbols; fractions become a/b', 'latex', <<<'TEX'
        Gravity is \qty{9.81}{\meter\per\second\squared} and Avogadro is \num{6.022e23}.
        Energy is in \unit{\kilogram\meter\squared\per\second\squared}; a bag holds \SI[round-mode=places]{3}{\kilo\gram}.
        Water at \SI{20}{\degreeCelsius}, an area of \qty{5}{\square\centi\metre}, doses of \qtyrange{1}{5}{\milli\litre},
        \qty{3}{km/h}, \qty{2}{\furlong}, \unit[per-mode=symbol]{\metre\per\second} and $\qty{1}{\metre}$ in maths.
        \begin{tabular}{ll}
        Paper width & \unit[8\nicefrac{1}{2}]{inches} \\
        Gutter & \unit[\nicefrac{3}{8}]{inches} \\
        \end{tabular}
        TEX),
    $read('ipynb: markdown, code with stream, result and error outputs, raw cells, images dropped', 'ipynb', $notebook([
        ['cell_type' => 'markdown', 'metadata' => [], 'source' => ["# Analysis\n", "\n", "Load the data first."]],
        ['cell_type' => 'code', 'execution_count' => 1, 'metadata' => [], 'source' => ["import pandas as pd\n", "df = pd.read_csv('fees.csv')\n", "print(len(df))"], 'outputs' => [
            ['output_type' => 'stream', 'name' => 'stdout', 'text' => ["42\n"]],
        ]],
        ['cell_type' => 'code', 'execution_count' => 2, 'metadata' => [], 'source' => 'df.total.sum()', 'outputs' => [
            ['output_type' => 'execute_result', 'execution_count' => 2, 'metadata' => [], 'data' => ['text/plain' => ['1250.5'], 'text/html' => ['<b>1250.5</b>']]],
            ['output_type' => 'display_data', 'metadata' => [], 'data' => ['image/png' => 'iVBORw0KGgo=']],
        ]],
        ['cell_type' => 'code', 'execution_count' => 3, 'metadata' => [], 'source' => '1 / 0', 'outputs' => [
            ['output_type' => 'error', 'ename' => 'ZeroDivisionError', 'evalue' => 'division by zero', 'traceback' => ["\u{1B}[0;31m---\u{1B}[0m", 'ZeroDivisionError: division by zero']],
        ]],
        ['cell_type' => 'raw', 'metadata' => [], 'source' => "First raw paragraph\nwraps here.\n\nSecond raw paragraph."],
    ])),
    $read('ipynb: empty cells, outputs with only whitespace and colour codes, and unknown cell types', 'ipynb', $notebook([
        ['cell_type' => 'code', 'metadata' => [], 'source' => [], 'outputs' => []],
        ['cell_type' => 'code', 'metadata' => [], 'source' => 'run()', 'outputs' => [
            ['output_type' => 'stream', 'name' => 'stderr', 'text' => "\u{1B}[31mwarning:\u{1B}[0m slow  \n\n"],
            ['output_type' => 'stream', 'name' => 'stdout', 'text' => "   \n"],
        ]],
        ['cell_type' => 'heading', 'metadata' => [], 'source' => 'Old heading cell'],
        ['cell_type' => 'markdown', 'metadata' => [], 'source' => "| a | b |\n| - | - |\n| 1 | 2 |"],
        'not a cell',
    ])),
    $read('ipynb: invalid JSON is an error', 'ipynb', '{"cells": ['),
    $read('ipynb: JSON without a cells list is an error', 'ipynb', '{"cells": {"0": {}}}'),
    $read('ipynb: a JSON list is an error', 'ipynb', '[{"cell_type": "markdown", "source": "x"}]'),
    $read('vtt: header, NOTE, STYLE and REGION dropped; identifiers, settings and hours optional', 'vtt', <<<'VTT'
        WEBVTT - Board meeting
        Kind: captions

        NOTE recorded on 3 March
        by the clerk

        STYLE
        ::cue { color: yellow }

        REGION
        id:left width:40%

        intro
        00:01.000 --> 00:04.000 align:start position:10%
        Welcome to the board meeting.

        00:00:05.250 --> 00:00:08.000
        The minutes
        were approved.
        VTT),
    $read('vtt: voice spans give the speaker, several voices in one cue make one block each', 'vtt', <<<'VTT'
        WEBVTT

        00:00:10.000 --> 00:00:12.000
        <v.chair Ann Lee>We move to item four.</v>

        00:00:12.500 --> 00:00:15.000
        <v Ben>Agreed.</v><v Cara>Seconded.

        00:00:15.000 --> 00:00:16.000
        Before <v Ann Lee>Thank you</v> after
        VTT),
    $read('vtt: a leading Name: or Name (role): gives the speaker; tags, timestamps and entities', 'vtt', <<<'VTT'
        WEBVTT

        1
        00:01:00.000 --> 00:01:03.000
        Dr Okafor (treasurer): The budget is &lt;on track&gt; &amp; <i>under</i> spend.

        2
        00:01:03.000 --> 00:01:05.000
        <c.loud>MARIA:</c> Thanks,&nbsp;<00:01:04.000>noted&lrm;.

        3
        00:01:05.000 --> 00:01:07.000
        Note: a word from the stoplist is not a name, so this cue keeps the last speaker.

        4
        00:01:07.000 --> 00:01:09.000
        Step 1: digits are not part of a name.
        VTT),
    $read('vtt: dash lines and Name: lines split a cue into utterances', 'vtt', <<<'VTT'
        WEBVTT

        00:02:00.000 --> 00:02:04.000
        - Is it signed?
        - Not yet.

        00:02:04.000 --> 00:02:08.000
        JOHN: Then we wait.
        MARY: Until Friday.
        VTT),
    $read('vtt: a timing line without a blank line before it starts a new cue', 'vtt', "WEBVTT\n00:00.000 --> 00:01.000\nFirst cue.\n00:01.000 --> 00:02.000\nSecond cue.\n\nnot a cue\nno timing here"),
    $read('vtt: a file without the WEBVTT line is an error', 'vtt', "00:00.000 --> 00:01.000\nHello"),
    $read('vtt: a byte order mark and CRLF line endings are accepted', 'vtt', "\u{FEFF}WEBVTT\r\n\r\n00:00:01.000 --> 00:00:02.000\r\nHello there.\r\n"),
    $read('srt: index, timing and text; tags and position overrides stripped', 'srt', <<<'SRT'
        1
        00:00:01,000 --> 00:00:03,500
        <i>Previously on the show...</i>

        2
        00:00:04,000 --> 00:00:06,000
        {\an8}<font color="#ffff00">Signs read: OPEN</font>
        SRT),
    $read('srt: Name: speakers, dash lines and role annotations', 'srt', <<<'SRT'
        1
        00:10:00,000 --> 00:10:02,000
        ANNA: Where were you?

        2
        00:10:02,500 --> 00:10:05,000
        - At the office.
        - All night?

        3
        01:00:00,000 --> 01:00:03,000
        Inspector Jane Doe (narrator): It had rained
        since Tuesday.
        SRT),
    $read('srt: an unnamed cue keeps the last named speaker until a dash exchange clears it', 'srt', <<<'SRT'
        1
        00:00:01,000 --> 00:00:02,000
        Warden Hale: Lights out.

        2
        00:00:02,000 --> 00:00:04,000
        Everyone back to the cells.

        3
        00:00:04,000 --> 00:00:05,000
        - Now?
        - Now.

        4
        00:00:05,000 --> 00:00:06,000
        Nobody moved.
        SRT),
    $read('srt: a missing index, a blank line inside a cue and a BOM with CRLF', 'srt', "\u{FEFF}1\r\n00:00:01,000 --> 00:00:02,000\r\nFirst line\r\n\r\nstill the first cue\r\n\r\n00:00:03,000 --> 00:00:04,000\r\nNo index before me\r\n\r\n3\r\n00:00:05.000 --> 00:00:06.000\r\nDot as the separator\r\n"),
    $read('srt: text with no timing line gives no blocks', 'srt', "Just some text\nwith no cues."),
    $read('srt: an empty cue is dropped and a numeric line in the text is kept', 'srt', "1\n00:00:01,000 --> 00:00:02,000\n\n2\n00:00:02,000 --> 00:00:03,000\nThe answer is\n42\n"),
    $read('mdx: imports, exports, component-only lines and comments are dropped; front matter too', 'mdx', <<<'MDX'
        ---
        title: Fees
        ---
        import Callout from '../components/Callout'
        import {
          Tabs,
          TabItem,
        } from '@theme/Tabs'
        export const meta = { draft: false }

        # Fees

        {/* internal note: check with finance */}
        Invoices are issued monthly.

        <Callout type="warning">
        Late payment incurs interest.
        </Callout>

        <Chart data={totals} />
        MDX),
    $read('mdx: fenced code is kept as written, including imports and comments inside it', 'mdx', <<<'MDX'
        Example:

        ```jsx
        import Thing from './thing'
        {/* kept */}
        <Thing />
        ```

        ~~~~
        export default 1
        ~~~~
        MDX),
    $read('mdx: an mdx-code-block fence is unwrapped and read as MDX', 'mdx', <<<'MDX'
        ````mdx-code-block
        import Tabs from '@theme/Tabs';

        <Tabs>
          <TabItem value="a" label="A">First tab text.</TabItem>
        </Tabs>
        ````

        After.
        MDX),
    $read('mdx: a multi-line opening tag, a multi-line export and a comment across lines', 'mdx', <<<'MDX'
        <Tabs
          groupId="os"
          values={[{label: 'Mac', value: 'mac'}]}>
        <TabItem value="mac">

        Press Cmd+C.

        </TabItem>
        </Tabs>

        export default function Layout({ children }) {
          return <main>{children}</main>
        }

        Text {/* a comment
        spanning lines */} continues.

        Inline <Kbd>Ctrl</Kbd> stays as written.
        MDX),
    $read('mdx: lowercase HTML lines and fragments', 'mdx', "<>\n<div className=\"note\">\nKept as text.\n</div>\n</>\n\nimporter is not an import."),
    $read('mdx: admonitions keep their title and content; lowercase HTML tags are removed outside code spans', 'mdx', <<<'MDX'
        :::tip Learn Markdown

        You can learn it in ten minutes.

        :::

        :::note[Heads up]
        Content right after the marker.
        :::

        <details>
          <summary>Toggle me!</summary>

        Hidden <b>detail</b> text.<!-- truncate -->

        </details>

        Keep `<summary>` on one line.
        MDX),
];
