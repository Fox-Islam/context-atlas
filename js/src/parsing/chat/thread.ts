import { Block, BlockType } from '../../block.js';
import { trim } from '../../text.js';
import { envelopeOf, linksOf, readMessage, type Message } from './messages.js';
import type { Part } from './parts.js';

const META_ORDER: readonly string[] = ['speaker', 'speaker_full', 'turn', 'time', 'reply_to', 'call', 'tool', 'kind', 'attachment', 'error'];

type Meta = Record<string, unknown>;

interface Call {
    id: string | null;
    tool: string;
    turn: string;
    answered: boolean;
}

/** Whether any item is a recognised one. */
export function recognises(items: readonly unknown[]): boolean {
    return items.some((item) => readMessage(item) !== null);
}

/**
 * One conversation's items as blocks: a paragraph per message, a code block per tool call and
 * per tool result, attachments marked, and `turn`, `reply_to` and `call` links worked out across
 * the conversation (spec/formats/chat.md). `system` holds the conversation's system prompt parts.
 */
export function threadBlocks(items: readonly unknown[], system: readonly Part[]): Block[] {
    const thread = new Thread();
    const text = joined(system, 'text');

    if (text !== '') {
        thread.add(BlockType.Paragraph, text, { speaker: 'system', turn: 'system', attachment: true });
    }
    for (const [position, item] of kept(items)) {
        const message = readMessage(item);

        if (message !== null && typeof message === 'object') {
            thread.message(message, String(position));
        } else {
            const [ids, parent] = linksOf(item);

            for (const id of ids) {
                thread.remember(id, parent, null);
            }
        }
    }

    return thread.blocks;
}

/**
 * The items left after dropping a repeated `uuid` (the first is kept) and, when some item has
 * `isSidechain: false`, every item with `isSidechain: true`, with their positions.
 */
function kept(items: readonly unknown[]): [number, unknown][] {
    const main = items.some((item) => envelopeOf(item)[1] === false);
    const seen = new Set<string>();
    const out: [number, unknown][] = [];

    items.forEach((item, position) => {
        const [uuid, sidechain] = envelopeOf(item);

        if ((uuid !== null && seen.has(uuid)) || (main && sidechain === true)) {
            return;
        }
        if (uuid !== null) {
            seen.add(uuid);
        }
        out.push([position, item]);
    });

    return out;
}

class Thread {
    readonly blocks: Block[] = [];

    /** Message id to its parent and the turn of its last block. */
    private readonly byId = new Map<string, { parent: string | null; last: string | null }>();

    /** Turns some block already carries. */
    private readonly turns = new Set<string>();

    private readonly calls: Call[] = [];

    remember(id: string, parent: string | null, last: string | null): void {
        if (!this.byId.has(id)) {
            this.byId.set(id, { parent, last });
        }
    }

    message(message: Message, position: string): void {
        const turn = message.ids[0] ?? position;
        const replyTo = this.replyTo(message.parent, turn);
        const base: Meta = { speaker: message.role, speaker_full: message.speakerFull, turn, time: message.time };
        const before = this.blocks.length;
        const hasResults = message.parts.some((part) => part[0] === 'result');

        if (message.result && !hasResults) {
            this.result(base, ['result', joined(message.parts, 'text', 'media'), message.callId, message.tool, message.error], 0);
        } else {
            this.parts(message, base, replyTo);
        }

        const last = this.blocks.length > before ? this.blocks.at(-1)!.meta['turn'] : null;

        for (const id of message.ids) {
            this.remember(id, message.parent, typeof last === 'string' ? last : null);
        }
    }

    /**
     * Blocks in the order of the parts, except that all text parts make one paragraph where the
     * first stood, and all reasoning parts one where the first stood.
     */
    private parts(message: Message, base: Meta, replyTo: string | null): void {
        const attachment = message.role === 'system';
        const done = { text: false, reasoning: false };
        let k = 0;

        for (const part of message.parts) {
            const kind = part[0];

            if (kind === 'text' || kind === 'reasoning') {
                if (!done[kind]) {
                    done[kind] = true;
                    const text = joined(message.parts, kind);
                    const extra = kind === 'reasoning' ? { kind: 'reasoning', attachment: true } : attachment ? { attachment: true } : {};

                    if (text !== '') {
                        this.add(BlockType.Paragraph, text, { ...base, reply_to: replyTo, ...extra });
                    }
                }
            } else if (kind === 'media') {
                this.add(BlockType.Paragraph, part[1], { ...base, reply_to: replyTo, attachment: true });
            } else if (kind === 'call') {
                this.call(base, part, k++, replyTo);
            } else {
                this.result(base, part, k++);
            }
        }
    }

    private call(base: Meta, part: Part, k: number, replyTo: string | null): void {
        const id = part[3] ?? null;
        const own = String(base['turn']);
        const turn = `${own}#${id ?? String(k)}`;
        const answers = this.turns.has(own) ? own : replyTo;

        this.calls.push({ id, tool: part[1], turn, answered: false });
        this.add(BlockType.Code, `${part[1]}(${part[2] ?? ''})`, { ...base, turn, reply_to: answers, call: id, tool: part[1] });
    }

    private result(base: Meta, part: Part, k: number): void {
        const id = part[2] ?? null;
        const call = this.answer(id, part[3] ?? null);
        const tool = part[3] ?? (call === null ? null : this.calls[call]!.tool);
        const text = trim(lines(part[1]));

        if (text === '') {
            return;
        }

        const answers = call === null ? null : this.calls[call]!.turn;
        let turn = `${String(base['turn'])}#${id ?? String(k)}`;
        turn = turn === answers ? `${turn}/result` : turn;
        const meta: Meta = { speaker: 'tool', turn, time: base['time'], reply_to: answers, tool, attachment: true };

        this.add(BlockType.Code, text, part[4] === true ? { ...meta, error: true } : meta);
    }

    /**
     * The index of the call a result answers: the latest earlier call with its id, else the
     * earliest unanswered earlier call with its tool name; null when none.
     */
    private answer(id: string | null, tool: string | null): number | null {
        let found: number | null = null;

        this.calls.forEach((call, i) => {
            if (id !== null ? call.id === id : tool !== null && call.tool === tool && !call.answered && found === null) {
                found = i;
            }
        });
        if (found !== null) {
            this.calls[found]!.answered = true;
        }

        return found;
    }

    /**
     * The turn of the last block of the nearest ancestor (followed by parent ids through items
     * that gave no block or share this turn) whose last block has another turn.
     */
    private replyTo(start: string | null, turn: string): string | null {
        const seen = new Set<string>();
        let parent = start;

        while (parent !== null && this.byId.has(parent) && !seen.has(parent)) {
            seen.add(parent);
            const entry = this.byId.get(parent)!;

            if (entry.last !== null && entry.last !== turn) {
                return entry.last;
            }
            parent = entry.parent;
        }

        return null;
    }

    add(type: BlockType, given: string, fields: Meta): void {
        const text = trim(lines(given));

        if (text === '') {
            return;
        }

        const meta: Meta = { ...fields };

        if ((meta['reply_to'] ?? null) === (meta['turn'] ?? null)) {
            meta['reply_to'] = null;
        }

        const ordered: Meta = {};

        for (const key of META_ORDER) {
            if (meta[key] !== undefined && meta[key] !== null) {
                ordered[key] = meta[key];
            }
        }
        this.blocks.push(new Block(type, text, null, ordered));

        const turn = ordered['turn'];

        if (typeof turn === 'string') {
            this.turns.add(turn);
        }
    }
}

/**
 * The texts of the parts of the given kinds, each with line endings made `\n` and trimmed, the
 * non-empty ones joined by a blank line.
 */
function joined(parts: readonly Part[], ...kinds: string[]): string {
    return parts.map((part) => (kinds.includes(part[0]) ? trim(lines(part[1])) : '')).filter((text) => text !== '').join('\n\n');
}

function lines(text: string): string {
    return text.replace(/\r\n?/g, '\n');
}
