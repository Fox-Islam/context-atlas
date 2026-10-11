<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing\Chat;

use Phox\ContextAtlas\Text;

/**
 * Reads one item of a transcript (a message, a Responses API item, a session-log event or a
 * LangChain dict) into a Message, recognising it by its structure. The rules are stated in
 * spec/formats/chat.md.
 */
final class Messages
{
    /** a recognised item that gives no message (meta and bookkeeping events) */
    public const string SKIP = 'skip';

    /** @var array<string, string> lowercased role => speaker */
    private const array ROLES = [
        'user' => 'user',
        'human' => 'user',
        'assistant' => 'assistant',
        'ai' => 'assistant',
        'model' => 'assistant',
        'bot' => 'assistant',
        'chatbot' => 'assistant',
        'gemini' => 'assistant',
        'system' => 'system',
        'developer' => 'system',
        'tool' => 'tool',
        'function' => 'tool',
        'ipython' => 'tool',
    ];

    /** @var list<string> `type` values that name a role (LangChain, Gemini CLI) */
    private const array TYPE_ROLES = ['human', 'ai', 'user', 'assistant', 'system', 'developer', 'tool', 'function', 'gemini', 'model'];

    private const array TIME_KEYS = ['timestamp', 'created_at', 'create_time', 'createdAt', 'time'];

    private const array PARENT_KEYS = ['parentUuid', 'parent_message_uuid', 'parent', 'parentId', 'parent_id'];

    /**
     * The message an item holds, SKIP for a recognised item with nothing to read, or null for an
     * item of no known shape.
     */
    public static function read(mixed $item): Message|string|null
    {
        if (! $item instanceof JsonMap) {
            return null;
        }
        $queued = self::queued($item);
        if ($queued !== null) {
            return $queued;
        }
        [$inner, $envelope] = self::unwrap($item);
        if ($inner === null) {
            return $envelope === null ? null : self::SKIP;
        }
        if ($envelope?->get('isMeta') === true || $inner->get('isMeta') === true) {
            $origin = $envelope?->map('origin')?->string('kind');

            return $origin === null ? self::SKIP : new Message('user', $origin, self::ids($inner, $envelope), self::parent($inner, $envelope), self::time($inner, $envelope), Parts::of($inner->get('content')));
        }

        return self::item($inner, $envelope);
    }

    /**
     * A Claude Code `attachment` event of type `queued_command`: a user message sent while the
     * agent was working, its text in `prompt`.
     */
    private static function queued(JsonMap $item): ?Message
    {
        $attachment = $item->string('type') === 'attachment' ? $item->map('attachment') : null;
        if ($attachment?->string('type') !== 'queued_command' || ! $attachment->has('prompt')) {
            return null;
        }

        return new Message('user', 'queued', self::ids($item, null), self::parent($item, null), self::time($item, null), Parts::of($attachment->get('prompt')));
    }

    /**
     * The envelope fields that decide whether an item is kept: its `uuid` and `isSidechain`.
     *
     * @return array{?string, ?bool}
     */
    public static function envelope(mixed $item): array
    {
        if (! $item instanceof JsonMap) {
            return [null, null];
        }
        $sidechain = $item->get('isSidechain');

        return [$item->string('uuid'), is_bool($sidechain) ? $sidechain : null];
    }

    /**
     * The ids (`uuid`, `id`) and parent of an item that gives no message, so that `reply_to`
     * can be followed through it.
     *
     * @return array{list<string>, ?string}
     */
    public static function links(mixed $item): array
    {
        if (! $item instanceof JsonMap) {
            return [[], null];
        }

        return [self::ids($item, null), self::parent($item, null)];
    }

    /**
     * The speaker for a role: the table above for a known role (ASCII case ignored), else the
     * role as written.
     */
    public static function role(string $role): string
    {
        return self::ROLES[strtolower($role)] ?? $role;
    }

    /**
     * The message object an item holds and the envelope around it, if any. `[null, envelope]`
     * marks a known bookkeeping event.
     *
     * @return array{?JsonMap, ?JsonMap}
     */
    private static function unwrap(JsonMap $item): array
    {
        $type = $item->string('type');
        $payload = $item->map('payload');
        if ($type !== null && $payload !== null) {
            return [$type === 'response_item' ? $payload : null, $item];
        }
        if ($item->map('message') !== null) {
            return [$item->map('message'), $item];
        }
        if ($item->has('message') && $item->get('message') === null && ($item->has('parent') || $item->has('children'))) {
            return [null, $item];
        }
        if ($type !== null && $item->map('data') !== null) {
            return [$item->map('data'), $item];
        }
        if ($type === 'constructor' && $item->map('kwargs') !== null) {
            return [$item->map('kwargs'), $item];
        }
        if ($type === 'system' && $item->string('subtype') !== null) {
            return [null, $item];
        }

        return [$item, null];
    }

    private static function item(JsonMap $inner, ?JsonMap $envelope): ?Message
    {
        $type = $inner->string('type') ?? '';
        if ($type === 'summary' && $inner->string('summary') !== null) {
            return self::message($inner, $envelope, 'system', [['text', (string) $inner->string('summary')]]);
        }
        if ($inner->get('isCompactSummary') === true || $envelope?->get('isCompactSummary') === true) {
            return self::message($inner, $envelope, 'system', Parts::of($inner->get('content')));
        }
        if ($type === 'reasoning') {
            return self::message($inner, $envelope, 'assistant', self::reasoningItem($inner), false);
        }
        if ($type === 'function_call_output' || str_ends_with($type, '_call_output')) {
            return self::message($inner, $envelope, 'tool', Parts::result($inner->get('output'), $inner->get('call_id'), null, false), false);
        }
        if ($type === 'function_call' || str_ends_with($type, '_call')) {
            return self::message($inner, $envelope, 'assistant', self::callItem($inner, $type), false);
        }
        $role = self::roleOf($inner, $envelope);

        return $role === null ? null : self::conversational($inner, $envelope, self::role($role));
    }

    /**
     * The raw role: `role`, `author.role`, `sender`, a `type` naming a role, the envelope's
     * `type` naming a role, or `user` for a Gemini Content without one.
     */
    private static function roleOf(JsonMap $inner, ?JsonMap $envelope): ?string
    {
        $role = $inner->string('role') ?? $inner->map('author')?->string('role') ?? $inner->string('sender');
        if ($role !== null) {
            return $role;
        }
        $class = $envelope?->list('id');
        $class = is_array($class) && $class !== [] && is_string($class[count($class) - 1]) ? $class[count($class) - 1] : '';
        if (preg_match('/^(Human|AI|System|Tool|Function)Message(?:Chunk)?$/', $class, $match) === 1) {
            return $match[1];
        }
        foreach ([$inner, $envelope] as $map) {
            $type = $map?->string('type');
            if ($type !== null && in_array(strtolower($type), self::TYPE_ROLES, true)) {
                return $type;
            }
        }

        return $inner->list('parts') !== null ? 'user' : null;
    }

    private static function conversational(JsonMap $inner, ?JsonMap $envelope, string $speaker): Message
    {
        $parts = [...self::reasoningFields($inner), ...self::contentParts($inner), ...self::extraText($inner), ...self::media($inner), ...self::toolCalls($inner)];
        $recipient = $inner->string('recipient');
        if ($speaker === 'assistant' && $recipient !== null && $recipient !== 'all') {
            $parts = self::recipientCall($parts, $recipient);
        }
        if ($speaker !== 'tool') {
            return self::message($inner, $envelope, $speaker, $parts);
        }
        $error = $inner->get('is_error') === true || $inner->get('status') === 'error';
        $tool = Parts::first($inner, ['name', 'tool_name']) ?? $inner->map('author')?->string('name');

        return new Message('tool', null, self::ids($inner, $envelope), self::parent($inner, $envelope), self::time($inner, $envelope), $parts, true, Parts::id($inner->get('tool_call_id')), $tool, $error);
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}>  $parts
     */
    private static function message(JsonMap $inner, ?JsonMap $envelope, string $speaker, array $parts, bool $named = true): Message
    {
        return new Message($speaker, $named ? self::speakerFull($inner) : null, self::ids($inner, $envelope), self::parent($inner, $envelope), self::time($inner, $envelope), $parts);
    }

    /**
     * `name`, `author.name`, `model`, then `metadata.model_slug`.
     */
    private static function speakerFull(JsonMap $inner): ?string
    {
        return Parts::first($inner, ['name']) ?? $inner->map('author')?->string('name') ?? Parts::first($inner, ['model']) ?? $inner->map('metadata')?->string('model_slug');
    }

    /**
     * @return list<string> the message's `id` and `uuid`, then the envelope's `uuid` and `id`
     */
    private static function ids(JsonMap $inner, ?JsonMap $envelope): array
    {
        $ids = [];
        foreach ([[$inner, 'id'], [$inner, 'uuid'], [$envelope, 'uuid'], [$envelope, 'id']] as [$map, $key]) {
            $id = $map === null ? null : Parts::id($map->get($key));
            if ($id !== null && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private static function parent(JsonMap $inner, ?JsonMap $envelope): ?string
    {
        foreach ([$envelope, $inner] as $map) {
            foreach (self::PARENT_KEYS as $key) {
                $id = $map === null ? null : Parts::id($map->get($key));
                if ($id !== null) {
                    return $id;
                }
            }
        }

        return null;
    }

    private static function time(JsonMap $inner, ?JsonMap $envelope): ?string
    {
        foreach ([$inner, $envelope] as $map) {
            foreach (self::TIME_KEYS as $key) {
                $time = $map === null ? null : self::timeValue($map->get($key));
                if ($time !== null) {
                    return $time;
                }
            }
        }

        return null;
    }

    /**
     * A string as given (when not blank), or a number of seconds since 1970 (milliseconds when
     * its integer part has 12 or more digits) as `YYYY-MM-DDTHH:MM:SSZ`.
     */
    private static function timeValue(mixed $value): ?string
    {
        if (is_string($value)) {
            return Text::trim($value) === '' ? null : $value;
        }
        if (! $value instanceof JsonNumber || preg_match('/^(\d{1,15})(?:\.\d+)?$/', $value->raw, $match) !== 1) {
            return null;
        }
        $seconds = strlen($match[1]) >= 12 ? intdiv((int) $match[1], 1000) : (int) $match[1];

        return gmdate('Y-m-d\TH:i:s\Z', $seconds);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function reasoningFields(JsonMap $inner): array
    {
        $parts = [];
        foreach (['reasoning_content', 'reasoning'] as $key) {
            if ($inner->string($key) !== null) {
                $parts[] = ['reasoning', (string) $inner->string($key)];
            }
        }
        if ($inner->string('reasoning') === null) {
            $texts = [];
            foreach ($inner->list('reasoning_details') ?? [] as $detail) {
                $texts[] = $detail instanceof JsonMap ? (Parts::first($detail, ['text', 'summary']) ?? '') : '';
            }
            $parts[] = ['reasoning', implode("\n\n", array_filter($texts, static fn (string $t): bool => $t !== ''))];
        }
        foreach (['thinking', 'tool_plan'] as $key) {
            if ($inner->string($key) !== null) {
                $parts[] = ['reasoning', (string) $inner->string($key)];
            }
        }
        foreach ($inner->list('thoughts') ?? [] as $thought) {
            if ($thought instanceof JsonMap) {
                $parts[] = ['reasoning', implode("\n\n", array_filter([$thought->string('subject') ?? '', $thought->string('description') ?? ''], static fn (string $t): bool => $t !== ''))];
            }
        }

        return $parts;
    }

    /**
     * @return list<array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}>
     */
    private static function contentParts(JsonMap $inner): array
    {
        $parts = $inner->get('content') !== null ? Parts::of($inner->get('content')) : Parts::of($inner->list('parts'));
        if ($parts === [] && is_string($inner->get('text'))) {
            return [['text', $inner->get('text')]];
        }

        return $parts;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function extraText(JsonMap $inner): array
    {
        $parts = [];
        if ($inner->string('refusal') !== null) {
            $parts[] = ['text', (string) $inner->string('refusal')];
        }
        $transcript = $inner->map('audio')?->string('transcript');
        if ($transcript !== null) {
            $parts[] = ['text', $transcript];
        }

        return $parts;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function media(JsonMap $inner): array
    {
        $parts = [];
        foreach ($inner->list('images') ?? [] as $ignored) {
            $parts[] = ['media', '[image]'];
        }
        foreach (['attachments', 'files'] as $key) {
            foreach ($inner->list($key) ?? [] as $file) {
                if ($file instanceof JsonMap) {
                    $parts[] = ['media', Parts::file(Parts::first($file, ['file_name', 'filename', 'name']))];
                }
            }
        }

        return $parts;
    }

    /**
     * @return list<array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}>
     */
    private static function toolCalls(JsonMap $inner): array
    {
        $parts = [];
        foreach ($inner->list('tool_calls') ?? [] as $call) {
            if ($call instanceof JsonMap) {
                $function = $call->map('function') ?? $call->map('custom');
                $arguments = $function !== null ? ($function->get('arguments') ?? $function->get('input')) : ($call->get('args') ?? $call->get('arguments') ?? $call->get('input'));
                array_push($parts, ...Parts::call($function?->get('name') ?? $call->get('name'), $arguments, $call->get('id') ?? $call->get('call_id')));
            }
        }
        foreach ($inner->list('toolCalls') ?? [] as $call) {
            if ($call instanceof JsonMap) {
                array_push($parts, ...Parts::call($call->get('name'), $call->get('args'), $call->get('id')));
                if ($call->has('result') && $call->get('result') !== null) {
                    array_push($parts, ...Parts::result($call->get('result'), $call->get('id'), $call->get('name'), $call->get('status') === 'error'));
                }
            }
        }
        $legacy = $inner->map('function_call');
        if ($legacy !== null) {
            array_push($parts, ...Parts::call($legacy->get('name'), $legacy->get('arguments'), null));
        }

        return $parts;
    }

    /**
     * A ChatGPT assistant message addressed to a tool: its text becomes the call's arguments.
     *
     * @param  list<array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}>  $parts
     * @return list<array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}>
     */
    private static function recipientCall(array $parts, string $recipient): array
    {
        $texts = [];
        $rest = [];
        foreach ($parts as $part) {
            if ($part[0] === 'text') {
                $texts[] = $part[1];
            } else {
                $rest[] = $part;
            }
        }

        return [...$rest, ...Parts::call($recipient, implode("\n\n", $texts), null)];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function reasoningItem(JsonMap $item): array
    {
        foreach (['summary', 'content'] as $key) {
            $texts = [];
            foreach ($item->list($key) ?? [] as $part) {
                $texts[] = $part instanceof JsonMap ? ($part->string('text') ?? '') : '';
            }
            $text = implode("\n\n", array_filter($texts, static fn (string $t): bool => $t !== ''));
            if ($text !== '') {
                return [['reasoning', $text]];
            }
        }

        return [];
    }

    /**
     * A Responses API tool call item: `function_call`, `custom_tool_call` and the built-in
     * `*_call` items (named by `name`, else the type without `_call`), plus its `output` when it
     * carries one.
     *
     * @return list<array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}>
     */
    private static function callItem(JsonMap $item, string $type): array
    {
        $name = $item->string('name') ?? substr($type, 0, -5);
        $arguments = null;
        foreach (['arguments', 'input', 'action', 'code'] as $key) {
            if ($item->has($key)) {
                $arguments = $item->get($key);
                break;
            }
        }
        $id = $item->get('call_id') ?? $item->get('id');
        $parts = Parts::call($name, $arguments, $id);
        if ($item->has('output') && $item->get('output') !== null) {
            $error = $item->string('error') !== null || $item->map('error') !== null;
            array_push($parts, ...Parts::result($item->get('output'), $id, $name, $error));
        }

        return $parts;
    }
}
