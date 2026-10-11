# Chat and agent transcripts

How a chat or agent transcript from any model vendor becomes blocks (`spec/behaviour.md`,
Blocks): one paragraph per message carrying who spoke, a code block per tool call and per tool
result, and reasoning, system prompts and media as attachments, so a section of them is
conversational (`spec/prose.md`, Conversations). Shapes are recognised by structure, never by a
vendor flag, so one reader covers the APIs, SDK dumps, session logs and exports listed under
Sources. The PHP implementation is `php/src/Parsing/ChatParser.php` with helpers in
`php/src/Parsing/Chat/`; the cases are the `read` fixtures named `chat: ...`
(`php/tests/Support/read_chat_cases.php`).

The format is `chat`. It has no file extension: `.json` and `.jsonl` stay data formats
(`spec/markup.md`), and a caller passes `chat` to read a file as a transcript.

## Decoding

**Input.** UTF-8 (output for invalid UTF-8 is not part of the contract). A leading U+FEFF is
removed. Offsets are byte offsets into the UTF-8 text (a JavaScript port counts UTF-8 bytes, not
UTF-16 units).

**JSON** is RFC 8259, read by a hand scanner with no regex (a transcript holds strings of many
megabytes, which a regex-based tokenizer would choke on):

- Whitespace is space, tab, `\r`, `\n`. Objects and arrays may nest at most 512 deep; the
  bracket that would open level 513 is an error at its offset.
- Strings are decoded: the escapes `\" \\ \/ \b \f \n \r \t` and `\uXXXX`. A high surrogate
  escape followed directly by a low one is one code point; any other surrogate escape is U+FFFD.
  A raw control byte (U+0000-U+001F) or a bad escape makes the string invalid.
- Numbers follow `-?(0|[1-9]\d*)(\.\d+)?([eE][+-]?\d+)?`, the fraction and exponent taken only
  when digits follow (so `1.` reads `1` and then fails at `.`). A number is kept **as written**,
  never converted.
- Objects keep their members in source order. A repeated key keeps the place of its first
  occurrence and the value of its last. Ports must use an order-keeping map (a JavaScript object
  reorders integer-like keys).
- `true`, `false`, `null` as usual.

An error is `Invalid JSON at byte <b>.`, b being: for an invalid string (bad escape, raw
control byte, no closing quote) the offset of its opening quote; for a bad literal or number,
the offset of its first byte; for a missing `:`, `,`, `]` or `}` or an object key that is not a
string, the offset of the offending byte; for text after the value, the offset of its first
non-whitespace byte; for empty input, the end of the text.

**JSON or JSON Lines.** The text is first read as one JSON value. When that fails, it is JSON
Lines if, split on `\n`, at least two lines are non-blank (blank: only space, tab, `\r`) and the
first non-blank line alone is one JSON value. Then each non-blank line is read alone and the
records form a list, in order; a line that fails is the error
`Invalid JSONL at line <n>: Invalid JSON at byte <b>.` (n counting every line from 1, b within
the line). Otherwise the error of the whole-text read stands.

**Compact JSON**, used to print arguments and structured results: no whitespace; members in
order; numbers, `true`, `false`, `null` as written; strings in `"` with only `"` → `\"`,
`\` → `\\`, U+0008 `\b`, U+000C `\f`, U+000A `\n`, U+000D `\r`, U+0009 `\t` and other
U+0000-U+001F as `\u00xx` (lowercase hex) escaped; every other character, `/`, U+2028 and
U+2029 included, as is. Members named `encrypted_content` or `signature` are left out at every
depth (opaque blobs, often base64).

## Conversations

The decoded value (the **root**) resolves to conversations:

1. **Root object.**
   1. A `conversations` member that is an array: a list of conversations (step 3).
   2. The root is a **container** (below): one conversation.
   3. Its `conversation` member is a container object: that one conversation.
   4. The root is itself a recognised item (Items): one conversation of that item.
   5. Otherwise the error (below).
2. **Root array** (or JSON Lines records).
   1. Empty: no blocks.
   2. At least one element is a recognised item: one conversation whose items are the elements.
   3. Otherwise its elements that are container objects form a list of conversations (others
      are dropped); none is the error.
3. **A list of conversations**: each conversation, in order, gives a level-1 heading, then its
   blocks. The heading is `title`, else `name` (a string not empty after squash, squashed), else
   `id` (a string, or a number as written), `uuid`, `conversation_id` (non-empty strings), else
   `Conversation <n>`, n counting the list's conversations from 1. A single conversation (steps
   1.2-1.4, 2.2) has no heading.

Any other root (a string, number, `true`, `false`, `null`) is the error.

The error, a fixed string:
`A chat transcript must be a JSON array or JSON Lines of messages, an object holding them (messages, contents, history, input, items, chat_messages, mapping, ...), or a list of conversations.`

A **container** is an object for which the first rule that applies gives its **items**:

1. `mapping` is an object (ChatGPT export): the current branch. When `current_node` is a string
   naming a member of `mapping`, the branch is that node, its `parent`, that node's `parent`, and
   so on while the parent id (a string, or a number as written) names a member and has not been
   visited, reversed so it runs from the root. Otherwise the branch starts at the first member,
   in order, whose `parent` is not an id naming a member, and repeatedly moves to the last entry
   of `children` that names a member, until there is none or a node repeats.
2. The first of `chat_messages`, `messages`, `contents`, `history`, `items`, `data` that is an
   array.
3. `input`, `output` (Responses API request and response): `input`'s elements then `output`'s,
   when at least one of them is an array.

Otherwise the object is not a container.

A container's **system prompt** is the parts (Parts) of, in order, `system`,
`systemInstruction`, `system_instruction` and `instructions`, each read when it is a string, an
array or an object (an object with a `parts` array is read as that array). Its text parts,
joined as in Paragraphs, give one paragraph before the items, meta
`{speaker: "system", turn: "system", attachment: true}`, when not empty.

## Items

Each conversation's items are filtered, then read in order. A conversation's **position**
counter is an item's 0-based index in its items list (after the branch is taken, before any
filtering).

**Filtering.** An item that is an object with a string `uuid` already seen in this
conversation is dropped (session logs repeat earlier events on resume; the first wins). When
some item has `isSidechain: false`, every item with `isSidechain: true` is dropped (a Claude
Code session with subagent branches); when none has `false`, sidechain items are kept (a
subagent's own transcript has only sidechain events).

**Queued messages.** An item whose `type` is `attachment` and whose `attachment` object has
`type` `queued_command` and a `prompt` (Claude Code: a user message sent while the agent was
working) is a message of role `user`, speaker in full `queued`, the parts of `prompt`; its ids,
parent and time are read from the item as from a message object with no envelope.

**Unwrapping.** An item that is not an object is unrecognised. Otherwise the first rule that
applies gives its **message object** `m` and **envelope** `e`:

1. A string `type` and an object `payload` (Codex CLI rollout): when `type` is `response_item`,
   `m` = `payload`, `e` = the item; any other type is a **skip**.
2. An object `message` (Claude Code event, ChatGPT node, generic wrapper): `m` = it, `e` = the
   item.
3. `message` present and `null`, and `parent` or `children` present (ChatGPT root node): a skip.
4. A string `type` and an object `data` (LangChain `messages_to_dict`): `m` = `data`, `e` = the
   item.
5. `type` is `constructor` and `kwargs` is an object (LangChain serialised): `m` = `kwargs`,
   `e` = the item.
6. `type` is `system` and `subtype` is a string (Claude Code system event): a skip.
7. Otherwise `m` = the item, no envelope.

`isMeta: true` on `e` or `m` makes the item a skip, unless `e` has an object `origin` with a
string `kind` (Claude Code: a message from a coordinator, a peer session or a task
notification): then it is a message of role `user`, speaker in full that `kind`, the parts of
`m`'s `content`.

**Classifying** `m` (t = its `type` when a string, else empty), first rule that applies:

| Rule | Message |
| --- | --- |
| t = `summary` and `summary` is a non-empty string | role `system`, one text part: `summary` |
| `isCompactSummary: true` on `m` or `e` | role `system`, the parts of `content` |
| t = `reasoning` (Responses) | role `assistant`, one reasoning part: the `text` of each element of `summary` joined by a blank line (empty ones dropped); when that is empty, the same over `content`; when both are empty, no parts (encrypted reasoning is dropped) |
| t = `function_call_output` or ends with `_call_output` | role `tool`, one result part: content `output`, call id `call_id` |
| t = `function_call` or ends with `_call` | role `assistant`, a call part: name `name` (non-empty string) else t without `_call`; arguments the first member present of `arguments`, `input`, `action`, `code`; id `call_id` else `id`. When `output` is present and not null, also a result part: content `output`, the same id and name, error when `error` is a non-empty string or an object |
| a role (below) is found | a conversational message (below) |
| otherwise | unrecognised |

The **role**, first found: `role`, `author.role`, `sender` (non-empty strings); when `e`'s `id`
is a non-empty array whose last element matches `^(Human|AI|System|Tool|Function)Message(Chunk)?$`,
group 1; `m`'s then `e`'s `type` when its ASCII lowercase is one of `human ai user assistant
system developer tool function gemini model`; `user` when `m` has a `parts` array (a Gemini
Content without a role).

**Speakers**, from the role, ASCII-case-insensitive; any other role is kept as written:

| Role | Speaker |
| --- | --- |
| `user`, `human` | `user` |
| `assistant`, `ai`, `model`, `bot`, `chatbot`, `gemini` | `assistant` |
| `system`, `developer` | `system` |
| `tool`, `function`, `ipython` | `tool` |

Skips and unrecognised items give no blocks; a list counts as messages (Conversations 2.2) when
at least one element is anything but unrecognised.

**Conversational message** parts, in this order:

1. Reasoning: `reasoning_content` and `reasoning` (non-empty strings, each a part); when
   `reasoning` is not a non-empty string, `reasoning_details`' elements' `text` else `summary`
   joined by a blank line (encrypted entries have neither); `thinking`, `tool_plan` (non-empty
   strings); each element of `thoughts` (Gemini CLI): `subject` and `description` joined by a
   blank line.
2. Content: the parts of `content` when present and not null, else of `parts`. When this gives
   no part at all and `text` is a string, one text part of it (Claude.ai, whose `text` repeats
   its `content`).
3. `refusal`, then `audio.transcript` (non-empty strings): text parts.
4. Media: `[image]` for each element of `images`; `[file: <name>]` for each object in
   `attachments` then `files`, name the first non-empty string of `file_name`, `filename`,
   `name` (`[file]` when none). Attached text such as `extracted_content` is never inlined.
5. Calls: each object in `tool_calls`: with an object `function` (or `custom`) the name is its
   `name` and the arguments its `arguments` (else `input`); otherwise `name` and the first
   non-null of `args`, `arguments`, `input`; the id `id` else `call_id`. Then each object in
   `toolCalls` (Gemini CLI): call `name`, `args`, `id`, plus, when `result` is present and not
   null, a result part (content `result`, the same id and name, error when `status` is `error`).
   Then `function_call` (legacy): name and `arguments`, no id.

When the speaker is `assistant` and `recipient` is a non-empty string other than `all` (a
ChatGPT message addressed to a tool), the text parts are removed and one call part is appended:
name the recipient, arguments the removed texts joined by a blank line.

When the speaker is `tool`, the message is a **tool message**: its tool is `name`, else
`tool_name`, else `author.name`; its call id `tool_call_id`; error when `is_error` is `true` or
`status` is `error`.

**Message fields.**

- `speaker_full`, for conversational messages and summaries other than tool messages: the first
  non-empty string of `m.name`, `m.author.name`, `m.model`, `m.metadata.model_slug`; none for
  Responses call, output and reasoning items.
- **ids**, in order without repeats: `m.id`, `m.uuid`, `e.uuid`, `e.id`, each a non-empty
  string or a number as written. The first is the message's **turn**; with none, the turn is
  the position as a decimal string. Split events of one model reply (Claude Code writes one
  line per content block, all with the same `message.id`) therefore share a turn.
- **parent**: the first id among `parentUuid`, `parent_message_uuid`, `parent`, `parentId`,
  `parent_id`, looked up on `e`, then on `m`.
- **time**: the first of `timestamp`, `created_at`, `create_time`, `createdAt`, `time` on `m`,
  then on `e`, that is a string not blank after trim (kept exactly as given), or a number
  written `\d{1,15}(\.\d+)?`: seconds since 1970-01-01 UTC (milliseconds, divided by 1000 and
  floored, when the integer part has 12 or more digits), fraction dropped, written
  `YYYY-MM-DDTHH:MM:SSZ`.

## Parts

A **content value** gives parts: a string is one text part; an array gives its elements' parts
in order (a string element is a text part, an object as below, others nothing); an object with
a string `content_type` is ChatGPT content (below); any other object is read as one element.

**Typed elements** (string `type`):

| `type` | Part |
| --- | --- |
| `text`, `input_text`, `output_text` | text: `text` (when a string) |
| `refusal` | text: `refusal` |
| `thinking` | reasoning: `thinking` when a string, else the text parts of `thinking` (Mistral chunks) joined by a blank line |
| `reasoning` | reasoning: the first non-empty string of `reasoning`, `text` |
| `redacted_thinking` and every other type | nothing |
| `image`, `image_url`, `input_image`, `image_file` | media `[image]` |
| `input_audio`, `audio` | media `[audio]` |
| `video` | media `[video]` |
| `file`, `input_file` | media `[file: <name>]`: `filename`, `file_name`, `name`, then the same in a `file` object |
| `document` | with an object `document` (Cohere): text, its `data` when a string, else `data` as compact JSON; otherwise media `[file: <title, name or filename>]` |
| `tool_use`, `server_tool_use`, `mcp_tool_use` | call: `name`, arguments `input`, id `id` |
| `tool_result` or ending `_tool_result` | result: content `content`, call id `tool_use_id`, tool `name`, error when `is_error` is `true` |

**ChatGPT parts** (string `content_type`, no `type`): `image_asset_pointer` → `[image]`;
`audio_asset_pointer` and `real_time_user_audio_video_asset_pointer` → `[audio]`;
`audio_transcription` → text `text`; others nothing.

**Keyed elements** (no `type`, no `content_type`; Gemini parts and Bedrock blocks), first match:

| Member | Part |
| --- | --- |
| `text` (a string) | reasoning when `thought` is `true`, else text |
| `functionCall` / `function_call` (object) | call: `name`, arguments `args`, id `id` |
| `functionResponse` / `function_response` | result: content `response`, call id `id`, tool `name` |
| `toolCall` (Gemini server tool) | call: `toolName` else `toolType`, arguments `args`, id `id` |
| `toolResponse` | result: content `response`, call id `id` |
| `toolUse` (Bedrock) | call: `name`, arguments `input`, id `toolUseId` |
| `toolResult` | result: content `content`, call id `toolUseId`, error when `status` is `error` |
| `reasoningContent.reasoningText` | reasoning: its `text` (`redactedContent` gives nothing) |
| `inlineData` / `inline_data` / `fileData` / `file_data` | media by `mimeType` / `mime_type` (ASCII lowercase) prefix: `image/` `[image]`, `audio/` `[audio]`, `video/` `[video]`, else `[file: <displayName or display_name>]` |
| `executableCode` / `executable_code` | call: name `code_execution`, arguments its `code` |
| `codeExecutionResult` / `code_execution_result` | result: content `output`, tool `code_execution`, error unless `outcome` is absent or `OUTCOME_OK` |
| `image` / `video` (objects) | `[image]` / `[video]` |
| `document` (object) | `[file: <its name>]` |

**ChatGPT content** (by `content_type`): `thoughts` → one reasoning part, the `content` of each
element of `thoughts` joined by a blank line; `reasoning_recap`, `user_editable_context`,
`model_editable_context` → nothing; `tether_browsing_display` → text `result`; with a `parts`
array → the parts of it; otherwise text `text` (covers `code`, `execution_output`,
`tether_quote`, `system_error`).

**Call arguments**, printed: absent or `null` → empty; a string that is one JSON value →
that value as compact JSON (so `"{\"a\": 1}"` prints `{"a":1}`); any other string as given
(free-form custom-tool input); anything else → compact JSON. An empty or missing name drops the
call.

**Result content**, printed: a string as given; `null` → empty; an array → its elements joined
by a blank line, empty ones dropped, each: a string as is; an object whose parts are a single
text, media or result part → that part's text; an object whose only member is `json` → that
member as compact JSON; anything else → compact JSON; any other value → compact JSON.

An **id** is a non-empty string or a number as written.

## Blocks

**Paragraphs.** A part's text has `\r\n` and `\r` turned into `\n` and is trimmed
(`spec/behaviour.md`); texts are not squashed (messages keep their Markdown and code). "Joined"
means the non-empty texts joined by `\n\n`. A block whose trimmed text is empty is not made.

**Meta**, keys in this order, each only when it has a value:
`speaker, speaker_full, turn, time, reply_to, call, tool, kind, attachment, error`. A `reply_to`
equal to the block's own `turn` is dropped.

Each message, after the system prompt paragraph, gives blocks in the order of its parts, except
that all its text parts make **one** paragraph placed where the first text part stood, and all
its reasoning parts one paragraph where the first reasoning part stood. With `base` =
`{speaker, speaker_full, turn, time}` of the message and `R` its reply_to (below):

- **text** → paragraph, `base` + `reply_to: R`, + `attachment: true` when the speaker is
  `system` (system and developer prompts, summaries).
- **reasoning** → paragraph, `base` + `reply_to: R` + `kind: "reasoning"` + `attachment: true`.
- **media** → paragraph of the placeholder, `base` + `reply_to: R` + `attachment: true`. Base64
  data is never inlined.
- **call** (k = the 0-based index of this part among the message's call and result parts) →
  code block `<name>(<arguments>)`, `base` with `turn: <message turn>#<id, else k>`, `reply_to`
  the message turn when some block already carries it, else `R`; `call: <id>` when there is
  one; `tool: <name>`. The call is remembered (id, name, turn, unanswered).
- **result** (k as for calls) → code block of the printed content, meta `speaker: "tool"`,
  `turn: <message turn>#<id, else k>` (with `/result` appended when that equals the turn of
  the call it answers, as when a call and its result sit in one message), `time`, `reply_to`
  the answered call's turn, `tool` (the part's tool, else the answered call's name),
  `attachment: true`, `error: true` when flagged. The **answered call** is the latest
  remembered call with the result's id when it has one; without an id, the earliest unanswered
  call with the result's tool name; none otherwise. The call found is marked answered. Empty
  results give no block.

A **tool message** with no result part is one result: content the joined text and media
parts, call id, tool and error from the message, k = 0. A tool message with result parts is read
like any other message.

**reply_to** of a message (`R`): starting from its parent id, follow ids through earlier items
of the conversation (every kept item is registered under its ids, with its parent, including
items that gave no block or were skips or unrecognised, through their own `uuid`/`id` and
parent keys; the first registration of an id wins): the first such item whose last block's turn
exists and differs from the message's turn gives that turn. None when the chain leaves the
conversation, loops, or the format has no parent ids. So a Claude Code reply points past
attachment and meta events to the tool result before it, and split events of one reply do not
point at each other.

## Sources

Recognition by structure, per family (A: field facts verified in official API references or SDK
source; B: no official schema, verified on real or MIT-licensed samples):

| Shape | Recognised by | Mapping |
| --- | --- | --- |
| OpenAI Chat Completions (A) | `messages` array; `role` | `developer` → system; `tool_calls[].function.{name,arguments}` (JSON string), `tool_call_id`; legacy `function_call` + `function` role matched by `name`; parts `text`, `image_url`, `input_audio`, `file`, `refusal` |
| OpenAI Responses / Conversations (A) | `input`/`output` arrays, `data` list; item `type` | `message` parts `input_text`/`output_text`; `function_call` / `*_call` → calls by `call_id`; `*_output` → results; `reasoning.summary[].text`; `instructions` → system |
| Anthropic Messages (A) | `messages`; typed blocks | top-level `system`; `tool_use`/`tool_result` (`is_error`); `thinking` kept, `redacted_thinking` dropped; `server_tool_use` + `*_tool_result` |
| Gemini (A) | `contents`, Content `parts` keyed | `model` → assistant; `functionCall`/`functionResponse` (ids optional, matched by name); `thought: true`; `systemInstruction` |
| Mistral, Groq, Ollama, OpenRouter, DeepSeek, xAI (A) | OpenAI shape | `thinking` chunks (Mistral); `reasoning` (Groq, OpenRouter), `reasoning_details`; `reasoning_content` (DeepSeek, xAI); Ollama `thinking`, `images`, object `arguments`, `tool_name` |
| Cohere v2 (A) | OpenAI-like | `tool_plan` → reasoning; tool content `document.data` |
| Bedrock Converse (A) | keyed blocks | `toolUse`/`toolResult` (`status: error`, `{json}`), `reasoningContent`; `system` list |
| LangChain / LangGraph (A) | `type` human/ai/tool/system, `{type,data}`, constructor `kwargs` | `tool_calls[{name,args,id}]`, `tool_call_id`, `status: error` |
| Claude Code JSONL (B) | events with `message`, `uuid`, `parentUuid` | dedupe by `uuid`; split replies share `message.id`; `queued_command` attachments and `isMeta` events with an `origin` read as user messages; other `isMeta`, `system` subtypes, `attachment` and other types skipped; sidechains dropped unless all are |
| Codex CLI rollout (B, source) | `{type, payload}` | `response_item` payloads read as Responses items; other types skipped |
| Gemini CLI (B, source) | checkpoint `history`; session JSONL `type: user/gemini` | `toolCalls[{id,name,args,result,status}]`, `thoughts[{subject,description}]`; `info`/`error`/`warning` and metadata lines unrecognised |
| ChatGPT export (B) | `mapping`, `current_node` | current branch; `author.role/name`; `recipient` ≠ `all` → call; `thoughts`; hidden system nodes give no text; epoch `create_time` |
| Claude.ai export (B) | `chat_messages`, `sender` | `content` over `text`; `attachments`/`files` → `[file]`; tool results without ids matched by `name`; `parent_message_uuid` |

Not handled: Groq `raw` reasoning (`<think>` inline in `content` stays text); ChatGPT's
`metadata.is_visually_hidden_from_conversation` is not read (such nodes normally carry empty
text, which gives no block); Codex `event_msg` copies of messages are skipped by design.

- OpenAI Chat Completions: https://platform.openai.com/docs/api-reference/chat/create,
  https://developers.openai.com/api/reference/resources/chat/subresources/completions/methods/create,
  https://github.com/openai/openai-python/tree/main/src/openai/types/chat
- OpenAI Responses and Conversations: https://developers.openai.com/api/reference/resources/responses/methods/create,
  https://developers.openai.com/api/docs/guides/conversation-state,
  https://github.com/openai/openai-python/tree/main/src/openai/types/responses,
  https://github.com/openai/openai-python/blob/main/src/openai/types/conversations/conversation_item_list.py
- Anthropic Messages: https://platform.claude.com/docs/en/api/messages
- Gemini: https://ai.google.dev/api/generate-content#v1beta.Content,
  https://ai.google.dev/api/generate-content#v1beta.Part
- Mistral: https://docs.mistral.ai/capabilities/function_calling/,
  https://docs.mistral.ai/capabilities/reasoning/
- Groq: https://console.groq.com/docs/reasoning
- Ollama: https://github.com/ollama/ollama/blob/main/docs/api.md
- OpenRouter: https://openrouter.ai/docs/use-cases/reasoning-tokens
- DeepSeek: https://api-docs.deepseek.com/guides/thinking_mode
- xAI: https://docs.x.ai/developers/rest-api-reference/inference/chat-completions.md,
  https://docs.x.ai/developers/model-capabilities/text/reasoning.md
- Cohere v2: https://docs.cohere.com/reference/chat, https://docs.cohere.com/v2/docs/tool-use-overview
- AWS Bedrock Converse: https://docs.aws.amazon.com/bedrock/latest/APIReference/API_runtime_ContentBlock.html,
  https://docs.aws.amazon.com/bedrock/latest/APIReference/API_runtime_ToolResultBlock.html,
  https://docs.aws.amazon.com/bedrock/latest/APIReference/API_runtime_ReasoningContentBlock.html
- LangChain / LangGraph: https://github.com/langchain-ai/langchain/blob/master/libs/core/langchain_core/messages/utils.py,
  https://github.com/langchain-ai/langgraph/blob/main/libs/langgraph/langgraph/graph/message.py
- Claude Code: https://code.claude.com/docs/en/hooks (`transcript_path`); event fields from
  real session files; community notes https://claude-dev.tools/docs/jsonl-format
- Codex CLI: https://github.com/openai/codex/blob/main/codex-rs/history/src/rollout_payload.rs,
  https://github.com/openai/codex/blob/main/codex-rs/protocol/src/models.rs
- Gemini CLI: https://github.com/google-gemini/gemini-cli/blob/main/packages/core/src/core/logger.ts,
  https://github.com/google-gemini/gemini-cli/blob/main/packages/core/src/services/chatRecordingTypes.ts
- ChatGPT export: https://help.openai.com/en/articles/7260999-how-do-i-export-my-chatgpt-history-and-data,
  sample https://github.com/meltylabs/chorus/blob/main/resources/openai_conversations.json (MIT)
- Claude.ai export: https://support.claude.com/en/articles/9450526-how-can-i-export-my-claude-data,
  sample https://github.com/meltylabs/chorus/blob/main/resources/claude_conversations.json (MIT),
  https://github.com/osteele/claude-chat-viewer/blob/main/docs/schemas.md
