<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\Exceptions\ContextAtlasException;
use Phox\ContextAtlas\Parsing\Chat\ChatJson;
use Phox\ContextAtlas\Parsing\Chat\JsonMap;
use Phox\ContextAtlas\Parsing\Chat\Messages;
use Phox\ContextAtlas\Parsing\Chat\Parts;
use Phox\ContextAtlas\Parsing\Chat\Thread;
use Phox\ContextAtlas\Text;

/**
 * Reads a chat or agent transcript from any model vendor (JSON or JSON Lines) into blocks: one
 * paragraph per message with `speaker`, `turn`, `time` and `reply_to` in meta, a code block per
 * tool call and per tool result, reasoning, system prompts and media as attachments. Shapes are
 * recognised by structure, not by a vendor flag. The rules are stated in spec/formats/chat.md.
 */
final class ChatParser
{
    public const string UNRECOGNISED = 'A chat transcript must be a JSON array or JSON Lines of messages, an object holding them (messages, contents, history, input, items, chat_messages, mapping, ...), or a list of conversations.';

    /** @var list<string> members holding a conversation's items, in the order they are looked for */
    private const array ITEM_KEYS = ['chat_messages', 'messages', 'contents', 'history', 'items', 'data'];

    /**
     * @return list<Block>
     *
     * @throws ContextAtlasException
     */
    public static function parse(string $text): array
    {
        $root = self::decode(str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text);
        if ($root instanceof JsonMap) {
            return self::object($root);
        }
        if (! is_array($root) || ! array_is_list($root)) {
            throw new ContextAtlasException(self::UNRECOGNISED);
        }
        if ($root === [] || Thread::recognises($root) || self::isAgentLog($root)) {
            return Thread::blocks($root, []);
        }
        $conversations = array_values(array_filter($root, static fn (mixed $item): bool => $item instanceof JsonMap && self::items($item) !== null));
        if ($conversations === []) {
            throw new ContextAtlasException(self::UNRECOGNISED);
        }

        return self::many($conversations);
    }

    /**
     * One JSON value, or the records of JSON Lines as a list: the text is JSON Lines when it is
     * not one JSON value, its first non-blank line is, and another non-blank line follows.
     *
     * @throws ContextAtlasException
     */
    private static function decode(string $text): mixed
    {
        try {
            return ChatJson::decode($text);
        } catch (ContextAtlasException $error) {
            $lines = explode("\n", $text);
            $filled = array_keys(array_filter($lines, static fn (string $line): bool => trim($line, " \t\r") !== ''));
            if (count($filled) < 2 || ! ChatJson::valid($lines[$filled[0]])) {
                throw $error;
            }
            $records = [];
            foreach ($filled as $n) {
                try {
                    $records[] = ChatJson::decode($lines[$n]);
                } catch (ContextAtlasException $e) {
                    throw new ContextAtlasException('Invalid JSONL at line ' . ($n + 1) . ': ' . $e->getMessage(), 0, $e);
                }
            }

            return $records;
        }
    }

    /**
     * @return list<Block>
     *
     * @throws ContextAtlasException
     */
    private static function object(JsonMap $root): array
    {
        $list = $root->list('conversations');
        if ($list !== null) {
            return self::many(array_values(array_filter($list, static fn (mixed $item): bool => $item instanceof JsonMap && self::items($item) !== null)));
        }
        $conversation = self::items($root) !== null ? $root : $root->map('conversation');
        if ($conversation !== null && self::items($conversation) !== null) {
            return Thread::blocks((array) self::items($conversation), self::system($conversation));
        }
        if (Messages::read($root) !== null) {
            return Thread::blocks([$root], []);
        }
        if (self::isAgentLog([$root])) {
            return [];
        }
        throw new ContextAtlasException(self::UNRECOGNISED);
    }

    /**
     * An agent's session log that holds no message yet: every record names its `type`, and one
     * names its `sessionId`, as Claude Code writes before the first prompt.
     *
     * @param  list<mixed>  $records
     */
    private static function isAgentLog(array $records): bool
    {
        $named = array_filter($records, static fn (mixed $record): bool => $record instanceof JsonMap && $record->string('type') !== null);
        $sessions = array_filter($records, static fn (mixed $record): bool => $record instanceof JsonMap && $record->string('sessionId') !== null);

        return count($named) === count($records) && $sessions !== [];
    }

    /**
     * Each conversation under a level-1 heading of its title.
     *
     * @param  list<JsonMap>  $conversations
     * @return list<Block>
     */
    private static function many(array $conversations): array
    {
        $blocks = [];
        foreach ($conversations as $n => $conversation) {
            $title = null;
            foreach (['title', 'name'] as $key) {
                $title ??= Text::squash((string) $conversation->string($key)) !== '' ? Text::squash((string) $conversation->string($key)) : null;
            }
            $title ??= Parts::id($conversation->get('id')) ?? Parts::first($conversation, ['uuid', 'conversation_id']) ?? 'Conversation ' . ($n + 1);
            $blocks[] = Block::heading($title, 1);
            array_push($blocks, ...Thread::blocks((array) self::items($conversation), self::system($conversation)));
        }

        return $blocks;
    }

    /**
     * A conversation's items: the current branch of a ChatGPT `mapping`, else the first list
     * among ITEM_KEYS, else `input` followed by `output`; null when it holds none.
     *
     * @return list<mixed>|null
     */
    private static function items(JsonMap $conversation): ?array
    {
        $mapping = $conversation->map('mapping');
        if ($mapping !== null) {
            return self::branch($mapping, $conversation->string('current_node'));
        }
        foreach (self::ITEM_KEYS as $key) {
            if ($conversation->list($key) !== null) {
                return $conversation->list($key);
            }
        }
        $input = $conversation->list('input');
        $output = $conversation->list('output');

        return $input === null && $output === null ? null : [...($input ?? []), ...($output ?? [])];
    }

    /**
     * The nodes from the root to `current_node`, following `parent`; without a usable
     * `current_node`, from the first node with no parent in the mapping down its last children.
     *
     * @return list<mixed>
     */
    private static function branch(JsonMap $mapping, ?string $current): array
    {
        $nodes = $mapping->members;
        if ($current === null || ! isset($nodes[$current])) {
            return self::downward($nodes);
        }
        $path = [];
        $seen = [];
        for ($id = $current; $id !== null && isset($nodes[$id]) && ! isset($seen[$id]);) {
            $seen[$id] = true;
            $path[] = $nodes[$id];
            $id = $nodes[$id] instanceof JsonMap ? Parts::id($nodes[$id]->get('parent')) : null;
        }

        return array_reverse($path);
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     * @return list<mixed>
     */
    private static function downward(array $nodes): array
    {
        $id = null;
        foreach ($nodes as $key => $node) {
            $parent = $node instanceof JsonMap ? Parts::id($node->get('parent')) : null;
            if ($parent === null || ! isset($nodes[$parent])) {
                $id = (string) $key;
                break;
            }
        }
        $path = [];
        $seen = [];
        while ($id !== null && isset($nodes[$id]) && ! isset($seen[$id])) {
            $seen[$id] = true;
            $node = $nodes[$id];
            $path[] = $node;
            $children = $node instanceof JsonMap ? array_values(array_filter($node->list('children') ?? [], static fn (mixed $child): bool => Parts::id($child) !== null && isset($nodes[(string) Parts::id($child)]))) : [];
            $id = $children === [] ? null : Parts::id($children[count($children) - 1]);
        }

        return $path;
    }

    /**
     * The system prompt parts of a conversation: `system`, `systemInstruction` or
     * `system_instruction`, and `instructions`, in that order.
     *
     * @return list<array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}>
     */
    private static function system(JsonMap $conversation): array
    {
        $parts = [];
        foreach (['system', 'systemInstruction', 'system_instruction', 'instructions'] as $key) {
            $value = $conversation->get($key);
            $value = $value instanceof JsonMap && $value->list('parts') !== null ? $value->list('parts') : $value;
            if (is_string($value) || is_array($value) || $value instanceof JsonMap) {
                array_push($parts, ...Parts::of($value));
            }
        }

        return $parts;
    }
}
