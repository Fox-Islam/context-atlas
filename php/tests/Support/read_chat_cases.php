<?php

declare(strict_types=1);

/*
 * Chat transcript reader fixtures (format `chat`): one small transcript per shape family,
 * written from each vendor's documentation, plus dedupe, branch-following and the errors every
 * package must give. spec/formats/chat.md states the rules.
 */

$chat = static fn (string $name, string $text): array => ['name' => $name, 'kind' => 'read', 'input' => ['format' => 'chat', 'text' => $text]];

$jsonl = static fn (array $lines): string => implode("\n", $lines) . "\n";

return [
    $chat('chat: OpenAI Chat Completions with a developer prompt, content parts, a named user, tool calls and a tool reply', <<<'JSON'
        {"model": "gpt-4.1", "messages": [
          {"role": "developer", "content": "Answer in one sentence."},
          {"role": "user", "name": "ann", "content": [{"type": "text", "text": "What is the weather in Paris and Rome?"}, {"type": "image_url", "image_url": {"url": "data:image/png;base64,iVBORw0KGgo="}}, {"type": "file", "file": {"filename": "trip.pdf", "file_data": "JVBERi0="}}]},
          {"role": "assistant", "content": null, "tool_calls": [
            {"id": "call_1", "type": "function", "function": {"name": "get_weather", "arguments": "{\"city\": \"Paris\", \"days\": 2.50}"}},
            {"id": "call_2", "type": "function", "function": {"name": "get_weather", "arguments": "{\"city\":\"Rome\"}"}}]},
          {"role": "tool", "tool_call_id": "call_2", "content": "Rome: 24C, sunny"},
          {"role": "tool", "tool_call_id": "call_1", "content": [{"type": "text", "text": "Paris: 18C, cloudy"}]},
          {"role": "assistant", "content": "Paris is 18C and cloudy; Rome is 24C and sunny.", "refusal": null}
        ]}
        JSON),
    $chat('chat: OpenAI legacy function_call and the function role, matched by name', <<<'JSON'
        [{"role": "system", "content": "You are a calculator."},
         {"role": "user", "content": "2+2?"},
         {"role": "assistant", "content": null, "function_call": {"name": "add", "arguments": "{\"a\":2,\"b\":2}"}},
         {"role": "function", "name": "add", "content": "4"},
         {"role": "assistant", "content": [{"type": "refusal", "refusal": "I can only add."}]}]
        JSON),
    $chat('chat: OpenAI Responses input and output items, instructions, reasoning summaries, built-in and custom tool calls', <<<'JSON'
        {"instructions": "Be brief.", "input": [
          {"type": "message", "role": "user", "content": [{"type": "input_text", "text": "Find the release date."}, {"type": "input_image", "image_url": "https://example.com/a.png"}]},
          {"type": "reasoning", "id": "rs_1", "summary": [{"type": "summary_text", "text": "Need a search."}], "encrypted_content": "gAAAA"},
          {"type": "reasoning", "id": "rs_2", "summary": [], "encrypted_content": "gAAAB"},
          {"type": "web_search_call", "id": "ws_1", "status": "completed", "action": {"type": "search", "query": "release date"}},
          {"type": "function_call", "id": "fc_1", "call_id": "call_9", "name": "lookup", "arguments": "{\"q\": \"v2\"}"},
          {"type": "function_call_output", "call_id": "call_9", "output": "2026-03-01"},
          {"type": "custom_tool_call", "id": "ctc_1", "call_id": "call_10", "name": "shell", "input": "ls -la"},
          {"type": "custom_tool_call_output", "call_id": "call_10", "output": [{"type": "input_text", "text": "README.md"}]}
        ], "output": [
          {"type": "message", "id": "msg_1", "role": "assistant", "status": "completed", "content": [{"type": "output_text", "text": "It ships on 1 March 2026.", "annotations": []}]}
        ]}
        JSON),
    $chat('chat: an OpenAI Conversations item list', <<<'JSON'
        {"object": "list", "data": [
          {"type": "message", "id": "msg_a", "role": "user", "content": [{"type": "input_text", "text": "Hello"}]},
          {"type": "message", "id": "msg_b", "role": "assistant", "content": [{"type": "output_text", "text": "Hi there."}]}
        ], "first_id": "msg_a", "last_id": "msg_b", "has_more": false}
        JSON),
    $chat('chat: Anthropic Messages with a system prompt, thinking, tool use, an error result, media and a server tool', <<<'JSON'
        {"model": "claude-sonnet-4-5", "system": [{"type": "text", "text": "You are a support agent."}], "messages": [
          {"role": "user", "content": [{"type": "image", "source": {"type": "base64", "media_type": "image/png", "data": "iVBORw0KGgo="}}, {"type": "document", "title": "Invoice 12", "source": {"type": "text", "media_type": "text/plain", "data": "Total 40"}}, {"type": "text", "text": "Why was I charged twice?"}]},
          {"role": "assistant", "content": [
            {"type": "thinking", "thinking": "Check the billing records.", "signature": "EqQB"},
            {"type": "redacted_thinking", "data": "EmwKAhgB"},
            {"type": "text", "text": "Let me check."},
            {"type": "tool_use", "id": "toolu_1", "name": "billing_lookup", "input": {"invoice": 12}}]},
          {"role": "user", "content": [{"type": "tool_result", "tool_use_id": "toolu_1", "is_error": true, "content": [{"type": "text", "text": "Timeout"}]}]},
          {"role": "assistant", "content": [
            {"type": "server_tool_use", "id": "srvtoolu_1", "name": "web_search", "input": {"query": "double charge"}},
            {"type": "web_search_tool_result", "tool_use_id": "srvtoolu_1", "content": [{"type": "web_search_result", "url": "https://example.com/help", "title": "Help", "encrypted_content": "EqgfCio", "page_age": null}]},
            {"type": "text", "text": "A retry can charge twice; it is refunded in 3 days."}]}
        ]}
        JSON),
    $chat('chat: Gemini contents with a system instruction, thoughts, calls without ids, inline data and code execution', <<<'JSON'
        {"systemInstruction": {"parts": [{"text": "Reply in French."}]}, "contents": [
          {"role": "user", "parts": [{"text": "Weather in Lyon and Nice?"}, {"inlineData": {"mimeType": "image/jpeg", "data": "/9j/4AAQ"}}, {"fileData": {"mimeType": "application/pdf", "fileUri": "gs://b/f.pdf", "displayName": "plan.pdf"}}]},
          {"role": "model", "parts": [{"text": "Two lookups.", "thought": true}, {"functionCall": {"name": "weather", "args": {"city": "Lyon"}}}, {"functionCall": {"name": "weather", "args": {"city": "Nice"}}}]},
          {"role": "user", "parts": [{"functionResponse": {"name": "weather", "response": {"temp": 15}}}, {"functionResponse": {"name": "weather", "response": {"temp": 21}}}]},
          {"role": "model", "parts": [{"executableCode": {"language": "PYTHON", "code": "print(15 + 21)"}}, {"codeExecutionResult": {"outcome": "OUTCOME_OK", "output": "36\n"}}, {"text": "Lyon 15, Nice 21."}]}
        ]}
        JSON),
    $chat('chat: OpenAI-compatible variants: DeepSeek reasoning_content, OpenRouter reasoning_details, Mistral thinking chunks and named tool replies', <<<'JSON'
        [{"role": "user", "content": "Is 91 prime?"},
         {"role": "assistant", "reasoning_content": "91 = 7 x 13.", "content": "No."},
         {"role": "assistant", "reasoning_details": [{"type": "reasoning.text", "text": "Double-check."}, {"type": "reasoning.encrypted", "data": "abc"}], "content": "Still no."},
         {"role": "assistant", "content": [{"type": "thinking", "thinking": [{"type": "text", "text": "Use the tool."}]}], "tool_calls": [{"id": "D681PevKs", "type": "function", "function": {"name": "factor", "arguments": "{\"n\": 91}"}}]},
         {"role": "tool", "name": "factor", "tool_call_id": "D681PevKs", "content": "[7, 13]"}]
        JSON),
    $chat('chat: Ollama messages with thinking, images, object arguments and tool_name replies', <<<'JSON'
        {"model": "qwen3", "messages": [
          {"role": "user", "content": "What is in this picture, and the time in Tokyo?", "images": ["iVBORw0KGgo="]},
          {"role": "assistant", "content": "", "thinking": "Call the clock.", "tool_calls": [{"function": {"name": "clock", "arguments": {"tz": "Asia/Tokyo"}}}]},
          {"role": "tool", "tool_name": "clock", "content": "09:30"},
          {"role": "assistant", "content": "A cat; it is 09:30 in Tokyo."}
        ]}
        JSON),
    $chat('chat: Cohere v2 with a tool plan and document tool content', <<<'JSON'
        {"messages": [
          {"role": "system", "content": "Use the sales tool."},
          {"role": "user", "content": [{"type": "text", "text": "Sales on 29 Sep?"}]},
          {"role": "assistant", "tool_plan": "I will query the sales database.", "tool_calls": [{"id": "query_daily_sales_report_0", "type": "function", "function": {"name": "query_daily_sales_report", "arguments": "{\"day\":\"2023-09-29\"}"}}]},
          {"role": "tool", "tool_call_id": "query_daily_sales_report_0", "content": [{"type": "document", "document": {"data": "{\"total_sales_amount\": 10000}"}}]},
          {"role": "assistant", "content": [{"type": "text", "text": "Sales were 10,000."}]}
        ]}
        JSON),
    $chat('chat: AWS Bedrock Converse with system blocks, reasoning, tool use and a JSON error result', <<<'JSON'
        {"system": [{"text": "You are a librarian."}], "messages": [
          {"role": "user", "content": [{"text": "Find a book on owls."}, {"document": {"format": "pdf", "name": "catalogue", "source": {"bytes": "JVBERi0="}}}]},
          {"role": "assistant", "content": [{"reasoningContent": {"reasoningText": {"text": "Search the catalogue.", "signature": "x"}}}, {"toolUse": {"toolUseId": "tooluse_1", "name": "search", "input": {"q": "owls"}}}]},
          {"role": "user", "content": [{"toolResult": {"toolUseId": "tooluse_1", "status": "error", "content": [{"json": {"error": "index offline"}}]}}]},
          {"role": "assistant", "content": [{"text": "The catalogue is offline."}, {"image": {"format": "png", "source": {"bytes": "iVBO"}}}]}
        ]}
        JSON),
    $chat('chat: LangChain dicts: messages_to_dict, flat types, a constructor envelope and LangGraph state', <<<'JSON'
        {"messages": [
          {"type": "system", "data": {"content": "Be helpful.", "type": "system", "id": null}},
          {"type": "human", "content": "Search for owls", "id": "h1"},
          {"type": "ai", "content": "", "id": "a1", "tool_calls": [{"name": "search", "args": {"q": "owls"}, "id": "call_s1", "type": "tool_call"}]},
          {"type": "tool", "content": "Owls are birds.", "tool_call_id": "call_s1", "status": "error", "id": "t1"},
          {"lc": 1, "type": "constructor", "id": ["langchain", "schema", "messages", "AIMessage"], "kwargs": {"content": "Owls are birds.", "id": "a2"}}
        ]}
        JSON),
    $chat('chat: a Claude Code session: repeated events dropped by uuid, split assistant events, sidechains, meta and system events skipped, a summary kept', $jsonl([
        '{"type":"summary","summary":"Fixing the login bug","leafUuid":"u6"}',
        '{"type":"user","uuid":"u1","parentUuid":null,"isSidechain":false,"timestamp":"2026-01-05T10:00:00.000Z","message":{"role":"user","content":"Fix the login bug"}}',
        '{"type":"user","uuid":"u1b","parentUuid":"u1","isSidechain":false,"isMeta":true,"timestamp":"2026-01-05T10:00:00.100Z","message":{"role":"user","content":"<system-reminder>hidden</system-reminder>"}}',
        '{"type":"assistant","uuid":"u2","parentUuid":"u1b","isSidechain":false,"timestamp":"2026-01-05T10:00:02.000Z","message":{"id":"msg_A","role":"assistant","model":"claude-opus-4-1","content":[{"type":"thinking","thinking":"Look at auth.php.","signature":"Eo"}]}}',
        '{"type":"attachment","uuid":"u2a","parentUuid":"u2","isSidechain":false,"timestamp":"2026-01-05T10:00:02.100Z","attachment":{"type":"date","date":"2026-01-05"}}',
        '{"type":"assistant","uuid":"u3","parentUuid":"u2a","isSidechain":false,"timestamp":"2026-01-05T10:00:03.000Z","message":{"id":"msg_A","role":"assistant","model":"claude-opus-4-1","content":[{"type":"tool_use","id":"toolu_R","name":"Read","input":{"file_path":"auth.php"}}]}}',
        '{"type":"user","uuid":"u4","parentUuid":"u3","isSidechain":false,"timestamp":"2026-01-05T10:00:04.000Z","message":{"role":"user","content":[{"type":"tool_result","tool_use_id":"toolu_R","content":"<?php return false;"}]},"toolUseResult":{"type":"text"}}',
        '{"type":"user","uuid":"s1","parentUuid":"u4","isSidechain":true,"timestamp":"2026-01-05T10:00:04.500Z","message":{"role":"user","content":"sidechain prompt"}}',
        '{"type":"system","subtype":"turn_duration","uuid":"y1","parentUuid":"u4","isSidechain":false,"timestamp":"2026-01-05T10:00:04.600Z","content":"took 4s","isMeta":false}',
        '{"type":"assistant","uuid":"u5","parentUuid":"y1","isSidechain":false,"timestamp":"2026-01-05T10:00:05.000Z","message":{"id":"msg_B","role":"assistant","model":"claude-opus-4-1","content":[{"type":"text","text":"The check always fails; fixed."}]}}',
        '{"type":"user","uuid":"u1","parentUuid":null,"isSidechain":false,"timestamp":"2026-01-05T10:00:00.000Z","message":{"role":"user","content":"Fix the login bug"}}',
        '{"type":"user","uuid":"u6","parentUuid":"u5","isSidechain":false,"timestamp":"2026-01-05T10:01:00.000Z","message":{"role":"user","content":[{"type":"text","text":"Thanks!"}]}}',
    ])),
    $chat('chat: a Claude Code subagent transcript, every event a sidechain, is read', $jsonl([
        '{"type":"user","uuid":"a1","parentUuid":null,"isSidechain":true,"timestamp":"2026-01-05T11:00:00Z","message":{"role":"user","content":"Count the files"}}',
        '{"type":"assistant","uuid":"a2","parentUuid":"a1","isSidechain":true,"timestamp":"2026-01-05T11:00:01Z","message":{"id":"msg_C","role":"assistant","content":[{"type":"text","text":"There are 12."}]}}',
    ])),
    $chat('chat: conversation headings: a blank title falls back to the name, then to the id', <<<'JSON'
        [{"title": "  ", "name": "Trip  planning", "messages": [{"role": "user", "content": "Where to?"}]},
         {"title": "", "id": 42, "messages": [{"role": "user", "content": "Hi"}]}]
        JSON),
    $chat('chat: Claude Code messages sent while the agent works: a queued command, a coordinator message and a task notification; other meta events skipped', $jsonl([
        '{"type":"user","uuid":"q1","parentUuid":null,"isSidechain":true,"timestamp":"2026-01-06T10:00:00Z","message":{"role":"user","content":"Port the parser"}}',
        '{"type":"assistant","uuid":"q2","parentUuid":"q1","isSidechain":true,"timestamp":"2026-01-06T10:00:05Z","message":{"id":"msg_Q","role":"assistant","content":[{"type":"text","text":"Starting."}]}}',
        '{"type":"attachment","uuid":"q3","parentUuid":"q2","isSidechain":true,"timestamp":"2026-01-06T10:01:00Z","attachment":{"type":"queued_command","prompt":"Also keep the tests green."}}',
        '{"type":"attachment","uuid":"q4","parentUuid":"q3","isSidechain":true,"timestamp":"2026-01-06T10:01:01Z","attachment":{"type":"total_tokens_reminder","text":"lots left"}}',
        '{"type":"user","uuid":"q5","parentUuid":"q4","isSidechain":true,"isMeta":true,"origin":{"kind":"coordinator"},"timestamp":"2026-01-06T10:02:00Z","message":{"role":"user","content":"Use the shared fixtures."}}',
        '{"type":"user","uuid":"q6","parentUuid":"q5","isSidechain":true,"isMeta":true,"origin":{"kind":"task-notification"},"timestamp":"2026-01-06T10:03:00Z","message":{"role":"user","content":[{"type":"text","text":"Background build finished."}]}}',
        '{"type":"user","uuid":"q7","parentUuid":"q6","isSidechain":true,"isMeta":true,"timestamp":"2026-01-06T10:03:01Z","message":{"role":"user","content":"<system-reminder>internal</system-reminder>"}}',
        '{"type":"assistant","uuid":"q8","parentUuid":"q7","isSidechain":true,"timestamp":"2026-01-06T10:04:00Z","message":{"id":"msg_R","role":"assistant","content":[{"type":"text","text":"Done, tests green."}]}}',
    ])),
    $chat('chat: an OpenAI Codex CLI rollout: response items read, session meta and event messages skipped', $jsonl([
        '{"timestamp":"2026-02-01T09:00:00.000Z","type":"session_meta","payload":{"id":"sess-1","cwd":"/repo","base_instructions":{"text":"You are Codex."}}}',
        '{"timestamp":"2026-02-01T09:00:01.000Z","type":"response_item","payload":{"type":"message","role":"user","content":[{"type":"input_text","text":"Run the tests"}]}}',
        '{"timestamp":"2026-02-01T09:00:01.500Z","type":"event_msg","payload":{"type":"user_message","message":"Run the tests"}}',
        '{"timestamp":"2026-02-01T09:00:02.000Z","type":"response_item","payload":{"type":"reasoning","summary":[{"type":"summary_text","text":"Use the shell."}],"encrypted_content":"gAAA"}}',
        '{"timestamp":"2026-02-01T09:00:03.000Z","type":"response_item","payload":{"type":"function_call","name":"shell","arguments":"{\"command\":[\"npm\",\"test\"]}","call_id":"call_T"}}',
        '{"timestamp":"2026-02-01T09:00:09.000Z","type":"response_item","payload":{"type":"function_call_output","call_id":"call_T","output":"12 passing"}}',
        '{"timestamp":"2026-02-01T09:00:10.000Z","type":"response_item","payload":{"type":"message","role":"assistant","content":[{"type":"output_text","text":"All 12 tests pass."}]}}',
    ])),
    $chat('chat: a Gemini CLI checkpoint (history of Content)', <<<'JSON'
        {"history": [
          {"role": "user", "parts": [{"text": "List the src files"}]},
          {"role": "model", "parts": [{"functionCall": {"id": "ls-1", "name": "list_directory", "args": {"path": "src"}}}]},
          {"role": "user", "parts": [{"functionResponse": {"id": "ls-1", "name": "list_directory", "response": {"output": "a.ts\nb.ts"}}}]},
          {"role": "model", "parts": [{"text": "Two files: a.ts and b.ts."}]}
        ], "authType": "oauth-personal"}
        JSON),
    $chat('chat: a Gemini CLI session log with tool calls carrying their results and thoughts', $jsonl([
        '{"sessionId":"s-1","projectHash":"ab12","startTime":"2026-03-01T08:00:00.000Z","lastUpdated":"2026-03-01T08:01:00.000Z"}',
        '{"id":"m1","timestamp":"2026-03-01T08:00:01.000Z","type":"user","content":[{"text":"Read the README"}]}',
        '{"id":"m2","timestamp":"2026-03-01T08:00:03.000Z","type":"gemini","content":"It describes a CLI.","model":"gemini-2.5-pro","thoughts":[{"subject":"Reading","description":"Open README.md first.","timestamp":"2026-03-01T08:00:02.000Z"}],"toolCalls":[{"id":"rf-1","name":"read_file","args":{"path":"README.md"},"result":[{"functionResponse":{"id":"rf-1","name":"read_file","response":{"output":"# Tool"}}}],"status":"success"}]}',
        '{"id":"m3","timestamp":"2026-03-01T08:00:04.000Z","type":"info","content":"Checkpoint saved"}',
    ])),
    $chat('chat: a ChatGPT export: the current branch only, hidden messages skipped, a tool call by recipient, thoughts and epoch times', <<<'JSON'
        [{"title": "Trip planning", "create_time": 1717000000.5, "current_node": "n5", "conversation_id": "c-1", "mapping": {
          "root": {"id": "root", "message": null, "parent": null, "children": ["n1"]},
          "n1": {"id": "n1", "parent": "root", "children": ["n2a", "n2b"], "message": {"id": "n1", "author": {"role": "system", "name": null}, "create_time": null, "content": {"content_type": "text", "parts": [""]}, "metadata": {"is_visually_hidden_from_conversation": true}, "recipient": "all"}},
          "n2a": {"id": "n2a", "parent": "n1", "children": [], "message": {"id": "n2a", "author": {"role": "user"}, "create_time": 1717000001.25, "content": {"content_type": "text", "parts": ["Plan a day in Oslo (first draft)"]}, "recipient": "all"}},
          "n2b": {"id": "n2b", "parent": "n1", "children": ["n3"], "message": {"id": "n2b", "author": {"role": "user"}, "create_time": 1717000002, "content": {"content_type": "multimodal_text", "parts": [{"content_type": "image_asset_pointer", "asset_pointer": "file-service://file-1"}, "Plan a day in Oslo"]}, "recipient": "all"}},
          "n3": {"id": "n3", "parent": "n2b", "children": ["n4"], "message": {"id": "n3", "author": {"role": "assistant"}, "create_time": 1717000003, "content": {"content_type": "thoughts", "thoughts": [{"summary": "Weather", "content": "Check the forecast first."}]}, "metadata": {"model_slug": "o3"}, "recipient": "all"}},
          "n4": {"id": "n4", "parent": "n3", "children": ["n4t"], "message": {"id": "n4", "author": {"role": "assistant"}, "create_time": 1717000004, "content": {"content_type": "code", "language": "unknown", "text": "{\"q\": \"Oslo weather\"}"}, "metadata": {"model_slug": "o3"}, "recipient": "web.run"}},
          "n4t": {"id": "n4t", "parent": "n4", "children": ["n5"], "message": {"id": "n4t", "author": {"role": "tool", "name": "web.run"}, "create_time": 1717000005, "content": {"content_type": "text", "parts": ["Sunny, 20C"]}, "recipient": "all"}},
          "n5": {"id": "n5", "parent": "n4t", "children": [], "message": {"id": "n5", "author": {"role": "assistant"}, "create_time": 1717000006, "content": {"content_type": "text", "parts": ["Morning at the Opera House, ", "afternoon on the fjord."]}, "metadata": {"model_slug": "o3"}, "recipient": "all"}}
        }},
         {"title": "", "id": "c-2", "mapping": {"a": {"id": "a", "parent": null, "children": ["b"], "message": {"id": "a", "author": {"role": "user"}, "content": {"content_type": "text", "parts": ["Hi"]}}}, "b": {"id": "b", "parent": "a", "children": [], "message": {"id": "b", "author": {"role": "assistant"}, "content": {"content_type": "text", "parts": ["Hello!"]}}}}}]
        JSON),
    $chat('chat: a ChatGPT mapping without current_node follows the last child from the root', <<<'JSON'
        {"title": "Edits", "mapping": {
          "r": {"id": "r", "message": null, "parent": null, "children": ["u1"]},
          "u1": {"id": "u1", "parent": "r", "children": ["x1", "x2"], "message": {"id": "u1", "author": {"role": "user"}, "content": {"content_type": "text", "parts": ["Name a colour"]}}},
          "x1": {"id": "x1", "parent": "u1", "children": [], "message": {"id": "x1", "author": {"role": "assistant"}, "content": {"content_type": "text", "parts": ["Red"]}}},
          "x2": {"id": "x2", "parent": "u1", "children": [], "message": {"id": "x2", "author": {"role": "assistant"}, "content": {"content_type": "text", "parts": ["Blue"]}}}
        }}
        JSON),
    $chat('chat: a Claude.ai export: senders, content blocks over text, attachments, tool results matched by name, parent ids', <<<'JSON'
        [{"uuid": "conv-1", "name": "Budget review", "created_at": "2025-06-01T09:00:00.000000Z", "chat_messages": [
          {"uuid": "m1", "sender": "human", "text": "Review this budget", "content": [{"type": "text", "text": "Review this budget"}], "created_at": "2025-06-01T09:00:01.000000Z", "attachments": [{"file_name": "budget.xlsx", "extracted_content": "Rent 900"}], "files": [], "parent_message_uuid": "00000000-0000-4000-8000-000000000000"},
          {"uuid": "m2", "sender": "assistant", "text": "", "content": [{"type": "thinking", "thinking": "Rent looks high."}, {"type": "tool_use", "name": "web_search", "input": {"query": "average rent"}}, {"type": "tool_result", "name": "web_search", "content": [{"type": "text", "text": "Average rent is 800."}], "is_error": false}, {"type": "text", "text": "Rent is above average."}], "created_at": "2025-06-01T09:00:05.000000Z", "attachments": [], "files": [], "parent_message_uuid": "m1"},
          {"uuid": "m3", "sender": "human", "text": "Thanks", "content": [], "created_at": "2025-06-01T09:01:00.000000Z", "parent_message_uuid": "m2"}
        ]}]
        JSON),
    $chat('chat: JSON Lines of conversations (fine-tuning data) become untitled sections', $jsonl([
        '{"messages":[{"role":"system","content":"You are a drone pilot."},{"role":"user","content":"Take off"},{"role":"assistant","tool_calls":[{"id":"call_a","type":"function","function":{"name":"takeoff","arguments":"{\"altitude\": 10}"}}]}]}',
        '{"messages":[{"role":"user","content":"Land"},{"role":"assistant","content":"Landing now."}]}',
    ])),
    $chat('chat: strings decode escapes (a pair, a lone surrogate) and arguments keep numbers as written', <<<'JSON'
        [{"role": "user", "content": "Café 😀 \ud800 done\r\nnext"},
         {"role": "assistant", "tool_calls": [{"id": "c1", "type": "function", "function": {"name": "f", "arguments": "  {\"x\" : 1.50e+2, \"s\": \"a\\\"b\", \"u\": \"é\"}  "}}, {"id": "c2", "type": "function", "function": {"name": "g", "arguments": "not json"}}, {"id": "c3", "type": "function", "function": {"name": "h", "arguments": ""}}]}]
        JSON),
    $chat('chat: an empty array gives no blocks', '[]'),
    $chat('chat: invalid JSON is an error naming the byte', '{"messages": [{"role": "user", "content": "hi"}'),
    $chat('chat: an invalid JSON Lines record is an error naming the line', "{\"role\":\"user\",\"content\":\"hi\"}\n\n{\"role\":\"assistant\",\"content\":}\n"),
    $chat('chat: an object of no known shape is an error', '{"foo": [1, 2], "bar": {"baz": true}}'),
    $chat('chat: a list of nothing recognisable is an error', '[1, "two", {"x": 3}]'),
    $chat('chat: an agent session log with no message yet reads as no blocks', $jsonl([
        '{"type":"mode","mode":"default","sessionId":"5b1f"}',
        '{"type":"permission-mode","permissionMode":"default","sessionId":"5b1f"}',
        '{"type":"file-history-snapshot","messageId":"m1","snapshot":{}}',
    ])),
    $chat('chat: an agent session log of one record and no message reads as no blocks', '{"type":"mode","mode":"default","sessionId":"5b1f"}'),
    $chat('chat: typed records with no session and no message are an error', $jsonl(['{"type":"invoice","total":10}', '{"type":"refund","total":4}'])),
];
