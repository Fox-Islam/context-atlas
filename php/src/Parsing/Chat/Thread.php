<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Parsing\Chat;

use Phox\ContextAtlas\Block;
use Phox\ContextAtlas\BlockType;
use Phox\ContextAtlas\Text;

/**
 * Turns one conversation's items into blocks: a paragraph per message, a code block per tool
 * call and per tool result, attachments marked, and `turn`, `reply_to` and `call` links worked
 * out across the conversation. The rules are stated in spec/formats/chat.md.
 */
final class Thread
{
    private const array META_ORDER = ['speaker', 'speaker_full', 'turn', 'time', 'reply_to', 'call', 'tool', 'kind', 'attachment', 'error'];

    /** @var list<Block> */
    private array $blocks = [];

    /** @var array<string, array{parent: ?string, last: ?string}> message id => its parent and the turn of its last block */
    private array $byId = [];

    /** @var array<string, true> turns some block already carries */
    private array $turns = [];

    /** @var list<array{id: ?string, tool: string, turn: string, answered: bool}> */
    private array $calls = [];

    /**
     * @param  list<mixed>  $items
     * @param  list<array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}>  $system  the conversation's system prompt parts
     * @return list<Block>
     */
    public static function blocks(array $items, array $system): array
    {
        $thread = new self();
        $text = self::joined($system, 'text');
        if ($text !== '') {
            $thread->add(BlockType::Paragraph, $text, ['speaker' => 'system', 'turn' => 'system', 'attachment' => true]);
        }
        foreach (self::kept($items) as $position => $item) {
            $message = Messages::read($item);
            if ($message instanceof Message) {
                $thread->message($message, (string) $position);
            } else {
                [$ids, $parent] = Messages::links($item);
                foreach ($ids as $id) {
                    $thread->byId[$id] ??= ['parent' => $parent, 'last' => null];
                }
            }
        }

        return $thread->blocks;
    }

    /**
     * Whether any item is a recognised one.
     *
     * @param  list<mixed>  $items
     */
    public static function recognises(array $items): bool
    {
        foreach ($items as $item) {
            if (Messages::read($item) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The items left after dropping a repeated `uuid` (the first is kept) and, when some item
     * has `isSidechain: false`, every item with `isSidechain: true`. Keys are the positions.
     *
     * @param  list<mixed>  $items
     * @return array<int, mixed>
     */
    private static function kept(array $items): array
    {
        $main = false;
        foreach ($items as $item) {
            $main = $main || Messages::envelope($item)[1] === false;
        }
        $seen = [];
        $kept = [];
        foreach ($items as $position => $item) {
            [$uuid, $sidechain] = Messages::envelope($item);
            if (($uuid !== null && isset($seen[$uuid])) || ($main && $sidechain === true)) {
                continue;
            }
            if ($uuid !== null) {
                $seen[$uuid] = true;
            }
            $kept[$position] = $item;
        }

        return $kept;
    }

    private function message(Message $message, string $position): void
    {
        $turn = $message->ids[0] ?? $position;
        $replyTo = $this->replyTo($message->parent, $turn);
        $base = ['speaker' => $message->role, 'speaker_full' => $message->speakerFull, 'turn' => $turn, 'time' => $message->time];
        $before = count($this->blocks);
        $hasResults = false;
        foreach ($message->parts as $part) {
            $hasResults = $hasResults || $part[0] === 'result';
        }

        if ($message->result && ! $hasResults) {
            $this->result($base, ['result', self::joined($message->parts, 'text', 'media'), $message->callId, $message->tool, $message->error], 0);
        } else {
            $this->parts($message, $base, $replyTo);
        }

        $last = count($this->blocks) > $before ? $this->blocks[count($this->blocks) - 1]->meta['turn'] : null;
        foreach ($message->ids as $id) {
            $this->byId[$id] ??= ['parent' => $message->parent, 'last' => is_string($last) ? $last : null];
        }
    }

    /**
     * Blocks in the order of the parts, except that all text parts make one paragraph where the
     * first stood, and all reasoning parts one where the first stood.
     *
     * @param  array<string, mixed>  $base
     */
    private function parts(Message $message, array $base, ?string $replyTo): void
    {
        $attachment = $message->role === 'system';
        $done = ['text' => false, 'reasoning' => false];
        $k = 0;
        foreach ($message->parts as $part) {
            $kind = $part[0];
            if ($kind === 'text' || $kind === 'reasoning') {
                if (! $done[$kind]) {
                    $done[$kind] = true;
                    $text = self::joined($message->parts, $kind);
                    $extra = $kind === 'reasoning' ? ['kind' => 'reasoning', 'attachment' => true] : ($attachment ? ['attachment' => true] : []);
                    if ($text !== '') {
                        $this->add(BlockType::Paragraph, $text, [...$base, 'reply_to' => $replyTo, ...$extra]);
                    }
                }
            } elseif ($kind === 'media') {
                $this->add(BlockType::Paragraph, $part[1], [...$base, 'reply_to' => $replyTo, 'attachment' => true]);
            } elseif ($kind === 'call') {
                $this->call($base, $part, $k++, $replyTo);
            } else {
                $this->result($base, $part, $k++);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}  $part
     */
    private function call(array $base, array $part, int $k, ?string $replyTo): void
    {
        $id = $part[3] ?? null;
        $turn = $base['turn'] . '#' . ($id ?? (string) $k);
        $answers = isset($this->turns[$base['turn']]) ? $base['turn'] : $replyTo;
        $this->calls[] = ['id' => $id, 'tool' => $part[1], 'turn' => $turn, 'answered' => false];
        $this->add(BlockType::Code, $part[1] . '(' . ($part[2] ?? '') . ')', [...$base, 'turn' => $turn, 'reply_to' => $answers, 'call' => $id, 'tool' => $part[1]]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}  $part
     */
    private function result(array $base, array $part, int $k): void
    {
        $id = $part[2] ?? null;
        $call = $this->answer($id, $part[3] ?? null);
        $tool = $part[3] ?? ($call === null ? null : $this->calls[$call]['tool']);
        $text = Text::trim(self::lines($part[1]));
        if ($text === '') {
            return;
        }
        $turn = $base['turn'] . '#' . ($id ?? (string) $k);
        $answers = $call === null ? null : $this->calls[$call]['turn'];
        $turn = $turn === $answers ? $turn . '/result' : $turn;
        $meta = ['speaker' => 'tool', 'turn' => $turn, 'time' => $base['time'], 'reply_to' => $answers, 'tool' => $tool, 'attachment' => true];
        $this->add(BlockType::Code, $text, ($part[4] ?? false) ? [...$meta, 'error' => true] : $meta);
    }

    /**
     * The index of the call a result answers: the latest earlier call with its id, else the
     * earliest unanswered earlier call with its tool name; null when none.
     */
    private function answer(?string $id, ?string $tool): ?int
    {
        $found = null;
        foreach ($this->calls as $i => $call) {
            if ($id !== null ? $call['id'] === $id : ($tool !== null && $call['tool'] === $tool && ! $call['answered'] && $found === null)) {
                $found = $i;
            }
        }
        if ($found !== null) {
            $this->calls[$found]['answered'] = true;
        }

        return $found;
    }

    /**
     * The turn of the last block of the nearest ancestor (followed by parent ids through items
     * that gave no block or share this turn) whose last block has another turn.
     */
    private function replyTo(?string $parent, string $turn): ?string
    {
        $seen = [];
        while ($parent !== null && isset($this->byId[$parent]) && ! isset($seen[$parent])) {
            $seen[$parent] = true;
            $last = $this->byId[$parent]['last'];
            if ($last !== null && $last !== $turn) {
                return $last;
            }
            $parent = $this->byId[$parent]['parent'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function add(BlockType $type, string $text, array $meta): void
    {
        $text = Text::trim(self::lines($text));
        if ($text === '') {
            return;
        }
        if (($meta['reply_to'] ?? null) === ($meta['turn'] ?? null)) {
            $meta['reply_to'] = null;
        }
        $ordered = [];
        foreach (self::META_ORDER as $key) {
            if (isset($meta[$key])) {
                $ordered[$key] = $meta[$key];
            }
        }
        $this->blocks[] = new Block($type, $text, null, $ordered);
        $turn = $ordered['turn'] ?? null;
        if (is_string($turn)) {
            $this->turns[$turn] = true;
        }
    }

    /**
     * The texts of the parts of the given kinds, each with line endings made `\n` and trimmed,
     * the non-empty ones joined by a blank line.
     *
     * @param  list<array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}>  $parts
     */
    private static function joined(array $parts, string ...$kinds): string
    {
        $texts = [];
        foreach ($parts as $part) {
            $text = in_array($part[0], $kinds, true) ? Text::trim(self::lines($part[1])) : '';
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return implode("\n\n", $texts);
    }

    private static function lines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
