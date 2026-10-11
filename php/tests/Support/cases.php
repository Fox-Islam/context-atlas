<?php

declare(strict_types=1);

/*
 * The fixture cases. Thresholds are shrunk in each case's config so the texts
 * stay readable; behaviour is the same at any scale.
 */

$fees = 'Invoices are issued on the first working day of each month. Payment is due within fourteen days by bank transfer. ';
$late = 'Late payments incur interest of two percent per month on the outstanding balance until it is paid in full. ';
$cancel = 'Either party may end this agreement by giving thirty days written notice to the other party at any time. ';
$data = 'We process personal data only on your documented instructions and never sell it to anyone for any purpose. ';
$example = 'For example, an invoice of one thousand pounds paid a month late carries twenty pounds of interest on top. ';

$tabular = require __DIR__ . '/tabular_cases.php';
$prose = require __DIR__ . '/prose_cases.php';
$reference = require __DIR__ . '/reference_cases.php';
$conversation = require __DIR__ . '/conversation_cases.php';
$transcript = require __DIR__ . '/transcript_cases.php';
$markup = require __DIR__ . '/markup_cases.php';
$markupFormats = require __DIR__ . '/markup_formats_cases.php';
$readHtml = require __DIR__ . '/read_html_cases.php';
$readTextMarkup = require __DIR__ . '/read_text_markup_cases.php';
$readMisc = require __DIR__ . '/read_misc_cases.php';
$readText = require __DIR__ . '/read_text_cases.php';
$readDocx = require __DIR__ . '/read_docx_cases.php';
$readChat = require __DIR__ . '/read_chat_cases.php';

$small = ['max_tokens' => 120, 'min_tokens' => 40, 'overlap_tokens' => 12, 'topic_min_tokens' => 20, 'split_long_paragraph_at' => 150];

return [
    [
        'name' => 'markdown structure without Jev',
        'kind' => 'chunk',
        'format' => 'markdown',
        'jev' => false,
        'input' => "---\ntitle: Guide\n---\n# Service guide\n\nWe build and host websites.\n\n### Skipped level\n\nA heading that skips a level sits under its parent.\n\nSetext heading\n--------------\n\n- first item\n- second item\ncontinued\n\n> A quoted line\n> and its second line.\n\n| Plan | Price |\n|------|-------|\n| Basic | 10 |\n\n```js\nconst a = 1;\n\nconst b = 2;\n```\n\n***\n\nClosing words #\n\n## Trailing hashes ##\n\nLast paragraph.",
    ],
    [
        'name' => 'plain text paragraphs',
        'kind' => 'chunk',
        'format' => 'text',
        'jev' => false,
        'input' => "First paragraph\nwraps onto a second line.\n\n   \n\nSecond paragraph.\r\n\r\nThird one.",
    ],
    [
        'name' => 'packing by size with overlap inside a section',
        'kind' => 'chunk',
        'format' => 'markdown',
        'jev' => false,
        'config' => $small,
        'input' => "# Terms\n\n" . $fees . $late . "\n\n" . $cancel . $data . "\n\n" . $example . $fees . "\n\n" . $late . "\n\n# Other\n\nShort.",
    ],
    [
        'name' => 'long paragraph split by sentences without Jev',
        'kind' => 'chunk',
        'format' => 'text',
        'jev' => false,
        'config' => $small,
        'input' => str_repeat($fees, 4) . str_repeat($cancel, 3),
    ],
    [
        'name' => 'a section cut where Jev finds a topic change',
        'kind' => 'chunk',
        'format' => 'markdown',
        'config' => array_replace($small, ['max_tokens' => 400]),
        'input' => "# Terms\n\n" . $fees . "\n\n" . $late . "\n\n" . $cancel . "\n\n" . $data,
        'answers' => ['p_b2' => 0.9, 'r_b2' => 0.3, 'p_b3' => 0.08, 'r_b3' => 0.05, 'p_b4' => 0.2, 'r_b4' => 0.1],
    ],
    [
        'name' => 'never cut where the second passage depends on the first',
        'kind' => 'chunk',
        'format' => 'markdown',
        'config' => array_replace($small, ['max_tokens' => 400]),
        'input' => "# Terms\n\n" . $fees . "\n\n" . $late . "\n\n" . $example . "\n\n" . $cancel,
        'answers' => ['p_b2' => 0.9, 'r_b2' => 0.2, 'p_b3' => 0.2, 'r_b3' => 0.85, 'p_b4' => 0.1, 'r_b4' => 0.1],
    ],
    [
        'name' => 'the cheapest set of cuts, not each cut that looks cheap',
        'kind' => 'chunk',
        'format' => 'blocks',
        'config' => array_replace($small, ['max_tokens' => 400, 'topic_min_tokens' => 60]),
        'input' => [
            ['type' => 'paragraph', 'text' => $fees . $late . $late],
            ['type' => 'paragraph', 'text' => $late],
            ['type' => 'paragraph', 'text' => $cancel . $data . $cancel . $data . $cancel],
        ],
        'answers' => ['p_b1' => 0.3, 'r_b1' => 0.1, 'p_b2' => 0.05, 'r_b2' => 0.05],
    ],
    [
        'name' => 'a long paragraph cut at a sentence where the topic changes',
        'kind' => 'chunk',
        'format' => 'text',
        'config' => array_replace($small, ['max_tokens' => 400]),
        'input' => str_repeat($fees, 3) . str_repeat($cancel, 3),
        'answers' => ['p_b0s1' => 0.9, 'r_b0s1' => 0.4, 'p_b0s2' => 0.9, 'r_b0s2' => 0.4, 'p_b0s3' => 0.9, 'r_b0s3' => 0.4, 'p_b0s4' => 0.9, 'r_b0s4' => 0.4, 'p_b0s5' => 0.9, 'r_b0s5' => 0.4, 'p_b0s6' => 0.1, 'r_b0s6' => 0.05, 'p_b0s7' => 0.9, 'r_b0s7' => 0.4, 'p_b0s8' => 0.9, 'r_b0s8' => 0.4],
    ],
    [
        'name' => 'seams asked in batches',
        'kind' => 'chunk',
        'format' => 'markdown',
        'config' => array_replace($small, ['max_tokens' => 400, 'batch_size' => 2]),
        'input' => "# Terms\n\n" . $fees . "\n\n" . $late . "\n\n" . $cancel . "\n\n" . $data,
        'answers' => ['p_b2' => 0.9, 'r_b2' => 0.3, 'p_b3' => 0.08, 'r_b3' => 0.05, 'p_b4' => 0.95, 'r_b4' => 0.6],
    ],
    [
        'name' => 'structural chunks when Jev fails',
        'kind' => 'chunk',
        'format' => 'markdown',
        'config' => array_replace($small, ['max_tokens' => 400]),
        'fail' => true,
        'input' => "# Terms\n\n" . $fees . "\n\n" . $late . "\n\n" . $cancel . "\n\n" . $data,
    ],
    [
        'name' => 'blocks keep their input indexes past empty blocks',
        'kind' => 'chunk',
        'format' => 'blocks',
        'jev' => false,
        'config' => $small,
        'input' => [
            ['type' => 'heading', 'text' => "Report\n 2026", 'level' => 1, 'meta' => ['page' => 1]],
            ['type' => 'paragraph', 'text' => '   '],
            ['type' => 'paragraph', 'text' => 'Traffic rose by a tenth.', 'meta' => ['page' => 1]],
            ['type' => 'table', 'text' => "Page | Visits\nHome | 1200"],
            ['type' => 'heading', 'text' => 'Detail', 'level' => 3],
            ['type' => 'code', 'text' => 'SELECT 1;'],
        ],
    ],
    [
        'name' => 'unicode text, ellipses and multibyte overlap',
        'kind' => 'chunk',
        'format' => 'markdown',
        'jev' => false,
        'config' => ['max_tokens' => 40, 'min_tokens' => 10, 'overlap_tokens' => 8, 'topic_min_tokens' => 5, 'split_long_paragraph_at' => 30],
        'input' => "# Café déjà vu 🎉\n\nÉtude numéro un… Économie de marché ouverte. Ça marche très bien ici. Über alles gut gesagt! Ödön lit le journal? Ja, natürlich.\n\nŒuvre complète 🎨 avec des émojis. Zoë arrive demain matin.",
    ],
    [
        'name' => 'an oversized block opening a chunk when min_tokens is 0',
        'kind' => 'chunk',
        'format' => 'text',
        'jev' => false,
        'config' => ['max_tokens' => 20, 'min_tokens' => 0, 'overlap_tokens' => 0],
        'input' => "Short.\n\n" . $fees . "\n\nShort again.",
    ],
    [
        'name' => 'an oversized first block when min_tokens is 0',
        'kind' => 'chunk',
        'format' => 'markdown',
        'config' => ['max_tokens' => 20, 'min_tokens' => 0, 'overlap_tokens' => 0, 'topic_min_tokens' => 0],
        'input' => "# Terms\n\n" . $fees . "\n\n" . $cancel,
        'answers' => ['p_b2' => 0.1, 'r_b2' => 0.1],
    ],
    [
        'name' => 'an answer of the wrong type counts as missing',
        'kind' => 'chunk',
        'format' => 'markdown',
        'config' => array_replace($small, ['max_tokens' => 400]),
        'input' => "# Terms\n\n" . $fees . "\n\n" . $late . "\n\n" . $cancel . "\n\n" . $data,
        'answers' => ['p_b2' => 0.9, 'r_b2' => 0.3, 'p_b3' => 'yes', 'r_b3' => 0.05, 'p_b4' => 0.1, 'r_b4' => 'no'],
    ],
    [
        'name' => 'rank by answer, with the cross-check pick first',
        'kind' => 'rank',
        'query' => 'How long do customers have to send something back?',
        'passages' => [
            ['id' => 'overview', 'text' => 'Refunds are a common question from customers.', 'heading_path' => ['Refunds', 'Overview']],
            ['id' => 'policy', 'text' => 'Orders may be returned within 30 days for a full refund.', 'heading_path' => ['Refunds', 'Policy']],
            ['id' => 'shipping', 'text' => 'Returns are shipped back at our cost.', 'heading_path' => ['Refunds', 'Shipping']],
            ['id' => 'press', 'text' => 'We were founded in 2009.', 'heading_path' => ['About']],
        ],
        'answers' => ['c_refunds-overview-0' => 0.2, 'c_refunds-policy-1' => 0.7, 'c_refunds-shipping-2' => 0.8, 'c_about-3' => 0.05, 'best' => 'refunds-policy-1'],
    ],
    [
        'name' => 'rank keeps a weak pick out unless rescue is on',
        'kind' => 'rank',
        'query' => 'Do you ship to Mars?',
        'passages' => [
            ['id' => 'a', 'text' => 'We ship to every country in Europe.', 'heading_path' => ['Shipping']],
            ['id' => 'b', 'text' => 'Delivery takes three days.', 'heading_path' => ['Shipping']],
        ],
        'answers' => ['c_shipping-0' => 0.3, 'c_shipping-1' => 0.1, 'best' => 'shipping-0'],
    ],
    [
        'name' => 'rank adds a weak pick when rescue is on',
        'kind' => 'rank',
        'query' => 'Do you ship to Mars?',
        'config' => ['cross_check_rescue' => true],
        'passages' => [
            ['id' => 'a', 'text' => 'We ship to every country in Europe.', 'heading_path' => ['Shipping']],
            ['id' => 'b', 'text' => 'Delivery takes three days.', 'heading_path' => ['Shipping']],
        ],
        'answers' => ['c_shipping-0' => 0.3, 'c_shipping-1' => 0.1, 'best' => 'shipping-0'],
    ],
    [
        'name' => 'rank labels, passage cap, ties and limit',
        'kind' => 'rank',
        'query' => 'Where is the café?',
        'limit' => 2,
        'config' => ['passage_chars' => 20, 'cross_check' => false],
        'passages' => [
            ['id' => '1', 'text' => 'The café is on the corner of Rue de Rivoli and Rue du Louvre.', 'heading_path' => ['Café Déjà Vu', 'Où sommes-nous?']],
            ['id' => '2', 'text' => 'Opening hours are nine to five.'],
            ['id' => '3', 'text' => 'Find us on the corner, next to the museum.', 'heading_path' => ['']],
        ],
        'answers' => ['c_cafe-deja-vu-ou-sommes-nous-0' => 0.9, 'c_passage-2-1' => 0.1, 'c_untitled-2' => 0.9],
    ],
    [
        'name' => 'rank ignores answers of the wrong type',
        'kind' => 'rank',
        'query' => 'How long do customers have to send something back?',
        'passages' => [
            ['id' => 'overview', 'text' => 'Refunds are a common question from customers.', 'heading_path' => ['Refunds']],
            ['id' => 'policy', 'text' => 'Orders may be returned within 30 days.', 'heading_path' => ['Policy']],
        ],
        'answers' => ['c_refunds-0' => 'refunds-0', 'c_policy-1' => 0.8, 'best' => 0.9],
    ],
    [
        'name' => 'rank returns nothing when Jev fails',
        'kind' => 'rank',
        'query' => 'Anything?',
        'fail' => true,
        'passages' => [['id' => 'x', 'text' => 'Some text.']],
    ],
    ['name' => 'config takes whole numbers for integers', 'kind' => 'config', 'input' => ['max_tokens' => 500.0, 'seam_threshold' => 1, 'cross_check' => false]],
    ['name' => 'config refuses an unknown key', 'kind' => 'config', 'input' => ['max_token' => 5]],
    ['name' => 'config refuses a string for an integer', 'kind' => 'config', 'input' => ['max_tokens' => 'many']],
    ['name' => 'config refuses a fraction for an integer', 'kind' => 'config', 'input' => ['max_tokens' => 2.5]],
    ['name' => 'config refuses a value below its minimum', 'kind' => 'config', 'input' => ['overlap_tokens' => -1]],
    ['name' => 'config refuses a number above its maximum', 'kind' => 'config', 'input' => ['seam_threshold' => 1.5]],
    ['name' => 'config refuses a number for a boolean', 'kind' => 'config', 'input' => ['cross_check' => 1]],
    ['name' => 'blocks keep meta and drop a level outside headings', 'kind' => 'blocks', 'input' => '[{"type":"heading","text":"A","level":2,"meta":{"page":3}},{"type":"paragraph","text":"B","level":4}]'],
    ['name' => 'blocks refuse an empty object', 'kind' => 'blocks', 'input' => '{}'],
    ['name' => 'blocks refuse a list of strings', 'kind' => 'blocks', 'input' => '["text"]'],
    ['name' => 'blocks refuse an unknown type', 'kind' => 'blocks', 'input' => '[{"type":"image","text":"x"}]'],
    ['name' => 'blocks refuse a heading without a level', 'kind' => 'blocks', 'input' => '[{"type":"heading","text":"x"}]'],
    ['name' => 'blocks refuse invalid JSON', 'kind' => 'blocks', 'input' => '[{"type":'],
    [
        'name' => 'a code block and a table over max_tokens split at lines, the table repeating its head',
        'kind' => 'chunk',
        'format' => 'blocks',
        'jev' => false,
        'config' => ['max_tokens' => 30, 'min_tokens' => 10, 'overlap_tokens' => 0, 'topic_min_tokens' => 10, 'split_long_paragraph_at' => 40],
        'input' => [
            ['type' => 'heading', 'text' => 'Output', 'level' => 1],
            ['type' => 'code', 'text' => implode("\n", array_map(static fn (int $i): string => "step {$i} finished in {$i}0 ms", range(1, 12)))],
            ['type' => 'table', 'text' => "Table: Rates\nBand | Rate\n" . implode("\n", array_map(static fn (int $i): string => "Band {$i} | £{$i}5", range(1, 12)))],
            ['type' => 'paragraph', 'text' => str_repeat('averyveryverylongword ', 12)],
        ],
    ],
    ...$tabular,
    ...$prose,
    ...$reference,
    ...$conversation,
    ...$transcript,
    ...$markup,
    ...$markupFormats,
    ...$readHtml,
    ...$readTextMarkup,
    ...$readMisc,
    ...$readText,
    ...$readDocx,
    ...$readChat,
];
