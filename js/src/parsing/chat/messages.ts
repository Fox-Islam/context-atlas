import { trim } from '../../text.js';
import { JsonMap, JsonNumber } from './json.js';
import { callPart, filePlaceholder, first, idOf, partsOf, resultPart, type Part } from './parts.js';

/**
 * One message, event or item of a transcript in vendor-neutral form. `ids` holds every id the
 * message can be named by, the turn id first; `result` marks a tool message whose whole text is
 * one result.
 */
export interface Message {
    role: string;
    speakerFull: string | null;
    ids: string[];
    parent: string | null;
    time: string | null;
    parts: Part[];
    result: boolean;
    callId: string | null;
    tool: string | null;
    error: boolean;
}

/** A recognised item that gives no message (meta and bookkeeping events). */
export const SKIP = 'skip';

/** Lowercased role to speaker. */
const ROLES: Readonly<Record<string, string>> = {
    user: 'user',
    human: 'user',
    assistant: 'assistant',
    ai: 'assistant',
    model: 'assistant',
    bot: 'assistant',
    chatbot: 'assistant',
    gemini: 'assistant',
    system: 'system',
    developer: 'system',
    tool: 'tool',
    function: 'tool',
    ipython: 'tool',
};

/** `type` values that name a role (LangChain, Gemini CLI). */
const TYPE_ROLES: readonly string[] = ['human', 'ai', 'user', 'assistant', 'system', 'developer', 'tool', 'function', 'gemini', 'model'];

const TIME_KEYS: readonly string[] = ['timestamp', 'created_at', 'create_time', 'createdAt', 'time'];

const PARENT_KEYS: readonly string[] = ['parentUuid', 'parent_message_uuid', 'parent', 'parentId', 'parent_id'];

/**
 * The message an item holds (a message, a Responses API item, a session-log event or a LangChain
 * dict, recognised by its structure), SKIP for a recognised item with nothing to read, or null
 * for an item of no known shape (spec/formats/chat.md).
 */
export function readMessage(item: unknown): Message | typeof SKIP | null {
    if (!(item instanceof JsonMap)) {
        return null;
    }

    const queued = queuedCommand(item);

    if (queued !== null) {
        return queued;
    }

    const [inner, envelope] = unwrap(item);

    if (inner === null) {
        return envelope === null ? null : SKIP;
    }
    if (envelope?.get('isMeta') === true || inner.get('isMeta') === true) {
        const origin = envelope?.map('origin')?.string('kind') ?? null;

        return origin === null ? SKIP : message('user', origin, ids(inner, envelope), parentOf(inner, envelope), timeOf(inner, envelope), partsOf(inner.get('content')));
    }

    return itemMessage(inner, envelope);
}

/** The envelope fields that decide whether an item is kept: its `uuid` and `isSidechain`. */
export function envelopeOf(item: unknown): [string | null, boolean | null] {
    if (!(item instanceof JsonMap)) {
        return [null, null];
    }

    const sidechain = item.get('isSidechain');

    return [item.string('uuid'), typeof sidechain === 'boolean' ? sidechain : null];
}

/** The ids (`uuid`, `id`) and parent of an item that gives no message, so that `reply_to` can be followed through it. */
export function linksOf(item: unknown): [string[], string | null] {
    if (!(item instanceof JsonMap)) {
        return [[], null];
    }

    return [ids(item, null), parentOf(item, null)];
}

/** The speaker for a role: the table above for a known role (ASCII case ignored), else the role as written. */
export function speakerOf(role: string): string {
    const lower = asciiLower(role);

    return Object.hasOwn(ROLES, lower) ? ROLES[lower]! : role;
}

function message(role: string, speakerFull: string | null, idList: string[], parent: string | null, time: string | null, parts: Part[], result = false, callId: string | null = null, tool: string | null = null, error = false): Message {
    return { role, speakerFull, ids: idList, parent, time, parts, result, callId, tool, error };
}

/** A Claude Code `attachment` event of type `queued_command`: a user message sent while the agent was working, its text in `prompt`. */
function queuedCommand(item: JsonMap): Message | null {
    const attachment = item.string('type') === 'attachment' ? item.map('attachment') : null;

    if (attachment === null || attachment.string('type') !== 'queued_command' || !attachment.has('prompt')) {
        return null;
    }

    return message('user', 'queued', ids(item, null), parentOf(item, null), timeOf(item, null), partsOf(attachment.get('prompt')));
}

/** The message object an item holds and the envelope around it, if any. `[null, envelope]` marks a known bookkeeping event. */
function unwrap(item: JsonMap): [JsonMap | null, JsonMap | null] {
    const type = item.string('type');
    const payload = item.map('payload');

    if (type !== null && payload !== null) {
        return [type === 'response_item' ? payload : null, item];
    }
    if (item.map('message') !== null) {
        return [item.map('message'), item];
    }
    if (item.has('message') && item.get('message') === null && (item.has('parent') || item.has('children'))) {
        return [null, item];
    }
    if (type !== null && item.map('data') !== null) {
        return [item.map('data'), item];
    }
    if (type === 'constructor' && item.map('kwargs') !== null) {
        return [item.map('kwargs'), item];
    }
    if (type === 'system' && item.string('subtype') !== null) {
        return [null, item];
    }

    return [item, null];
}

function itemMessage(inner: JsonMap, envelope: JsonMap | null): Message | null {
    const type = inner.string('type') ?? '';
    const summary = inner.string('summary');

    if (type === 'summary' && summary !== null) {
        return named(inner, envelope, 'system', [['text', summary]]);
    }
    if (inner.get('isCompactSummary') === true || envelope?.get('isCompactSummary') === true) {
        return named(inner, envelope, 'system', partsOf(inner.get('content')));
    }
    if (type === 'reasoning') {
        return named(inner, envelope, 'assistant', reasoningItem(inner), false);
    }
    if (type === 'function_call_output' || type.endsWith('_call_output')) {
        return named(inner, envelope, 'tool', resultPart(inner.get('output'), inner.get('call_id'), null, false), false);
    }
    if (type === 'function_call' || type.endsWith('_call')) {
        return named(inner, envelope, 'assistant', callItem(inner, type), false);
    }

    const role = roleOf(inner, envelope);

    return role === null ? null : conversational(inner, envelope, speakerOf(role));
}

/** The raw role: `role`, `author.role`, `sender`, a `type` naming a role, the envelope's `type` naming a role, or `user` for a Gemini Content without one. */
function roleOf(inner: JsonMap, envelope: JsonMap | null): string | null {
    const role = inner.string('role') ?? inner.map('author')?.string('role') ?? inner.string('sender');

    if (role !== null) {
        return role;
    }

    const path = envelope?.list('id') ?? null;
    const last = path === null || path.length === 0 ? '' : path.at(-1);
    const match = /^(Human|AI|System|Tool|Function)Message(?:Chunk)?$/.exec(typeof last === 'string' ? last : '');

    if (match !== null) {
        return match[1]!;
    }
    for (const map of [inner, envelope]) {
        const type = map?.string('type') ?? null;

        if (type !== null && TYPE_ROLES.includes(asciiLower(type))) {
            return type;
        }
    }

    return inner.list('parts') !== null ? 'user' : null;
}

function conversational(inner: JsonMap, envelope: JsonMap | null, speaker: string): Message {
    let parts = [...reasoningFields(inner), ...contentParts(inner), ...extraText(inner), ...media(inner), ...toolCalls(inner)];
    const recipient = inner.string('recipient');

    if (speaker === 'assistant' && recipient !== null && recipient !== 'all') {
        parts = recipientCall(parts, recipient);
    }
    if (speaker !== 'tool') {
        return named(inner, envelope, speaker, parts);
    }

    const error = inner.get('is_error') === true || inner.get('status') === 'error';
    const tool = first(inner, ['name', 'tool_name']) ?? inner.map('author')?.string('name') ?? null;

    return message('tool', null, ids(inner, envelope), parentOf(inner, envelope), timeOf(inner, envelope), parts, true, idOf(inner.get('tool_call_id')), tool, error);
}

function named(inner: JsonMap, envelope: JsonMap | null, speaker: string, parts: Part[], withName = true): Message {
    return message(speaker, withName ? speakerFull(inner) : null, ids(inner, envelope), parentOf(inner, envelope), timeOf(inner, envelope), parts);
}

/** `name`, `author.name`, `model`, then `metadata.model_slug`. */
function speakerFull(inner: JsonMap): string | null {
    return first(inner, ['name']) ?? inner.map('author')?.string('name') ?? first(inner, ['model']) ?? inner.map('metadata')?.string('model_slug') ?? null;
}

/** The message's `id` and `uuid`, then the envelope's `uuid` and `id`. */
function ids(inner: JsonMap, envelope: JsonMap | null): string[] {
    const out: string[] = [];

    for (const [map, key] of [[inner, 'id'], [inner, 'uuid'], [envelope, 'uuid'], [envelope, 'id']] as const) {
        const id = map === null ? null : idOf(map.get(key));

        if (id !== null && !out.includes(id)) {
            out.push(id);
        }
    }

    return out;
}

function parentOf(inner: JsonMap, envelope: JsonMap | null): string | null {
    for (const map of [envelope, inner]) {
        for (const key of PARENT_KEYS) {
            const id = map === null ? null : idOf(map.get(key));

            if (id !== null) {
                return id;
            }
        }
    }

    return null;
}

function timeOf(inner: JsonMap, envelope: JsonMap | null): string | null {
    for (const map of [inner, envelope]) {
        for (const key of TIME_KEYS) {
            const time = map === null ? null : timeValue(map.get(key));

            if (time !== null) {
                return time;
            }
        }
    }

    return null;
}

/**
 * A string as given (when not blank), or a number of seconds since 1970 (milliseconds when its
 * integer part has 12 or more digits) as `YYYY-MM-DDTHH:MM:SSZ`.
 */
function timeValue(value: unknown): string | null {
    if (typeof value === 'string') {
        return trim(value) === '' ? null : value;
    }

    const match = value instanceof JsonNumber ? /^(\d{1,15})(?:\.\d+)?$/.exec(value.raw) : null;

    if (match === null) {
        return null;
    }

    const whole = Number(match[1]);
    const date = new Date((match[1]!.length >= 12 ? Math.floor(whole / 1000) : whole) * 1000);
    const pad = (number: number, width = 2): string => String(number).padStart(width, '0');

    return `${pad(date.getUTCFullYear(), 4)}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}T${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}:${pad(date.getUTCSeconds())}Z`;
}

function reasoningFields(inner: JsonMap): Part[] {
    const parts: Part[] = [];

    for (const key of ['reasoning_content', 'reasoning']) {
        const text = inner.string(key);

        if (text !== null) {
            parts.push(['reasoning', text]);
        }
    }
    if (inner.string('reasoning') === null) {
        const texts = (inner.list('reasoning_details') ?? []).map((detail) => (detail instanceof JsonMap ? first(detail, ['text', 'summary']) ?? '' : ''));
        parts.push(['reasoning', texts.filter((text) => text !== '').join('\n\n')]);
    }
    for (const key of ['thinking', 'tool_plan']) {
        const text = inner.string(key);

        if (text !== null) {
            parts.push(['reasoning', text]);
        }
    }
    for (const thought of inner.list('thoughts') ?? []) {
        if (thought instanceof JsonMap) {
            parts.push(['reasoning', [thought.string('subject') ?? '', thought.string('description') ?? ''].filter((text) => text !== '').join('\n\n')]);
        }
    }

    return parts;
}

function contentParts(inner: JsonMap): Part[] {
    const parts = inner.get('content') !== null ? partsOf(inner.get('content')) : partsOf(inner.list('parts'));
    const text = inner.get('text');

    return parts.length === 0 && typeof text === 'string' ? [['text', text]] : parts;
}

function extraText(inner: JsonMap): Part[] {
    const parts: Part[] = [];
    const refusal = inner.string('refusal');
    const transcript = inner.map('audio')?.string('transcript') ?? null;

    if (refusal !== null) {
        parts.push(['text', refusal]);
    }
    if (transcript !== null) {
        parts.push(['text', transcript]);
    }

    return parts;
}

function media(inner: JsonMap): Part[] {
    const parts: Part[] = (inner.list('images') ?? []).map(() => ['media', '[image]'] as const);

    for (const key of ['attachments', 'files']) {
        for (const file of inner.list(key) ?? []) {
            if (file instanceof JsonMap) {
                parts.push(['media', filePlaceholder(first(file, ['file_name', 'filename', 'name']))]);
            }
        }
    }

    return parts;
}

function toolCalls(inner: JsonMap): Part[] {
    const parts: Part[] = [];

    for (const call of inner.list('tool_calls') ?? []) {
        if (call instanceof JsonMap) {
            const fn = call.map('function') ?? call.map('custom');
            const args = fn !== null ? fn.get('arguments') ?? fn.get('input') : call.get('args') ?? call.get('arguments') ?? call.get('input');
            parts.push(...callPart(fn?.get('name') ?? call.get('name'), args, call.get('id') ?? call.get('call_id')));
        }
    }
    for (const call of inner.list('toolCalls') ?? []) {
        if (call instanceof JsonMap) {
            parts.push(...callPart(call.get('name'), call.get('args'), call.get('id')));

            if (call.has('result') && call.get('result') !== null) {
                parts.push(...resultPart(call.get('result'), call.get('id'), call.get('name'), call.get('status') === 'error'));
            }
        }
    }

    const legacy = inner.map('function_call');

    if (legacy !== null) {
        parts.push(...callPart(legacy.get('name'), legacy.get('arguments'), null));
    }

    return parts;
}

/** A ChatGPT assistant message addressed to a tool: its text becomes the call's arguments. */
function recipientCall(parts: readonly Part[], recipient: string): Part[] {
    const texts = parts.filter((each) => each[0] === 'text').map((each) => each[1]);

    return [...parts.filter((each) => each[0] !== 'text'), ...callPart(recipient, texts.join('\n\n'), null)];
}

function reasoningItem(item: JsonMap): Part[] {
    for (const key of ['summary', 'content']) {
        const text = (item.list(key) ?? []).map((each) => (each instanceof JsonMap ? each.string('text') ?? '' : '')).filter((each) => each !== '').join('\n\n');

        if (text !== '') {
            return [['reasoning', text]];
        }
    }

    return [];
}

/**
 * A Responses API tool call item: `function_call`, `custom_tool_call` and the built-in `*_call`
 * items (named by `name`, else the type without `_call`), plus its `output` when it carries one.
 */
function callItem(item: JsonMap, type: string): Part[] {
    const name = item.string('name') ?? type.slice(0, -5);
    let args: unknown = null;

    for (const key of ['arguments', 'input', 'action', 'code']) {
        if (item.has(key)) {
            args = item.get(key);
            break;
        }
    }

    const id = item.get('call_id') ?? item.get('id');
    const parts = callPart(name, args, id);

    if (item.has('output') && item.get('output') !== null) {
        parts.push(...resultPart(item.get('output'), id, name, item.string('error') !== null || item.map('error') !== null));
    }

    return parts;
}

function asciiLower(text: string): string {
    return text.replace(/[A-Z]/g, (char) => char.toLowerCase());
}
