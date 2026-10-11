<?php

declare(strict_types=1);

/*
 * Markup fixtures for XML, TOML and JSON Lines: re-printed XML (minified and pretty files cut the
 * same), labels from attributes and child elements, id references as links, the prolog, doctype,
 * comments, CDATA and entities; TOML tables, tables with no written parent, a sub-table written
 * after other tables, arrays of tables and their sub-tables, strings of every kind, multi-line
 * arrays, own lines packed past the size limit, and a reopened table; JSONL records as items, a
 * large record cut into children, and blank and CRLF lines; items named by a key ending in name,
 * id, title or key, or by their first short one-line value, preferring a value no sibling shares.
 */

$long = static fn (string $what): string => str_repeat("{$what} is described at length so that this subtree passes the size limit. ", 6);

$beans = '<beans>'
    . '<bean id="dataSource" class="org.example.Pool"><property name="url" value="jdbc:mysql://db/app"/><description>' . $long('The pool') . '</description></bean>'
    . '<bean id="repository" class="org.example.Repository"><property name="dataSource" ref="dataSource"/><description>' . $long('The repository') . '</description></bean>'
    . '<bean name="service" class="org.example.Service"><property name="repository" idref="repository"/><description>' . $long('The service') . '</description></bean>'
    . '<alias name="dataSource" alias="db"/>'
    . '</beans>';

$pretty = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
    . "<!DOCTYPE catalog [\n  <!ENTITY vendor \"Acme\">\n  <!-- a comment with > inside -->\n]>\n"
    . "<!-- the catalog -->\n"
    . "<catalog xmlns:xlink=\"http://www.w3.org/1999/xlink\">\n"
    . "  <product xml:id=\"p1\">\n    <name>Hammer &amp; tongs</name>\n    <price currency='GBP'>12.50</price>\n"
    . "    <summary><![CDATA[Forged <steel> head]]>, &lt;hickory&gt; handle &#x2014; &vendor; made</summary>\n"
    . "    <notes>\n      " . $long('The hammer') . $long('The head') . "\n    </notes>\n  </product>\n"
    . "  <product xml:id=\"p2\">\n    <name>Nails</name>\n    <?render inline?>\n"
    . "    <notes>Sold in boxes of <b>100</b> or <i>500</i>; " . $long('The nails') . $long('The box') . "</notes>\n"
    . "    <related xlink:href=\"#p1\"/>\n  </product>\n"
    . "  <bundle href=\"#p2\">Starter kit</bundle>\n"
    . "</catalog>\n";

$sitemap = "<urlset>\n" . implode('', array_map(static fn (int $i): string => "  <url>\n    <loc>https://example.com/page-{$i}</loc>\n    <lastmod>2026-10-0{$i}</lastmod>\n    <changefreq>weekly</changefreq>\n  </url>\n", range(1, 3))) . "</urlset>\n";

$note = static fn (string $what): string => 'notes = "' . $long($what) . "\"\n";

$cargo = "# Package manifest\n[package]\nname = \"ripline\"\nversion = \"14.1.0\"\ndescription = \"\"\"\nSearch files \\\n  line by line.\"\"\"\nkeywords = [\"regex\", \"grep\",\n  # a comment with ] inside\n  \"search\"]\n"
    . "metadata.docs.rs = { all-features = true }\nmetadata.release = 'cargo release'\n" . $note('The package') . "\n"
    . "[[bin]]\nname = \"rl\"\npath = \"src/main.rs\"\n" . $note('The main binary') . "\n"
    . "[[bin]]\nname = \"rl-index\" # second binary\npath = \"src/index.rs\"\n" . $note('The index binary') . "\n"
    . "[dependencies]\nserde = { version = \"1.0\", features = [\"derive\"] }\nregex = \"1.10\"\n"
    . "\"quoted.key\" = 'C:\\tools'\n" . $note('The dependency list') . "\n"
    . "[target.'cfg(windows)'.dependencies]\nwinapi = \"0.3\"\n\n"
    . "[profile.release]\nlto = true\ndebug = 1\n";

$pyproject = "[build-system]\nrequires = [\"hatchling\"]\n\n"
    . "[project]\nname = \"pyline\"\nreadme = 'README.md'\nclassifiers = [\n" . implode('', array_map(static fn (int $i): string => "    \"Programming Language :: Python :: 3.1{$i}\",\n", range(0, 9))) . "]\n\n"
    . "[tool.hatch.version]\npath = 'pyline/version.py'\n\n[tool.hatch.build]\ninclude = ['/pyline']\n\n"
    . "[tool.ruff]\nline-length = 120\n\n[tool.ruff.lint]\nselect = [\n" . implode('', array_map(static fn (string $rule): string => "    '{$rule}',  # " . $long("Rule {$rule}") . "\n", ['E', 'F', 'I'])) . "]\n\n"
    . "# coverage settings\n[tool.coverage.run]\nbranch = true\n" . $note('Coverage');

$names = [
    "\"tab\\there \\\"quoted\\\" \\u00e9 \\U0001F600 back\\\\slash\"",
    "'C:\\Users\\nobody'",
    "\"\"\"\nfirst line \\\n    continued\"\"\"",
    "'''\n\\d+ stays as written'''",
    "\"\"\"two \"\"quotes\"\" inside\"\"\"\"\"",
    "\"keeps \\q as written\"",
    '1_000 # a comment',
];
$strings = implode("\n", array_map(static fn (string $name, int $i): string => "[[entry]]\nname = {$name}\n" . $note("Entry {$i}"), $names, array_keys($names)))
    . "\n[\"key with spaces\".'literal.key']\nvalue = true\n" . $note('The quoted table');

$fruits = "[[fruits]]\nname = \"apple\"\n" . $note('The apple') . "\n[fruits.physical]\ncolor = \"red\"\nshape = \"round\"\n\n"
    . "[[fruits.varieties]]\nname = \"red delicious\"\n" . $note('Red delicious') . "\n"
    . "[[fruits.varieties]]\nname = \"granny smith\"\n" . $note('Granny smith') . "\n"
    . "[[fruits]]\nname = \"banana\"\n" . $note('The banana') . "\n"
    . "[[fruits.varieties]]\nname = \"plantain\"\n" . $note('Plantain');

$late = "[package]\nname = \"late\"\n" . $note('The package') . "\n[profile.release]\nlto = true\n" . $note('The release profile')
    . "\n[package.metadata.small]\nflag = true\n\n[package.metadata.deb]\nmaintainer = \"Ann\"\n" . $note('The Debian package');

$packed = "# Site settings, one small table each\ntitle = \"Docs\"\n\n" . implode("\n", array_map(static fn (int $i): string => "[[cascade]]\nname = \"section {$i}\"\ndescription = \"Section {$i} is described in two sentences. It is small enough to stay with its neighbours.\"\nweight = {$i}\n", range(1, 14)));

$reopened = "[a]\nx = 1\n" . $note('Table a') . "\n[b]\ny = 2\n" . $note('Table b') . "\n[c]\n" . $note('Table c')
    . "\n# a again\n[a]\nz = 3\n" . $note('Table a again');

$records = implode("\n", [
    '{"id": "u1", "name": "Ada", "role": "admin"}',
    '',
    '{"id": "u2", "name": "Grace", "teams": ["compilers", "navy"]}',
    '  ',
    '{"name": "Linus", "bio": ' . json_encode($long('Linus')) . ', "history": ' . json_encode(array_map(static fn (int $i): array => ['year' => 1990 + $i, 'event' => $long("Event {$i}")], range(1, 3))) . '}',
]) . "\n";

$fallback = implode("\n", array_map(static fn (array $record): string => (string) json_encode($record, JSON_UNESCAPED_UNICODE), [
    ['userId' => 'u-7', 'bio' => $long('User seven')],
    ['tags' => ['faq'], 'question' => 'How many eggs does Janet sell at the farmers market each day, after breakfast and baking?', 'notes' => $long('The question')],
    ['tags' => ['faq'], 'city' => 'Leeds', 'notes' => $long('Leeds')],
    ['tags' => ['faq'], 'notes' => "two\nlines " . $long('No label')],
    ['kind' => 'faq', 'topic' => 'Billing', 'notes' => $long('Billing')],
    ['kind' => 'faq', 'topic' => 'Refunds', 'notes' => $long('Refunds')],
])) . "\n";

$plugins = '<plugins>' . implode('', array_map(static fn (string $artifact): string => '<plugin><groupId>org.apache.maven.plugins</groupId><artifactId>' . $artifact . '</artifactId><configuration>' . $long($artifact) . '</configuration></plugin>', ['maven-jar-plugin', 'maven-surefire-plugin', 'maven-javadoc-plugin', 'maven-site-plugin'])) . '</plugins>';

$manifest = '<manifest><application>' . implode('', array_map(static fn (string $name): string => '<activity android:exported="true" android:name="' . $name . '"><meta-data android:value="' . $long($name) . $long('Its meta-data') . '"/></activity>', ['.Main', '.Settings', '.Search', '.About'])) . '</application></manifest>';

$chat = implode("\n", [
    '{"messages": [{"role": "user", "content": "Summarise the attached report."}]}',
    (string) json_encode(['messages' => [['role' => 'user', 'content' => 'And the long one?'], ['role' => 'assistant', 'content' => str_repeat('A sentence of the long reply, with \\"escapes\\" and tabs\t. ', 450)]]]),
    '{"messages": [{"role": "user", "content": "Thanks."}]}',
]) . "\n";

$scalars = "1\r\n\"two\"\r\n[]\r\n{}\r\nnull\r\n[1, {\"a\": true}]\r\n";

return [
    ['name' => 'print minified XML in one layout, label items by attribute, and link ref and idref to ids', 'kind' => 'markup-ingest', 'input' => ['id' => 'beans', 'markup' => $beans, 'format' => 'xml', 'title' => 'Beans']],
    ['name' => 'skip the XML prolog, doctype, comments and instructions; decode entities and CDATA; link href and xlink:href', 'kind' => 'markup-ingest', 'input' => ['id' => 'catalog', 'markup' => $pretty, 'format' => 'xml', 'title' => 'Catalog']],
    ['name' => 'an XML file within the size limit is one unit', 'kind' => 'markup-ingest', 'input' => ['id' => 'sitemap', 'markup' => $sitemap, 'format' => 'xml']],
    ['name' => 'read an XML root named html as XML', 'kind' => 'markup-ingest', 'input' => ['id' => 'page', 'markup' => '<html><head><title>T</title></head><body><p id="a">One</p><p>Two <a href="#a">back</a></p></body></html>', 'format' => 'xml']],
    ['name' => 'read TOML tables, arrays of tables, dotted keys, inline tables and multi-line arrays', 'kind' => 'markup-ingest', 'input' => ['id' => 'cargo', 'markup' => $cargo, 'format' => 'toml', 'title' => 'Cargo.toml']],
    ['name' => 'hang a table under its nearest written ancestor and keep comment lines with the table below them', 'kind' => 'markup-ingest', 'input' => ['id' => 'pyproject', 'markup' => $pyproject, 'format' => 'toml']],
    ['name' => 'unescape TOML basic strings and keep literal strings as written', 'kind' => 'markup-ingest', 'input' => ['id' => 'strings', 'markup' => $strings, 'format' => 'toml']],
    ['name' => 'put TOML sub-tables and nested arrays of tables under the last item', 'kind' => 'markup-ingest', 'input' => ['id' => 'fruits', 'markup' => $fruits, 'format' => 'toml']],
    ['name' => 'a sub-table written after other tables hangs under its table; a big one is cut on its own', 'kind' => 'markup-ingest', 'input' => ['id' => 'late', 'markup' => $late, 'format' => 'toml']],
    ['name' => 'own lines past the size limit are packed at child boundaries, labelled by their first and last child', 'kind' => 'markup-ingest', 'input' => ['id' => 'packed', 'markup' => $packed, 'format' => 'toml']],
    ['name' => 'a reopened TOML table is cut once, from its last node', 'kind' => 'markup-ingest', 'input' => ['id' => 'reopened', 'markup' => $reopened, 'format' => 'toml']],
    ['name' => 'read JSONL records as items labelled by name or id, skipping blank lines', 'kind' => 'markup-ingest', 'input' => ['id' => 'people', 'markup' => $records, 'format' => 'jsonl', 'title' => 'People']],
    ['name' => 'name an item by a key ending in name, id, title or key, else by its first short one-line value', 'kind' => 'markup-ingest', 'input' => ['id' => 'fallback', 'markup' => $fallback, 'format' => 'jsonl']],
    ['name' => 'prefer a naming field whose value no same-name sibling shares', 'kind' => 'markup-ingest', 'input' => ['id' => 'plugins', 'markup' => $plugins, 'format' => 'xml']],
    ['name' => 'name an XML item by an attribute ending in name', 'kind' => 'markup-ingest', 'input' => ['id' => 'manifest', 'markup' => $manifest, 'format' => 'xml']],
    ['name' => 'read a JSONL record holding a string of 27,000 characters, keeping records in order', 'kind' => 'markup-ingest', 'input' => ['id' => 'chat', 'markup' => $chat, 'format' => 'jsonl']],
    ['name' => 'read JSONL scalars and containers with CRLF line ends', 'kind' => 'markup-ingest', 'input' => ['id' => 'scalars', 'markup' => $scalars, 'format' => 'jsonl']],
    ['name' => 'an empty JSONL file is one empty unit', 'kind' => 'markup-ingest', 'input' => ['id' => 'empty', 'markup' => "\n\n", 'format' => 'jsonl']],
];
