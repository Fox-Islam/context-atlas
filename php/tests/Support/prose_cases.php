<?php

declare(strict_types=1);

/*
 * Prose profile fixtures: a short licence (definitions, a grant that cites another section, a
 * worked example) followed by an FAQ, so detection gives the two parts different profiles.
 */

$blocks = [
    ['type' => 'heading', 'text' => 'Licence terms', 'level' => 1],
    ['type' => 'heading', 'text' => '1. Definitions', 'level' => 2],
    ['type' => 'paragraph', 'text' => '"Licensed Material" means the artistic or literary work, database or other material to which the Licensor applied this licence. "Adapted Material" means material derived from or based upon the Licensed Material.'],
    ['type' => 'heading', 'text' => '2. Grant', 'level' => 2],
    ['type' => 'paragraph', 'text' => 'Subject to the conditions in Section 3, the Licensor grants You a worldwide, royalty-free licence to reproduce and share the Licensed Material and to produce Adapted Material.'],
    ['type' => 'heading', 'text' => '3. Conditions', 'level' => 2],
    ['type' => 'paragraph', 'text' => 'If You share the Licensed Material, You must keep the copyright notice and indicate any changes You made.'],
    ['type' => 'paragraph', 'text' => 'For example, a translated edition must say that it is a translation and name the original.'],
    ['type' => 'heading', 'text' => 'Questions', 'level' => 1],
    ['type' => 'heading', 'text' => 'Can I sell copies?', 'level' => 2],
    ['type' => 'paragraph', 'text' => 'Yes. The licence allows commercial use as long as the conditions are met.'],
];
$config = ['max_tokens' => 120, 'min_tokens' => 40, 'overlap_tokens' => 0, 'topic_min_tokens' => 20, 'split_long_paragraph_at' => 150];
$licence = ['id' => 'licence', 'blocks' => $blocks, 'config' => $config];

$links = ['n0' => 0.1, 'n1' => 0.1, 'n2' => 0.1, 'n3' => 0.1, 'n4' => 0.9, 'd0' => 0.1, 'd1' => 0.95, 'd2' => 0.1, 'd3' => 0.1, 'd4' => 0.1, 'd5' => 0.1, 'u0' => 0.9];

$guide = ['id' => 'guide', 'profile' => 'procedural', 'config' => $config, 'blocks' => [
    ['type' => 'heading', 'text' => 'Install guide', 'level' => 1],
    ['type' => 'heading', 'text' => 'Prepare', 'level' => 2],
    ['type' => 'paragraph', 'text' => '1. Download the source archive from the project website. 2. Check its signature against the published key before unpacking it.'],
    ['type' => 'heading', 'text' => 'Warning:', 'level' => 3],
    ['type' => 'paragraph', 'text' => 'Never unpack an archive whose signature does not match.'],
    ['type' => 'heading', 'text' => 'Build', 'level' => 2],
    ['type' => 'paragraph', 'text' => 'Run make in the unpacked directory and wait for the compiler to finish without errors. If you skipped a step, go back to #Prepare first.'],
    ['type' => 'heading', 'text' => 'Install', 'level' => 2],
    ['type' => 'paragraph', 'text' => 'Run make install as root to copy the programs, libraries and manual pages into place. If it fails, see Section Q.'],
    ['type' => 'heading', 'text' => 'Q. Troubleshooting', 'level' => 2],
    ['type' => 'paragraph', 'text' => 'When make install fails with permission denied, your account cannot write to the install directory: run it again with sudo.'],
]];
$history = ['id' => 'history', 'profile' => 'narrative', 'config' => ['max_tokens' => 30, 'min_tokens' => 5, 'overlap_tokens' => 0, 'topic_min_tokens' => 5, 'split_long_paragraph_at' => 150], 'blocks' => [
    ['type' => 'heading', 'text' => 'The bridge', 'level' => 1],
    ['type' => 'paragraph', 'text' => 'Work on the bridge began in 1890 after a storm destroyed the old ferry pier.'],
    ['type' => 'paragraph', 'text' => 'Funding ran out in 1893, and construction stopped for two years while the council argued.'],
    ['type' => 'paragraph', 'text' => 'A railway company paid to finish the bridge, which opened to trains in 1898.'],
    ['type' => 'paragraph', 'text' => 'Pedestrians were allowed across only from 1902, when a footpath was added.'],
]];
$guideLinks = ['n0' => 0.1, 'n1' => 0.1, 'n2' => 0.1, 'n3' => 0.1, 'n4' => 0.1];
$historyLinks = ['n0' => 0.1, 'n1' => 0.1, 'n2' => 0.1];

return [
    ['name' => 'detect a profile per section and build each profile\'s links', 'kind' => 'prose-ingest', 'input' => $licence, 'answers' => ['s1' => 'normative', 's2' => 'guidance'] + $links],
    ['name' => 'a profile named by the caller applies to every section', 'kind' => 'prose-ingest', 'input' => $licence + ['profile' => 'normative'], 'answers' => $links],
    ['name' => 'no Jev: guidance passages without links', 'kind' => 'prose-ingest', 'input' => $licence, 'jev' => false],
    [
        'name' => 'follow needs, reference and definition links from the kept passages',
        'kind' => 'prose-retrieve',
        'documents' => [$licence + ['profile' => 'normative']],
        'scope' => ['licence'],
        'query' => 'Am I allowed to share a translation of the licensed material, and on what conditions?',
        'answers' => $links + ['c_p0' => 0.85, 'c_p1' => 0.3, 'c_p2' => 0.2, 'c_p3' => 0.1, 'best' => 'p0', 'scope' => 'one passage', 'c_reference-0' => 0.7, 'c_definition-0' => 0.3, 'definition' => 'definition-0'],
    ],
    [
        'name' => 'abstain when no passage answers',
        'kind' => 'prose-retrieve',
        'documents' => [$licence + ['profile' => 'normative']],
        'scope' => ['licence'],
        'query' => 'What is the licence fee in euros?',
        'answers' => $links + ['c_p0' => 0.1, 'c_p1' => 0.1, 'c_p2' => 0.1, 'c_p3' => 0.1, 'c_p4' => 0.05, 'c_p5' => 0.05, 'best' => 'p0', 'scope' => 'one passage'],
    ],
    ['name' => 'fold an admonition heading and link letter sections and wiki anchors', 'kind' => 'prose-ingest', 'input' => $guide, 'answers' => $guideLinks],
    [
        'name' => 'gather the step after the one the question names',
        'kind' => 'prose-retrieve',
        'documents' => [$guide],
        'scope' => ['guide'],
        'query' => 'I have run make. What comes next?',
        'answers' => $guideLinks + ['c_p0' => 0.2, 'c_p1' => 0.3, 'c_p2' => 0.8, 'best' => 'p2', 'scope' => 'the next or previous step', 'anchor' => 'p2', 'direction' => 'after', 'c_step-0' => 0.1, 'c_step-1' => 0.9, 'c_step-2' => 0.2, 'c_step-3' => 0.1, 'c_reference-0' => 0.3],
    ],
    [
        'name' => 'keep every narrative passage that answers part of a question',
        'kind' => 'prose-retrieve',
        'documents' => [$history],
        'scope' => ['history'],
        'query' => 'What happened to the bridge between 1890 and 1902?',
        'answers' => $historyLinks + ['c_p0' => 0.9, 'c_p1' => 0.8, 'c_p2' => 0.7, 'c_p3' => 0.6, 'c_p4' => 0.2, 'best' => 'p0', 'scope' => 'several places'],
    ],
];
