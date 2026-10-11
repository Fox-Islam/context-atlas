import { decodeChatJson, encodeChatJson, JsonMap, JsonNumber, validChatJson } from './json.js';

/**
 * One part of a message: `['text', text]`, `['reasoning', text]`, `['media', placeholder]`,
 * `['call', name, arguments, id]` or `['result', text, call id, tool name, error]`.
 */
export type Part = readonly [kind: string, text: string, second?: string | null, third?: string | null, error?: boolean];

const TEXT_TYPES: readonly string[] = ['text', 'input_text', 'output_text'];

const IMAGE_TYPES: readonly string[] = ['image', 'image_url', 'input_image', 'image_file'];

const CALL_TYPES: readonly string[] = ['tool_use', 'server_tool_use', 'mcp_tool_use'];

/**
 * Message content in any vendor's shape read into parts: typed content blocks (OpenAI,
 * Anthropic, Mistral, Cohere, LangChain), keyed blocks (Gemini, Bedrock), ChatGPT export content,
 * and tool results (spec/formats/chat.md).
 */
export function partsOf(content: unknown): Part[] {
    if (typeof content === 'string') {
        return [['text', content]];
    }
    if (content instanceof JsonMap) {
        return content.string('content_type') !== null ? chatgpt(content) : part(content);
    }

    return (Array.isArray(content) ? content : []).flatMap((item: unknown): Part[] => (typeof item === 'string' ? [['text', item]] : item instanceof JsonMap ? part(item) : []));
}

/**
 * A tool result's content as text: a string as given; a list item by item (text, a media
 * placeholder, a Cohere document's data, a Bedrock json member, else compact JSON) joined by a
 * blank line; anything else as compact JSON.
 */
export function resultText(content: unknown): string {
    if (typeof content === 'string') {
        return content;
    }
    if (content === null || content === undefined) {
        return '';
    }
    if (!Array.isArray(content)) {
        return encodeChatJson(content);
    }

    return content.map(resultItem).filter((text) => text !== '').join('\n\n');
}

/**
 * Call arguments as text: a string that is one JSON value re-serialised compactly, any other
 * string as given; a missing value empty; anything else compact JSON.
 */
export function argumentsText(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }
    if (typeof value !== 'string') {
        return encodeChatJson(value);
    }

    return validChatJson(value) ? encodeChatJson(decodeChatJson(value)) : value;
}

/** The placeholder for an attached file: `[file: <name>]`, or `[file]` with no name. */
export function filePlaceholder(name: string | null): string {
    return name === null ? '[file]' : `[file: ${name}]`;
}

/** The first non-empty string among the members, or null. */
export function first(map: JsonMap, keys: readonly string[]): string | null {
    for (const key of keys) {
        const value = map.string(key);

        if (value !== null) {
            return value;
        }
    }

    return null;
}

export function callPart(name: unknown, args: unknown, id: unknown): Part[] {
    return typeof name === 'string' && name !== '' ? [['call', name, argumentsText(args), idOf(id)]] : [];
}

export function resultPart(content: unknown, id: unknown, name: unknown, error: boolean): Part[] {
    return [['result', resultText(content), idOf(id), typeof name === 'string' && name !== '' ? name : null, error]];
}

/** An id: a non-empty string, or a number as written. */
export function idOf(id: unknown): string | null {
    if (id instanceof JsonNumber) {
        return id.raw;
    }

    return typeof id === 'string' && id !== '' ? id : null;
}

function part(map: JsonMap): Part[] {
    const type = map.string('type');

    if (type !== null) {
        return typed(map, type);
    }

    const contentType = map.string('content_type');

    return contentType !== null ? pointer(map, contentType) : keyed(map);
}

function typed(map: JsonMap, type: string): Part[] {
    if (TEXT_TYPES.includes(type)) {
        return text(map.get('text'));
    }
    if (type === 'refusal') {
        return text(map.get('refusal'));
    }
    if (type === 'thinking') {
        const thinking = map.get('thinking');

        return reasoning(typeof thinking === 'string' ? thinking : joinedText(thinking));
    }
    if (type === 'reasoning') {
        return reasoning(first(map, ['reasoning', 'text']) ?? '');
    }
    if (IMAGE_TYPES.includes(type)) {
        return [['media', '[image]']];
    }
    if (type === 'input_audio' || type === 'audio') {
        return [['media', '[audio]']];
    }
    if (type === 'video') {
        return [['media', '[video]']];
    }
    if (type === 'file' || type === 'input_file') {
        return [['media', filePlaceholder(fileName(map))]];
    }
    if (type === 'document') {
        const document = map.map('document');

        return document !== null ? text(documentText(document)) : [['media', filePlaceholder(first(map, ['title', 'name', 'filename']))]];
    }
    if (CALL_TYPES.includes(type)) {
        return callPart(map.get('name'), map.get('input'), map.get('id'));
    }
    if (type === 'tool_result' || type.endsWith('_tool_result')) {
        return resultPart(map.get('content'), map.get('tool_use_id'), map.get('name'), map.get('is_error') === true);
    }

    return [];
}

/** A ChatGPT export part named by `content_type` inside multimodal content. */
function pointer(map: JsonMap, contentType: string): Part[] {
    switch (contentType) {
        case 'image_asset_pointer':
            return [['media', '[image]']];
        case 'audio_asset_pointer':
        case 'real_time_user_audio_video_asset_pointer':
            return [['media', '[audio]']];
        case 'audio_transcription':
            return text(map.get('text'));
        default:
            return [];
    }
}

/** A block known by its member names (Gemini parts, Bedrock content blocks). */
function keyed(map: JsonMap): Part[] {
    const value = map.get('text');

    if (typeof value === 'string') {
        return map.get('thought') === true ? reasoning(value) : text(value);
    }

    const call = map.map('functionCall') ?? map.map('function_call');

    if (call !== null) {
        return callPart(call.get('name'), call.get('args'), call.get('id'));
    }

    const response = map.map('functionResponse') ?? map.map('function_response');

    if (response !== null) {
        return resultPart(response.get('response'), response.get('id'), response.get('name'), false);
    }

    const use = map.map('toolUse');

    if (use !== null) {
        return callPart(use.get('name'), use.get('input'), use.get('toolUseId'));
    }

    const server = map.map('toolCall');

    if (server !== null) {
        return callPart(server.get('toolName') ?? server.get('toolType'), server.get('args'), server.get('id'));
    }

    const serverResult = map.map('toolResponse');

    if (serverResult !== null) {
        return resultPart(serverResult.get('response'), serverResult.get('id'), null, false);
    }

    const result = map.map('toolResult');

    if (result !== null) {
        return resultPart(result.get('content'), result.get('toolUseId'), null, result.get('status') === 'error');
    }

    return keyedRest(map);
}

function keyedRest(map: JsonMap): Part[] {
    const thought = map.map('reasoningContent')?.map('reasoningText') ?? null;

    if (thought !== null) {
        return reasoning(thought.string('text') ?? '');
    }

    const data = map.map('inlineData') ?? map.map('inline_data') ?? map.map('fileData') ?? map.map('file_data');

    if (data !== null) {
        return [['media', mime(data)]];
    }

    const code = map.map('executableCode') ?? map.map('executable_code');

    if (code !== null) {
        return callPart('code_execution', code.string('code') ?? '', null);
    }

    const outcome = map.map('codeExecutionResult') ?? map.map('code_execution_result');

    if (outcome !== null) {
        const ok = outcome.get('outcome') === null || outcome.get('outcome') === 'OUTCOME_OK';

        return resultPart(outcome.get('output'), null, 'code_execution', !ok);
    }
    if (map.map('image') !== null) {
        return [['media', '[image]']];
    }
    if (map.map('video') !== null) {
        return [['media', '[video]']];
    }

    const document = map.map('document');

    return document !== null ? [['media', filePlaceholder(document.string('name'))]] : [];
}

/** ChatGPT export content (an object with `content_type`). */
function chatgpt(content: JsonMap): Part[] {
    const type = content.string('content_type') ?? '';

    if (type === 'thoughts') {
        return reasoning(join((content.list('thoughts') ?? []).map((thought) => (thought instanceof JsonMap ? thought.string('content') ?? '' : ''))));
    }
    if (['reasoning_recap', 'user_editable_context', 'model_editable_context'].includes(type)) {
        return [];
    }
    if (type === 'tether_browsing_display') {
        return text(content.get('result'));
    }

    const parts = content.list('parts');

    return parts !== null ? partsOf(parts) : text(content.get('text'));
}

function resultItem(item: unknown): string {
    if (typeof item === 'string') {
        return item;
    }
    if (!(item instanceof JsonMap)) {
        return encodeChatJson(item);
    }

    const parts = part(item);

    if (parts.length === 1 && ['text', 'media', 'result'].includes(parts[0]![0])) {
        return parts[0]![1];
    }
    if (item.has('json') && item.members.size === 1) {
        return encodeChatJson(item.get('json'));
    }

    return encodeChatJson(item);
}

function documentText(document: JsonMap): string {
    const data = document.get('data');

    return typeof data === 'string' ? data : encodeChatJson(data);
}

function fileName(map: JsonMap): string | null {
    const file = map.map('file');

    return first(map, ['filename', 'file_name', 'name']) ?? (file !== null ? first(file, ['filename', 'file_name', 'name']) : null);
}

function mime(data: JsonMap): string {
    const type = (first(data, ['mimeType', 'mime_type']) ?? '').toLowerCase();

    if (type.startsWith('image/')) {
        return '[image]';
    }
    if (type.startsWith('audio/')) {
        return '[audio]';
    }
    if (type.startsWith('video/')) {
        return '[video]';
    }

    return filePlaceholder(first(data, ['displayName', 'display_name']));
}

/** The texts of a list of text chunks (Mistral thinking), joined by a blank line. */
function joinedText(chunks: unknown): string {
    return join(partsOf(chunks).map((each) => (each[0] === 'text' ? each[1] : '')));
}

function join(texts: readonly string[]): string {
    return texts.filter((each) => each !== '').join('\n\n');
}

function text(value: unknown): Part[] {
    return typeof value === 'string' ? [['text', value]] : [];
}

function reasoning(value: string): Part[] {
    return [['reasoning', value]];
}
