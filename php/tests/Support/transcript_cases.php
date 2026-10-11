<?php

declare(strict_types=1);

/*
 * Agent transcript fixtures: blocks as the chat reader writes them. A tool call is a message of
 * its own; its result is an attachment, kept out of every Jev link question and linked
 * `output_of` the call. A later user message corrects a figure the assistant read from the
 * output. The append cases split the same transcripts in two and add the second half.
 */

$say = static fn (string $turn, string $speaker, string $text, ?string $replyTo = null): array => ['type' => 'paragraph', 'text' => $text, 'meta' => ['turn' => $turn, 'speaker' => $speaker] + ($replyTo !== null ? ['reply_to' => $replyTo] : [])];
$call = static fn (string $turn, string $tool, string $text, string $replyTo): array => ['type' => 'code', 'text' => $text, 'meta' => ['turn' => $turn, 'speaker' => 'assistant', 'tool' => $tool, 'reply_to' => $replyTo]];
$result = static fn (string $turn, string $tool, string $text, string $replyTo): array => ['type' => 'code', 'text' => $text, 'meta' => ['turn' => $turn, 'speaker' => 'tool', 'tool' => $tool, 'reply_to' => $replyTo, 'attachment' => true]];

$config = "replicas: 3\nimage: api:2.4.1\nregion: eu-west-1\nnotes: " . str_repeat('staging mirrors production traffic at one tenth of the volume; ', 30);

$head = [
    ['type' => 'heading', 'text' => 'Session', 'level' => 1],
    $say('u1', 'user', 'Find the deploy config for the staging cluster and tell me how many replicas it runs.'),
    $say('a1', 'assistant', 'I will read the staging deploy file.', 'u1'),
    $call('a1#c1', 'read_file', 'read_file({"path":"deploy/staging.yaml"})', 'a1'),
    $result('c1', 'read_file', $config, 'a1#c1'),
];
$tail = [
    $say('a2', 'assistant', 'Staging runs 3 replicas of api:2.4.1 in eu-west-1.'),
    $say('u2', 'user', 'That file is stale: staging was scaled to 5 replicas yesterday, not 3.'),
    $say('a3', 'assistant', 'Understood, staging runs 5 replicas.'),
];

$session = ['id' => 'session', 'blocks' => [...$head, ...$tail]];
$answers = ['r4' => 'm0', 'r5' => 'm4', 'r6' => 'm5', 'k6' => 0.9];

$minutes = (require __DIR__ . '/conversation_cases.php')[0]['input'];
$links = (require __DIR__ . '/conversation_cases.php')[0]['answers'];

return [
    ['name' => 'tool output becomes output units linked to the call, outside Jev link questions', 'kind' => 'prose-ingest', 'input' => $session, 'answers' => $answers],
    ['name' => 'conversation links switched off ask Jev nothing about a transcript', 'kind' => 'prose-ingest', 'input' => $session + ['config' => ['conversation_replies' => false, 'conversation_outcomes' => false, 'conversation_corrections' => false]], 'answers' => $answers],
    ['name' => 'a kept tool output brings the call that produced it', 'kind' => 'prose-retrieve', 'documents' => [$session], 'scope' => ['session'], 'query' => 'Which image does the staging deploy file name?', 'answers' => $answers + ['scope' => 'one message', 'c_message-3' => 0.9, 'best' => 'message-3']],
    ['name' => 'a kept message that a later one corrects brings the correction and is marked superseded', 'kind' => 'prose-retrieve', 'documents' => [$session], 'scope' => ['session'], 'query' => 'How many replicas does staging run?', 'answers' => $answers + ['scope' => 'one message', 'c_message-2' => 0.9, 'best' => 'message-2']],
    ['name' => 'appended turns continue the conversation and link back to earlier messages', 'kind' => 'prose-append', 'input' => ['id' => 'session', 'blocks' => $head, 'append' => [$tail]], 'answers' => ['k6' => 0.0, 'k3' => 0.9] + $answers],
    ['name' => 'appended minutes: a correction and an outcome link to messages ingested earlier, and a new section is prose', 'kind' => 'prose-append', 'input' => ['id' => 'minutes', 'config' => $minutes['config'], 'blocks' => array_slice($minutes['blocks'], 0, 5), 'append' => [array_slice($minutes['blocks'], 5), [['type' => 'heading', 'text' => 'Any other business', 'level' => 2], ['type' => 'paragraph', 'text' => 'The next meeting is on 4 May in the reading room.']]]], 'answers' => $links],
];
