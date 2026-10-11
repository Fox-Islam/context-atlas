<?php

declare(strict_types=1);

/*
 * Reader fixtures for reStructuredText, AsciiDoc and Org: each case is a small document that
 * exercises the rules of one section of spec/formats/rst.md, asciidoc.md or org.md.
 */

$read = static fn (string $name, string $format, string $text): array => ['name' => $name, 'kind' => 'read', 'input' => ['format' => $format, 'text' => $text]];

return [
    $read('rst: section titles take their level from the order their adornment style first appears; an underline of 4 or more may be shorter than its title', 'rst', <<<'RST'
        =========
        The Title
        =========

        Intro under the title.

        Install
        -------

        Text.

        On Linux
        ~~~~~~~~

        Deeper.

        Usage
        -----

        A title longer than its underline
        ----

        Short
        ---

        Too short
        ---
        RST),
    $read('rst: a trailing :: opens an indented literal block in its three spellings', 'rst', <<<'RST'
        Run this::

            pip install atlas
              --upgrade

        Expanded form ::

           make

        ::

           just code

        Back to text.
        RST),
    $read('rst: bullet, enumerated, field, definition and line-block lists', 'rst', <<<'RST'
        * first item
          wraps here
        * second item

          more of the second

          - nested item

        1. one
        #. two

        :Author: Ada
        :Version: 2

        term
           Its definition.
        other term : classifier
           Another definition.

        | line one
        | line two
        RST),
    $read('rst: directives keep their content and drop options and most arguments', 'rst', <<<'RST'
        .. code-block:: python
           :linenos:

           print("hi")

        .. note:: Mind the gap
           between trains.

        .. admonition:: Custom title

           Admonition body.

        .. versionchanged:: 2.0
           The default changed.

        .. image:: diagram.png
           :alt: A diagram

        .. py:function:: connect(host, port)

           Opens a connection.

        .. toctree::
           :maxdepth: 2

           intro
        RST),
    $read('rst: comments, targets and substitutions are dropped; footnotes stay', 'rst', <<<'RST'
        Para with a link_.

        .. _link: https://example.com
        .. |sub| replace:: substituted
        .. This is a comment
           that runs on.
        __ https://example.com/anon

        .. [1] A footnote
           on two lines.

        ..

           A block quote after an empty comment.
        RST),
    $read('rst: a simple table with a header and a continuation line', 'rst', <<<'RST'
        =====  ======  ==========
        Name   Price   Notes
        =====  ======  ==========
        Apple  1.00    Red or
                       green
        Pear   2.00
        Fig    -       Seasonal
        =====  ======  ==========

        After the table.
        RST),
    $read('rst: a grid table with a header row, a multi-line cell and a column span', 'rst', <<<'RST'
        +--------+--------+--------+
        | Header | Second | Third  |
        +========+========+========+
        | a      | b      | c      |
        | a2     |        |        |
        +--------+--------+--------+
        | spans two       | d      |
        +-----------------+--------+
        RST),
    $read('rst: table, list-table and csv-table directives give captions and header rows', 'rst', <<<'RST'
        .. table:: Fruit prices

           =====  =====
           Fruit  Price
           =====  =====
           Apple  1
           =====  =====

        .. list-table:: Treats
           :header-rows: 1

           * - Treat
             - Quantity
           * - Albatross
             - 2.99
             with sauce

        .. csv-table:: Ratings
           :header: "Name", "Score"

           "Smith, J", 9
           Lee, 7
        RST),
    $read('rst: doctest blocks, transitions and tabs', 'rst', "Start.\n\n>>> 1 + 1\n2\n\n----------\n\n*\tTabbed item\n\tcontinued\n"),
    $read('asciidoc: the document title, its header lines, section levels and two-line titles', 'asciidoc', <<<'ADOC'
        = Guide Title
        Ada Lovelace <ada@example.com>
        v1.0, 2024-01-01
        :toc: left

        Preamble.

        == Section ==

        === Subsection

        # Markdown heading

        Two Line Title
        --------------

        Text under it.

        Not a title because the underline is far too long
        -------
        ADOC),
    $read('asciidoc: attribute entries, block attributes, comments and block macros are dropped', 'asciidoc', <<<'ADOC'
        :name: value
        :long: first \
        second

        // a line comment
        ////
        a block comment
        ////

        [[anchor]]
        [.lead]
        Lead paragraph
        // inside comment
        still the lead.

        image::diagram.png[Diagram]
        include::other.adoc[]
        ifdef::env-github[]
        Shown on GitHub.
        endif::[]

        '''

        After the break.
        ADOC),
    $read('asciidoc: listing, literal and source paragraphs are code; compound blocks are read inside', 'asciidoc', <<<'ADOC'
        .Hello example
        [source,ruby]
        ----
        puts "hello"
        ----

        ....
        literal  text
        ....

        [source,python]
        print("one")
        print("two")

         indented literal
           paragraph

        ++++
        <div>raw</div>
        ++++

        [quote, Someone]
        ____
        A quotation.
        ____

        [NOTE]
        ====
        Example content.
        ====

        ****
        Sidebar content.
        ****

        --
        Open block content.
        --
        ADOC),
    $read('asciidoc: unordered, ordered, description and callout lists', 'asciidoc', <<<'ADOC'
        * apples
        ** green
        continued line
        * pears

        * after a blank, same list

        . step one
        . step two
        +
        attached to step two

        CPU:: The brain.
        RAM::
          Short-term memory.

        <1> First callout.
        <2> Second callout.
        ADOC),
    $read('asciidoc: a table with an implicit header row, cols, spans and a title caption', 'asciidoc', <<<'ADOC'
        .Price list
        [cols="2,1,1"]
        |===
        |Name |Price |Stock

        |Apple |1.00 |12
        |Pear
        |2.00
        |4
        2+|Total |16
        3*|x
        |===
        ADOC),
    $read('asciidoc: header option, noheader, CSV and DSV tables', 'asciidoc', <<<'ADOC'
        [%header]
        |===
        |Key |Value
        |a |1
        |===

        [options="noheader"]
        |===
        |not |header

        |row |two
        |===

        ,===
        Name,"Score, final"

        Ada,9
        ,===

        :===
        k:v
        x:y
        :===
        ADOC),
    $read('asciidoc: attribute references, cross references, links and includes', 'asciidoc', <<<'ADOC'
        = Guide
        :url-docs: https://docs.example.com
        :product: Atlas

        :tagline: {product} reads \
        AsciiDoc

        {tagline}; see {url-docs}[the docs] or {undefined}.
        Read xref:install.adoc[Installing], xref:usage.adoc[] and <<setup, the setup section>>.
        Mail mailto:team@example.com[us] or visit link:/faq.html[the FAQ] and https://example.com[].

        :product!:
        {product} is unset again.

        include::partials/intro.adoc[]
        include-code::example[tag=main]

        |===
        |{tagline} |xref:a.adoc[A]
        |===
        ADOC),
    $read('asciidoc: built-in character attributes, overridden or unset by the document', 'asciidoc', <<<'ADOC'
        Use {cpp}, {asterisk}{sp}and {startsb}x{endsb}{two-colons} it is 20{deg}C {ldquo}warm{rdquo}{empty}.

        :asterisk: star
        :vbar!:
        Now {asterisk} and {vbar}.
        ADOC),
    $read('org: a title shifts heading levels; keywords, priorities, tags and cookies leave headings', 'org', <<<'ORG'
        #+TITLE: Project Notes
        #+AUTHOR: Ada
        #+TODO: TODO NEXT(n) | DONE CANCELLED(c)

        Intro.

        * TODO [#A] Plan the release [1/3] :work:urgent:
        Body.
        ** NEXT Write docs [50%]
        ** CANCELLED Old idea
        * DONE Shipped
        * Notes about TODO lists :meta:
        ORG),
    $read('org: without a title heading levels are the star counts; COMMENT subtrees are dropped', 'org', <<<'ORG'
        * One
        Text one.
        * COMMENT Draft
        Hidden text.
        ** Hidden child
        * Two
        *** Deep
        ORG),
    $read('org: source and example blocks are code; quote and special blocks are read inside', 'org', <<<'ORG'
        #+BEGIN_SRC python :results output
          def f():
              return 1
          ,* escaped star
        #+END_SRC

        #+begin_example
        example text
        #+end_example

        #+BEGIN_QUOTE
        Quoted
        text.
        #+END_QUOTE

        #+BEGIN_EXPORT html
        <b>raw</b>
        #+END_EXPORT

        #+BEGIN_NOTE
        A special block.
        #+END_NOTE

        #+BEGIN_SRC sh
        never closed
        ORG),
    $read('org: drawers, planning lines, comments and keywords are dropped; fixed-width lines are code', 'org', <<<'ORG'
        * Task
          DEADLINE: <2024-02-01 Thu> SCHEDULED: <2024-01-20 Sat>
          :PROPERTIES:
          :ID: 42
          :END:
          :LOGBOOK:
          CLOCK: [2024-01-20 Sat 10:00]
          :END:
        Body text
        # a comment line
        continues.

        :NOTADRAWER:
        stays as text.

        #+NAME: example
        : fixed width
        :   keeps indentation
        ORG),
    $read('org: plain lists, their continuation lines and kinds', 'org', <<<'ORG'
        - first
          wraps
        - second
          1. nested

        - after a blank
        + plus bullet

        1. one
        2) two

          * indented star item

        - term :: description
        Paragraph after.
        ORG),
    $read('org: tables take a header from rows above the first rule and a caption from #+CAPTION', 'org', <<<'ORG'
        #+CAPTION: Prices
        | Name  | Price |
        |-------+-------|
        | Apple |  1.00 |
        | Pear  |  2.00 |

        |-------+-----|
        | <l>   | <5> |
        | a     | b   |
        |-------+-----|
        | c     | d   |
        |-------+-----|

        | x | y |
        | 1 | 2 |
        #+TBLFM: $2=$1

        -----

        [fn:1] A footnote definition.
        ORG),
];
