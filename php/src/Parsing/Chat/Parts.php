<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing\Chat;

/**
 * Message content in any vendor's shape read into Message parts: typed content blocks
 * (OpenAI, Anthropic, Mistral, Cohere, LangChain), keyed blocks (Gemini, Bedrock), ChatGPT
 * export content, and tool results. The rules are stated in spec/formats/chat.md.
 */
final class Parts
{
    private const array TEXT_TYPES = ['text', 'input_text', 'output_text'];

    private const array IMAGE_TYPES = ['image', 'image_url', 'input_image', 'image_file'];

    private const array CALL_TYPES = ['tool_use', 'server_tool_use', 'mcp_tool_use'];

    /**
     * @return list<array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}>
     */
    public static function of(mixed $content): array
    {
        if (is_string($content)) {
            return [['text', $content]];
        }
        if ($content instanceof JsonMap) {
            return $content->string('content_type') !== null ? self::chatgpt($content) : self::part($content);
        }
        $parts = [];
        foreach (is_array($content) ? $content : [] as $item) {
            array_push($parts, ...(is_string($item) ? [['text', $item]] : ($item instanceof JsonMap ? self::part($item) : [])));
        }

        return $parts;
    }

    /**
     * A tool result's content as text: a string as given; a list item by item (text, a media
     * placeholder, a Cohere document's data, a Bedrock json member, else compact JSON) joined
     * by a blank line; anything else as compact JSON.
     */
    public static function resultText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if ($content === null) {
            return '';
        }
        if (! is_array($content)) {
            return ChatJson::encode($content);
        }
        $texts = [];
        foreach ($content as $item) {
            $texts[] = self::resultItem($item);
        }

        return implode("\n\n", array_filter($texts, static fn (string $text): bool => $text !== ''));
    }

    /**
     * Call arguments as text: a string that is one JSON value re-serialised compactly, any other
     * string as given; a missing value empty; anything else compact JSON.
     */
    public static function arguments(mixed $arguments): string
    {
        if ($arguments === null) {
            return '';
        }
        if (! is_string($arguments)) {
            return ChatJson::encode($arguments);
        }

        return ChatJson::valid($arguments) ? ChatJson::encode(ChatJson::decode($arguments)) : $arguments;
    }

    /**
     * The placeholder for an attached file: `[file: <name>]`, or `[file]` with no name.
     */
    public static function file(?string $name): string
    {
        return $name === null ? '[file]' : '[file: ' . $name . ']';
    }

    /**
     * The first non-empty string among the members, or null.
     *
     * @param  list<string>  $keys
     */
    public static function first(JsonMap $map, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $map->string($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}>
     */
    private static function part(JsonMap $part): array
    {
        $type = $part->string('type');
        if ($type !== null) {
            return self::typed($part, $type);
        }
        $contentType = $part->string('content_type');
        if ($contentType !== null) {
            return self::pointer($part, $contentType);
        }

        return self::keyed($part);
    }

    /**
     * @return list<array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}>
     */
    private static function typed(JsonMap $part, string $type): array
    {
        return match (true) {
            in_array($type, self::TEXT_TYPES, true) => self::text($part->get('text')),
            $type === 'refusal' => self::text($part->get('refusal')),
            $type === 'thinking' => self::reasoning(is_string($part->get('thinking')) ? $part->get('thinking') : self::joinedText($part->get('thinking'))),
            $type === 'reasoning' => self::reasoning(self::first($part, ['reasoning', 'text']) ?? ''),
            in_array($type, self::IMAGE_TYPES, true) => [['media', '[image]']],
            $type === 'input_audio' || $type === 'audio' => [['media', '[audio]']],
            $type === 'video' => [['media', '[video]']],
            $type === 'file' || $type === 'input_file' => [['media', self::file(self::fileName($part))]],
            $type === 'document' => $part->map('document') !== null ? self::text(self::document($part->map('document'))) : [['media', self::file(self::first($part, ['title', 'name', 'filename']))]],
            in_array($type, self::CALL_TYPES, true) => self::call($part->get('name'), $part->get('input'), $part->get('id')),
            $type === 'tool_result' || str_ends_with($type, '_tool_result') => self::result($part->get('content'), $part->get('tool_use_id'), $part->get('name'), $part->get('is_error') === true),
            default => [],
        };
    }

    /**
     * A ChatGPT export part named by `content_type` inside multimodal content.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function pointer(JsonMap $part, string $contentType): array
    {
        return match ($contentType) {
            'image_asset_pointer' => [['media', '[image]']],
            'audio_asset_pointer', 'real_time_user_audio_video_asset_pointer' => [['media', '[audio]']],
            'audio_transcription' => self::text($part->get('text')),
            default => [],
        };
    }

    /**
     * A block known by its member names (Gemini parts, Bedrock content blocks).
     *
     * @return list<array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}>
     */
    private static function keyed(JsonMap $part): array
    {
        if (is_string($part->get('text'))) {
            return $part->get('thought') === true ? self::reasoning($part->get('text')) : self::text($part->get('text'));
        }
        $call = $part->map('functionCall') ?? $part->map('function_call');
        if ($call !== null) {
            return self::call($call->get('name'), $call->get('args'), $call->get('id'));
        }
        $response = $part->map('functionResponse') ?? $part->map('function_response');
        if ($response !== null) {
            return self::result($response->get('response'), $response->get('id'), $response->get('name'), false);
        }
        $use = $part->map('toolUse');
        if ($use !== null) {
            return self::call($use->get('name'), $use->get('input'), $use->get('toolUseId'));
        }
        $server = $part->map('toolCall');
        if ($server !== null) {
            return self::call($server->get('toolName') ?? $server->get('toolType'), $server->get('args'), $server->get('id'));
        }
        $serverResult = $part->map('toolResponse');
        if ($serverResult !== null) {
            return self::result($serverResult->get('response'), $serverResult->get('id'), null, false);
        }
        $result = $part->map('toolResult');
        if ($result !== null) {
            return self::result($result->get('content'), $result->get('toolUseId'), null, $result->get('status') === 'error');
        }

        return self::keyedRest($part);
    }

    /**
     * @return list<array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}>
     */
    private static function keyedRest(JsonMap $part): array
    {
        $reasoning = $part->map('reasoningContent')?->map('reasoningText');
        if ($reasoning !== null) {
            return self::reasoning($reasoning->string('text') ?? '');
        }
        $data = $part->map('inlineData') ?? $part->map('inline_data') ?? $part->map('fileData') ?? $part->map('file_data');
        if ($data !== null) {
            return [['media', self::mime($data)]];
        }
        $code = $part->map('executableCode') ?? $part->map('executable_code');
        if ($code !== null) {
            return self::call('code_execution', $code->string('code') ?? '', null);
        }
        $outcome = $part->map('codeExecutionResult') ?? $part->map('code_execution_result');
        if ($outcome !== null) {
            $ok = in_array($outcome->get('outcome'), [null, 'OUTCOME_OK'], true);

            return self::result($outcome->get('output'), null, 'code_execution', ! $ok);
        }

        return match (true) {
            $part->map('image') !== null => [['media', '[image]']],
            $part->map('video') !== null => [['media', '[video]']],
            $part->map('document') !== null => [['media', self::file($part->map('document')->string('name'))]],
            default => [],
        };
    }

    /**
     * ChatGPT export content (an object with `content_type`).
     *
     * @return list<array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}>
     */
    private static function chatgpt(JsonMap $content): array
    {
        $type = (string) $content->string('content_type');
        if ($type === 'thoughts') {
            $texts = [];
            foreach ($content->list('thoughts') ?? [] as $thought) {
                $texts[] = $thought instanceof JsonMap ? ($thought->string('content') ?? '') : '';
            }

            return self::reasoning(self::join($texts));
        }
        if (in_array($type, ['reasoning_recap', 'user_editable_context', 'model_editable_context'], true)) {
            return [];
        }
        if ($type === 'tether_browsing_display') {
            return self::text($content->get('result'));
        }
        if ($content->list('parts') !== null) {
            return self::of($content->list('parts'));
        }

        return self::text($content->get('text'));
    }

    private static function resultItem(mixed $item): string
    {
        if (is_string($item)) {
            return $item;
        }
        if (! $item instanceof JsonMap) {
            return ChatJson::encode($item);
        }
        $parts = self::part($item);
        if (count($parts) === 1 && in_array($parts[0][0], ['text', 'media', 'result'], true)) {
            return $parts[0][1];
        }
        if ($item->has('json') && count($item->members) === 1) {
            return ChatJson::encode($item->get('json'));
        }

        return ChatJson::encode($item);
    }

    private static function document(JsonMap $document): string
    {
        $data = $document->get('data');

        return is_string($data) ? $data : ChatJson::encode($data);
    }

    private static function fileName(JsonMap $part): ?string
    {
        return self::first($part, ['filename', 'file_name', 'name']) ?? ($part->map('file') !== null ? self::first($part->map('file'), ['filename', 'file_name', 'name']) : null);
    }

    private static function mime(JsonMap $data): string
    {
        $mime = mb_strtolower(self::first($data, ['mimeType', 'mime_type']) ?? '');

        return match (true) {
            str_starts_with($mime, 'image/') => '[image]',
            str_starts_with($mime, 'audio/') => '[audio]',
            str_starts_with($mime, 'video/') => '[video]',
            default => self::file(self::first($data, ['displayName', 'display_name'])),
        };
    }

    /**
     * The texts of a list of text chunks (Mistral thinking), joined by a blank line.
     */
    private static function joinedText(mixed $chunks): string
    {
        $texts = [];
        foreach (self::of($chunks) as $part) {
            $texts[] = $part[0] === 'text' ? $part[1] : '';
        }

        return self::join($texts);
    }

    /**
     * @param  list<string>  $texts
     */
    private static function join(array $texts): string
    {
        return implode("\n\n", array_filter($texts, static fn (string $text): bool => $text !== ''));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function text(mixed $text): array
    {
        return is_string($text) ? [['text', $text]] : [];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function reasoning(string $text): array
    {
        return [['reasoning', $text]];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string|null}>
     */
    public static function call(mixed $name, mixed $arguments, mixed $id): array
    {
        return is_string($name) && $name !== '' ? [['call', $name, self::arguments($arguments), self::id($id)]] : [];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string|null, 3: string|null, 4: bool}>
     */
    public static function result(mixed $content, mixed $id, mixed $name, bool $error): array
    {
        return [['result', self::resultText($content), self::id($id), is_string($name) && $name !== '' ? $name : null, $error]];
    }

    /**
     * An id: a non-empty string, or a number as written.
     */
    public static function id(mixed $id): ?string
    {
        if ($id instanceof JsonNumber) {
            return $id->raw;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }
}
