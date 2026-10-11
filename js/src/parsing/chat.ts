import { Block } from '../block.js';
import { ContextAtlasError } from '../errors.js';
import { squash } from '../text.js';
import { decodeChatJson, JsonMap, validChatJson } from './chat/json.js';
import { readMessage } from './chat/messages.js';
import { first, idOf, partsOf, type Part } from './chat/parts.js';
import { recognises, threadBlocks } from './chat/thread.js';

export const UNRECOGNISED_CHAT = 'A chat transcript must be a JSON array or JSON Lines of messages, an object holding them (messages, contents, history, input, items, chat_messages, mapping, ...), or a list of conversations.';

/** Members holding a conversation's items, in the order they are looked for. */
const ITEM_KEYS: readonly string[] = ['chat_messages', 'messages', 'contents', 'history', 'items', 'data'];

/**
 * Reads a chat or agent transcript from any model vendor (JSON or JSON Lines) into blocks: one
 * paragraph per message with `speaker`, `turn`, `time` and `reply_to` in meta, a code block per
 * tool call and per tool result, reasoning, system prompts and media as attachments. Shapes are
 * recognised by structure, not by a vendor flag (spec/formats/chat.md).
 *
 * @throws {ContextAtlasError} on invalid JSON or JSON Lines, or a value of no known shape
 */
export function parseChat(text: string): Block[] {
    const root = decode(text.startsWith('﻿') ? text.slice(1) : text);

    if (root instanceof JsonMap) {
        return fromObject(root);
    }
    if (!Array.isArray(root)) {
        throw new ContextAtlasError(UNRECOGNISED_CHAT);
    }
    if (root.length === 0 || recognises(root)) {
        return threadBlocks(root, []);
    }

    const conversations = root.filter((item): item is JsonMap => item instanceof JsonMap && itemsOf(item) !== null);

    if (conversations.length === 0) {
        throw new ContextAtlasError(UNRECOGNISED_CHAT);
    }

    return many(conversations);
}

/**
 * One JSON value, or the records of JSON Lines as a list: the text is JSON Lines when it is not
 * one JSON value, its first non-blank line is, and another non-blank line follows.
 */
function decode(text: string): unknown {
    try {
        return decodeChatJson(text);
    } catch (error) {
        if (!(error instanceof ContextAtlasError)) {
            throw error;
        }

        const lines = text.split('\n');
        const filled = lines.flatMap((line, n) => (line.replace(/^[ \t\r]+|[ \t\r]+$/g, '') !== '' ? [n] : []));

        if (filled.length < 2 || !validChatJson(lines[filled[0]!]!)) {
            throw error;
        }

        return filled.map((n) => {
            try {
                return decodeChatJson(lines[n]!);
            } catch (inner) {
                if (inner instanceof ContextAtlasError) {
                    throw new ContextAtlasError(`Invalid JSONL at line ${n + 1}: ${inner.message}`);
                }
                throw inner;
            }
        });
    }
}

function fromObject(root: JsonMap): Block[] {
    const list = root.list('conversations');

    if (list !== null) {
        return many(list.filter((item): item is JsonMap => item instanceof JsonMap && itemsOf(item) !== null));
    }

    const conversation = itemsOf(root) !== null ? root : root.map('conversation');
    const items = conversation === null ? null : itemsOf(conversation);

    if (conversation !== null && items !== null) {
        return threadBlocks(items, systemOf(conversation));
    }
    if (readMessage(root) !== null) {
        return threadBlocks([root], []);
    }

    throw new ContextAtlasError(UNRECOGNISED_CHAT);
}

/** Each conversation under a level-1 heading of its title. */
function many(conversations: readonly JsonMap[]): Block[] {
    return conversations.flatMap((conversation, n) => {
        const named = ['title', 'name'].map((key) => squash(conversation.string(key) ?? '')).find((value) => value !== '');
        const title = named
            ?? idOf(conversation.get('id'))
            ?? first(conversation, ['uuid', 'conversation_id'])
            ?? `Conversation ${n + 1}`;

        return [Block.heading(title, 1), ...threadBlocks(itemsOf(conversation) ?? [], systemOf(conversation))];
    });
}

/**
 * A conversation's items: the current branch of a ChatGPT `mapping`, else the first list among
 * ITEM_KEYS, else `input` followed by `output`; null when it holds none.
 */
function itemsOf(conversation: JsonMap): unknown[] | null {
    const mapping = conversation.map('mapping');

    if (mapping !== null) {
        return branch(mapping, conversation.string('current_node'));
    }
    for (const key of ITEM_KEYS) {
        const list = conversation.list(key);

        if (list !== null) {
            return list;
        }
    }

    const input = conversation.list('input');
    const output = conversation.list('output');

    return input === null && output === null ? null : [...(input ?? []), ...(output ?? [])];
}

/**
 * The nodes from the root to `current_node`, following `parent`; without a usable
 * `current_node`, from the first node with no parent in the mapping down its last children.
 */
function branch(mapping: JsonMap, current: string | null): unknown[] {
    const nodes = mapping.members;

    if (current === null || !isSet(nodes, current)) {
        return downward(nodes);
    }

    const path: unknown[] = [];
    const seen = new Set<string>();

    for (let id: string | null = current; id !== null && isSet(nodes, id) && !seen.has(id);) {
        seen.add(id);
        const node = nodes.get(id);
        path.push(node);
        id = node instanceof JsonMap ? idOf(node.get('parent')) : null;
    }

    return path.reverse();
}

function downward(nodes: ReadonlyMap<string, unknown>): unknown[] {
    let id: string | null = null;

    for (const [key, node] of nodes) {
        const parent = node instanceof JsonMap ? idOf(node.get('parent')) : null;

        if (parent === null || !isSet(nodes, parent)) {
            id = key;
            break;
        }
    }

    const path: unknown[] = [];
    const seen = new Set<string>();

    while (id !== null && isSet(nodes, id) && !seen.has(id)) {
        seen.add(id);
        const node = nodes.get(id);
        path.push(node);
        const children = node instanceof JsonMap ? (node.list('children') ?? []).filter((child) => {
            const childId = idOf(child);

            return childId !== null && isSet(nodes, childId);
        }) : [];
        id = children.length === 0 ? null : idOf(children.at(-1));
    }

    return path;
}

/** PHP's `isset($nodes[$id])`: present and not null. */
function isSet(nodes: ReadonlyMap<string, unknown>, id: string): boolean {
    return nodes.get(id) !== undefined && nodes.get(id) !== null;
}

/** The system prompt parts of a conversation: `system`, `systemInstruction` or `system_instruction`, and `instructions`, in that order. */
function systemOf(conversation: JsonMap): Part[] {
    const parts: Part[] = [];

    for (const key of ['system', 'systemInstruction', 'system_instruction', 'instructions']) {
        const given = conversation.get(key);
        const value = given instanceof JsonMap && given.list('parts') !== null ? given.list('parts') : given;

        if (typeof value === 'string' || Array.isArray(value) || value instanceof JsonMap) {
            parts.push(...partsOf(value));
        }
    }

    return parts;
}
