<?php

declare(strict_types=1);

/*
 * Markup fixtures: an OpenAPI file with a path whose request body is a `$ref` to a schema large
 * enough to be cut into a header and children, a Compose file with an anchor on its own line and
 * a merge alias, and a minified JSON file with a `$ref`.
 */

$long = static fn (string $what): string => str_repeat("{$what} is described at length so that this subtree passes the size limit. ", 6);

$openapi = "openapi: 3.1.0\n"
    . "info:\n  title: Mail API\n  version: '1.2'\n"
    . "tags:\n  - name: Emails\n    description: Send and manage emails.\n  - name: Contacts\n"
    . "paths:\n"
    . "  # Sending\n"
    . "  /emails:\n    post:\n      operationId: emails/send\n      summary: Send an email\n      requestBody:\n        content:\n          application/json:\n            schema:\n              \$ref: '#/components/schemas/SendEmailRequest'\n"
    . "  \"/emails/{id}/cancel\":\n    post:\n      operationId: emails/cancel\n      summary: Cancel a scheduled email\n"
    . "components:\n  schemas:\n    SendEmailRequest:\n      type: object\n      required: [from, to, subject]\n      properties:\n"
    . "        from:\n          type: string\n          description: >\n            " . $long('The sender') . "\n"
    . "        to:\n          type: array\n          description: |\n            " . $long('The recipients') . "\n"
    . "        subject:\n          type: string\n          description: \"The subject line.\\nOne line only.\"\n"
    . "        scheduled_at:\n          type: string\n          description: " . $long('The send time') . "\n";

$vars = implode('', array_map(static fn (int $i): string => "    SETTING_{$i}: value number {$i} for the shared environment\n", range(1, 8)));
$compose = "x-common:\n  &common\n  image: app:2.0\n  environment:\n    &env\n{$vars}"
    . "services:\n  web:\n    <<: *common\n    command: [\"serve\", \"--port\", \"8080\",\n      \"--workers\", \"4\"]\n    ports:\n      - \"8080:8080\"\n    # health check for the load balancer\n    healthcheck:\n      test: curl --fail http://localhost:8080/health || exit 1\n      interval: 30s\n      timeout: 10s\n      retries: 5\n"
    . "  worker:\n    <<: *common\n    environment: *env\n    command: run the queue worker with three processes and a ten minute timeout for each job\n    restart: always\n";

$json = '{"openapi":"3.1.0","paths":{"/contacts":{"get":{"operationId":"contacts/list","responses":{"200":{"content":{"application/json":{"schema":{"$ref":"#/components/schemas/ContactList"}}}}}}}},"components":{"schemas":{"ContactList":{"type":"object","properties":{"data":{"type":"array"},"has_more":{"type":"boolean","default":false}}}}}}';

$api = ['id' => 'mail-api', 'markup' => $openapi, 'format' => 'yaml', 'title' => 'Mail API'];

return [
    ['name' => 'cut an OpenAPI file into path subtrees and link a $ref to its schema', 'kind' => 'markup-ingest', 'input' => $api],
    ['name' => 'link Compose aliases to anchors, including an anchor on its own line', 'kind' => 'markup-ingest', 'input' => ['id' => 'compose', 'markup' => $compose, 'format' => 'yaml']],
    ['name' => 'a key given twice is cut once, from its last node', 'kind' => 'markup-ingest', 'input' => ['id' => 'repeated', 'markup' => "a:\n" . $vars . "b: 1\na:\n" . $vars, 'format' => 'yaml']],
    ['name' => 'print minified JSON in one layout and link its $ref', 'kind' => 'markup-ingest', 'input' => ['id' => 'contacts', 'markup' => $json, 'format' => 'json']],
    [
        'name' => 'follow a $ref from the endpoint to the schema the answer turns on',
        'kind' => 'prose-retrieve',
        'documents' => [$api],
        'scope' => ['mail-api'],
        'query' => 'Which fields are required when sending an email?',
        'answers' => ['c_p0' => 0.8, 'best' => 'p0', 'c_linked-0' => 0.9],
    ],
];
