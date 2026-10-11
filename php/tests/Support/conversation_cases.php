<?php

declare(strict_types=1);

/*
 * Conversational profile fixtures: minutes with an agenda section (detected by Jev) and a
 * discussion whose blocks name speakers. One reply comes from the source, the others from Jev;
 * a later message corrects a figure, and the chair's closing message settles the proposal.
 */

$turn = static fn (int $n, string $speaker, string $full, string $time, string $text, ?int $replyTo = null): array => ['type' => 'paragraph', 'text' => $text, 'meta' => ['turn' => $n, 'speaker' => $speaker, 'speaker_full' => $full, 'time' => $time, 'reply_to' => $replyTo]];

$minutes = ['id' => 'minutes', 'config' => ['max_tokens' => 60, 'min_tokens' => 10, 'overlap_tokens' => 0, 'topic_min_tokens' => 10, 'split_long_paragraph_at' => 150], 'blocks' => [
    ['type' => 'heading', 'text' => 'Library committee', 'level' => 1],
    ['type' => 'heading', 'text' => 'Agenda', 'level' => 2],
    ['type' => 'paragraph', 'text' => 'Items: the library budget for next year, then any other business.'],
    ['type' => 'heading', 'text' => 'Budget discussion', 'level' => 2],
    $turn(1, 'Ann Lee', 'Ann Lee (Chair)', '10:00', 'I propose that we set the library budget for next year at £40,000 so the reading room can open on Sundays.'),
    $turn(2, 'Ben Ortiz', 'Ben Ortiz (Treasurer)', '10:02', 'Sunday staffing for the reading room costs £6,000 a year, so the increase covers it.', 1),
    $turn(3, 'Cara Diaz', 'Cara Diaz', '10:03', 'Agreed.'),
    $turn(4, 'Ben Ortiz', 'Ben Ortiz (Treasurer)', '10:05', 'A correction: Sunday staffing costs £8,000 a year under the new pay rates, not £6,000.'),
    $turn(4, 'Ben Ortiz', 'Ben Ortiz (Treasurer)', '10:05', 'The budget still covers it, with £2,000 less for new books.'),
    $turn(5, 'Ann Lee', 'Ann Lee (Chair)', '10:08', 'The proposal is carried: the budget is £40,000 and the reading room opens on Sundays from April.'),
]];

$twoMeetings = ['id' => 'two-meetings', 'blocks' => [
    ['type' => 'heading', 'text' => 'March', 'level' => 1],
    $turn(1, 'Ann Lee', 'Ann Lee (Chair)', '10:00', 'Shall we open on Sundays?'),
    $turn(2, 'Ben Ortiz', 'Ben Ortiz (Treasurer)', '10:01', 'Only if the budget allows it.', 1),
    ['type' => 'heading', 'text' => 'April', 'level' => 1],
    $turn(1, 'Ann Lee', 'Ann Lee (Chair)', '10:00', 'The budget allows Sunday opening.'),
    $turn(2, 'Cara Diaz', 'Cara Diaz', '10:02', 'Then we open in May.', 1),
]];

$written = ['id' => 'written-minutes', 'blocks' => [
    ['type' => 'heading', 'text' => 'Committee', 'level' => 1],
    ['type' => 'heading', 'text' => 'Discussion', 'level' => 2],
    ['type' => 'paragraph', 'text' => '[10:00] Ann Lee (Chair): I propose a Sunday opening.'],
    ['type' => 'paragraph', 'text' => 'Ben Ortiz: It costs £6,000.'],
    ['type' => 'paragraph', 'text' => 'That is per year.'],
    ['type' => 'paragraph', 'text' => 'Cara Diaz: Agreed.'],
    ['type' => 'heading', 'text' => 'Questions', 'level' => 2],
    ['type' => 'paragraph', 'text' => 'Q: Is it open on Sundays?'],
    ['type' => 'paragraph', 'text' => 'A: From April.'],
    ['type' => 'paragraph', 'text' => 'Note: hours may change.'],
    ['type' => 'paragraph', 'text' => 'Example: 10:00 to 16:00.'],
]];

$languages = ['id' => 'languages', 'blocks' => [
    ['type' => 'heading', 'text' => 'Séance', 'level' => 1],
    ['type' => 'heading', 'text' => 'Débat', 'level' => 2],
    ['type' => 'paragraph', 'text' => 'M. le président. – La séance est ouverte.'],
    ['type' => 'paragraph', 'text' => 'Mme Anne Martin : Je demande la parole.'],
    ['type' => 'paragraph', 'text' => 'M. Jean Dupont. – Je m\'y oppose.'],
    ['type' => 'paragraph', 'text' => 'Mme Anne Martin : Merci.'],
    ['type' => 'heading', 'text' => '委員会', 'level' => 2],
    ['type' => 'paragraph', 'text' => '○宮路委員長　これより会議を開きます。'],
    ['type' => 'paragraph', 'text' => '○山田委員　質問します。'],
    ['type' => 'paragraph', 'text' => '田中（大臣）：お答えします。'],
    ['type' => 'paragraph', 'text' => '○宮路委員長　山田君。'],
    ['type' => 'heading', 'text' => '祝日', 'level' => 2],
    ['type' => 'paragraph', 'text' => '日本の祝日は法律で定められている。'],
    ['type' => 'paragraph', 'text' => '春分の日は毎年変わる。'],
    ['type' => 'paragraph', 'text' => '注：詳細は別表を参照。'],
    ['type' => 'paragraph', 'text' => '祝日が日曜日の場合は振替休日となる。'],
]];

$links = ['s1' => 'guidance', 'r2' => 'm1', 'r3' => 'm1', 'r4' => 'm0', 'm7' => 0.9, 'settles' => 'm3', 'k2' => 0.8];

return [
    ['name' => 'build message units and reply, outcome and correction links from a discussion', 'kind' => 'prose-ingest', 'input' => $minutes, 'answers' => $links],
    ['name' => 'no Jev: messages keep their source replies only', 'kind' => 'prose-ingest', 'input' => $minutes, 'jev' => false],
    ['name' => 'speakers written as "Name:" make a section conversational; Q and A do not', 'kind' => 'prose-ingest', 'input' => $written, 'jev' => false],
    ['name' => 'speakers in French and Japanese records; uncased prose and a 注 label are not speakers', 'kind' => 'prose-ingest', 'input' => $languages, 'jev' => false],
    ['name' => 'turns of different sections stay apart, and source replies resolve within a section', 'kind' => 'prose-ingest', 'input' => $twoMeetings, 'jev' => false],
    ['name' => 'gather a corrected figure with the message it corrects', 'kind' => 'prose-retrieve', 'documents' => [$minutes], 'scope' => ['minutes'], 'query' => 'How much does Sunday staffing cost?', 'answers' => $links + ['scope' => 'correction', 'c_message-1' => 0.8, 'best' => 'message-1']],
    ['name' => 'gather an outcome with the proposal it settles', 'kind' => 'prose-retrieve', 'documents' => [$minutes], 'scope' => ['minutes'], 'query' => 'What did the committee decide about the budget?', 'answers' => $links + ['scope' => 'outcome', 'c_message-0' => 0.9, 'best' => 'message-0']],
    ['name' => 'gather what one speaker said across their messages', 'kind' => 'prose-retrieve', 'documents' => [$minutes], 'scope' => ['minutes'], 'query' => 'What did the treasurer say about the reading room?', 'answers' => $links + ['scope' => 'one person', 'speaker' => 'ben-ortiz', 'c_message-0' => 0.7, 'best' => 'message-0']],
    ['name' => 'bring the message a short reply answers', 'kind' => 'prose-retrieve', 'documents' => [$minutes], 'scope' => ['minutes'], 'query' => 'Who agreed with Ben?', 'answers' => $links + ['scope' => 'exchange', 'c_message-1' => 0.8, 'best' => 'message-1']],
];
