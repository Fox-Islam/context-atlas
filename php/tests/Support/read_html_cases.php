<?php

declare(strict_types=1);

/*
 * HTML reader cases: each rule of spec/formats/html.md, and markup a browser would repair.
 */

$html = static fn (string $name, string $text): array => ['name' => $name, 'kind' => 'read', 'input' => ['format' => 'html', 'text' => $text]];

return [
    $html(
        'html reads main and drops page furniture',
        "<!DOCTYPE html>\n<html><head><title>Ignored title</title><style>p{color:red}</style><script>var a = '<p>no</p>';</script></head>\n"
        . "<body><header><a href=\"/\">Home</a></header><nav><ul><li>Menu</li></ul></nav>\n"
        . "<div class=\"cookie\" role=\"dialog\" hidden><p>We use cookies</p></div>\n"
        . "<main><header><h1>Guide <a class=\"headerlink\" href=\"#guide\">¶</a></h1></header><!-- a comment <p>not read</p> -->\n"
        . "<p>Read this.</p><aside>Related links</aside><form><label>Search</label><input name=q><button>Go</button></form>\n"
        . "<p aria-hidden=\"true\">Decorative</p><svg><title>icon</title><text>svg text</text></svg><footer><p>Article footer</p></footer></main>\n"
        . "<footer><p>Site footer</p></footer></body></html>",
    ),
    $html(
        'html reads body when there is no main, and the title stands in for a missing h1',
        "<html><head><title>  Release   notes </title></head><body><div role=\"navigation\">Skip</div><header>Site header</header>\n"
        . "<article><header><h2>Version 2</h2></header><p>Faster.</p><footer>Posted Monday</footer></article><footer>Site footer</footer></body></html>",
    ),
    $html(
        'html role main is where reading starts',
        "<body><p>Outside</p><div role=\"MAIN\"><h2>Inside</h2><p>Kept</p></div><p>After</p></body>",
    ),
    $html(
        'html collapses whitespace and splits paragraphs at block elements',
        "<body><div>Loose text <b>bold</b><i>italic</i>\n   and&nbsp;more<br>after a break\n<p>A paragraph\n\tover lines.</p>tail text<span> inline</span>"
        . "<blockquote>Quoted</blockquote><dl><dt>Term</dt><dd>Its definition, longer than the term.</dd></dl><hr>last</div></body>",
    ),
    $html(
        'html lists become one paragraph of item lines',
        "<body><ul><li>First<li>Second <p>with a paragraph</p><ul><li>Nested one</li><li><ol start=\"3\"><li>Deep three</li><li>Deep four</li></ol></li></ul>"
        . "<li></li><li>  </li><li>Last</li></ul><ol><li>One</li><li>Two</li></ol><ol start=\"x\"><li>Bad start</li></ol></body>",
    ),
    $html(
        'html pre keeps its text as code',
        "<body><p>Example:</p><pre>\n<code class=\"lang-php\">if (\$a &lt; 2) {\n    echo &quot;<b>small</b>&quot;;\n}</code>\n\n</pre>"
        . "<pre>line one<br>line two</pre><pre>   \n</pre><p>Inline <code>code</code> stays in the paragraph.</p></body>",
    ),
    $html(
        'html table with caption, head, colspan and rowspan',
        "<body><table><caption>Prices <small>(GBP)</small></caption><thead><tr><th>Plan<th colspan=\"2\">Cost</tr></thead>"
        . "<tbody><tr><td rowspan=\"2\">Basic</td><td>10</td><td>monthly</td></tr><tr><td>100</td><td>yearly</td></tr>"
        . "<tr><td></td><td> </td><td></td></tr><tr><td>Pro</td><td><table><tr><td>20</td><td>or 200</td></tr></table></td><td><p>both</p></td></tr></tbody></table></body>",
    ),
    $html(
        'html table header comes from th rows only',
        "<body><table><tr><td>Name</td><td>Role</td></tr><tr><td>Ada</td><td>Engineer</td></tr></table>"
        . "<table><tr><th>Key</th><th>Value</th></tr><tr><th>Colour</th><td>Blue</td></tr></table><table><tr><td> </td></tr></table></body>",
    ),
    $html(
        'html character references',
        "<body><p>&amp; &lt;&gt; &quot;q&quot; &apos;a&apos; &copy; &eacute;&Eacute; &#233; &#xE9; &#X1F600; &#150; &#0; &#xD800; &#1114112; &#99999999999;</p>"
        . "<p>&amp without semicolon, &unknown; &AMP; &#; &#x; a&b</p><p title=\"&lt;\">&nbsp;</p><h2>&#8220;Quoted&#8221;</h2></body>",
    ),
    $html(
        'html drops hidden and interactive elements',
        "<body><p>Kept <span hidden>hidden span</span><span aria-hidden=\"TRUE \">aria</span><span aria-hidden=\"false\">shown</span></p>"
        . "<template><p>template</p></template><iframe src=\"x\">frame text</iframe><noscript><p>Enable JS</p></noscript>"
        . "<select><option>Choice</option></select><textarea>typed</textarea><div role=\"search\">search box</div><div popover>tip</div>"
        . "<video>fallback</video><p><a href=\"#s\">#</a><a href=\"/x\">¶</a></p></body>",
    ),
    $html(
        'html repairs unclosed and stray tags',
        "<body><p>One<p>Two<div>Block closes the paragraph</div></span>Stray end tag ignored</p></p>"
        . "<h2>Heading <h3>closes the heading</h3><ul><li>Item<li>Item two</ul>"
        . "<table><tr><td>a<td>b<tr><td>c<td>d</table><div><p>Unclosed at the end",
    ),
    $html(
        'html tokenizer edge cases',
        "<BODY><P CLASS=x>Upper case</P><p data-x=\"a > b\" title='it&apos;s' plain=yes>Quoted &gt; in attribute</p>"
        . "<p>1 < 2 and a <3 heart</p><div/>after self-closed div<br/>br<p>break</br>end</p><![CDATA[cdata]]><?php echo 1; ?>"
        . "<script>if (a</b) {}</script ><p>after script</p></ p>bogus end<p>tail <b",
    ),
    $html(
        'html speaker markup gives the paragraphs after it meta.speaker',
        "<main><h1>Debate</h1><div class=\"speech\"><div class=\"speech__speaker-and-content\"><h2 class=\"speech__speaker\"><strong class=\"speech__speaker__name\">Ann Lee</strong> <small>Minister</small></h2>"
        . "<p>First point.</p><p>Second point.</p></div><ul><li>Link to this speech</li></ul></div>"
        . "<div><span class=\"speaker\">Bob</span><p>Reply.</p><span itemprop=\"author\"> Cy  Dee </span><p>Answer.</p><span class=\"speaker\"> </span><p>Still Cy.</p></div>"
        . "<p><b class=\"Speaker\">Not a speaker class</b> because tokens are case-sensitive.</p><p>After the block, nobody.</p></main>",
    ),
    $html(
        'html drops furniture by class and keeps footnote asides',
        "<main><div class=\"mw-heading\"><h2>History</h2><span class=\"mw-editsection\">[edit]</span></div><a class=\"mw-jump-link\" href=\"#c\">Jump</a>"
        . "<p>Text.<sup class=\"noprint\">[citation needed]</sup></p><div class=\"navbox\">Related topics</div><div class=\"catlinks\">Categories</div>"
        . "<div class=\"printfooter\">Retrieved from</div><aside class=\"footnote-list\"><p>[1] A footnote.</p></aside><aside role=\"note\">A note.</aside><aside>Sidebar</aside></main>",
    ),
    $html(
        'html reads through a form that wraps content',
        "<body><form id=\"page\"><h1>Wrapped</h1><p>Content inside a page-wide form.</p><label>Name</label><input name=n><button>Send</button></form>"
        . "<form><label>Search</label><input name=q></form></body>",
    ),
    $html(
        'html table title row becomes the caption',
        "<body><table><tr><th colspan=\"2\">Plants</th></tr><tr><th>Name</th><th>Colour</th></tr><tr><td>Dandelion</td><td>Yellow</td></tr></table>"
        . "<table><caption>Own caption</caption><tr><td colspan=\"2\">Wide row</td></tr><tr><td>a</td><td>b</td></tr></table>"
        . "<table><tr><td colspan=\"2\">Not wide enough</td></tr><tr><td>a</td><td>b</td><td>c</td></tr></table></body>",
    ),
    $html(
        'html pre and tables inside list items split the list',
        "<body><ol><li>Install:<pre>npm install x</pre>then restart.</li><li>Check<table><tr><th>Key</th><th>Value</th></tr><tr><td>a</td><td>1</td></tr></table></li><li>Done</li></ol></body>",
    ),
    $html(
        'html drops elements hidden by inline style or utility class',
        "<body><p>Shown <span style=\"DISPLAY : None ;color:red\">styled away</span><span style=\"visibility:hidden!important\">invisible</span><span style=\"display:inline\">inline</span></p>"
        . "<p class=\"d-none\">Bootstrap hidden</p><p class=\"hidden md:block\">Tailwind shown on desktop</p><p class=\"d-none d-lg-block\">Bootstrap shown on large</p>"
        . "<p class=\"print-only\">Printed only</p><p><span class=\"sr-only\">Screen reader</span> <span class=\"visually-hidden\">text is kept</span></p></body>",
    ),
    $html(
        'html drops a row of short links repeated three times',
        "<main><div><p>First speech.</p><ul><li>Link to this <a href=\"/1\">speech</a></li><li>(Citation: col 1)</li></ul></div>"
        . "<div><p>Second speech.</p><ul><li>Link to this <a href=\"/2\">speech</a></li><li>(Citation: col 2)</li></ul></div>"
        . "<div><p>Third speech.</p><ul><li>Link to this <a href=\"/3\">speech</a></li><li>(Citation: col 3)</li></ul></div>"
        . "<h2>See also</h2><ul><li><a href=\"/a\">Alpha</a></li></ul><ul><li><a href=\"/a\">Alpha</a></li></ul>"
        . "<p><a href=\"#f1\">[1]</a></p><p><a href=\"#f2\">[2]</a></p><p><a href=\"#f3\">[3]</a></p>"
        . "<p>A <a href=\"/x\">link in a sentence</a>.</p><p>A <a href=\"/x\">link in a sentence</a>.</p><p>A <a href=\"/x\">link in a sentence</a>.</p></main>",
    ),
    $html(
        'html keeps button text in headings and reads scripts, maths and role headings',
        "<body><h2 class=\"accordion__title\"><button aria-expanded=\"false\">Who is entitled?</button></h2><details><summary><button>More</button></summary><p>Detail.</p></details>"
        . "<dl><dt><button>Term</button></dt><dd>Defined <button>Copy</button></dd></dl>"
        . "<p>Each <span class=\"tip\">month<span role=\"tooltip\">: definition</span></span> counts.</p>"
        . "<p>10<sup>23</sup> atoms, H<sub>2</sub>O, x<sup>n</sup>, 1<sup>st</sup>, W3C<sup>®</sup>, a claim<sup><a href=\"#n1\">1</a></sup>, ( <sup>2</sup>).</p>"
        . "<p>Speed <span style=\"display:none\"><math alttext=\"{\\displaystyle c}\"><mi>c</mi></math></span><img alt=\"c\" aria-hidden=\"true\"> and <math><mi>E</mi><mo>=</mo><mi>m</mi><annotation encoding=\"application/x-tex\">E=m</annotation></math>.</p>"
        . "<div role=\"heading\" aria-level=\"3\">Role heading</div><div role=\"heading\" aria-level=\"9\">Default level</div></body>",
    ),
    $html(
        'html enumerations in a dl become list lines, and multilingual speaker classes',
        "<body><p>Zweck des Gesetzes ist es,</p><dl><dt>1.</dt><dd><div>die Sicherheit zu gewährleisten,</div><dl><dt>a)</dt><dd>erstens</dd><dt>b)</dt><dd>zweitens</dd></dl></dd><dt>2.</dt><dd>den Sonntag zu schützen.</dd></dl>"
        . "<dl><dt>Term</dt><dd>A glossary stays as paragraphs.</dd></dl>"
        . "<div class=\"intervenant\"><p><span class=\"orateur_nom\">M. le président.</span> - L'ordre du jour appelle le vote.</p><p>Second paragraph of the speech.</p></div>"
        . "<div class=\"intervenant\"><p><span class=\"orateur_nom\">Mme Dupont</span>, rapporteure. - Merci.</p></div><p>Nobody speaks here.</p></body>",
    ),
    $html(
        'html without any markup is one paragraph',
        "Just text,\r\nno tags &amp; no body.",
    ),
];
