import { Link } from '../document/link.js';
import { Unit } from '../document/unit.js';
import { MARKUP } from '../prose/profiles.js';
import { phpTrim } from '../support/php.js';
import { length, tokens } from '../text.js';
import type { Outline, OutlineEntry } from './outline.js';

const MAX = 450;

const SMALL = 90;

const BLANK_OR_COMMENT = /^[ \t\n\v\f\r]*(#[^\n]*)?$/;

const DEFAULT_LABELS: readonly string[] = ['name', 'id', 'title', 'key'];

/**
 * Cuts a key tree into units: a subtree that fits `MAX` tokens is one unit; a bigger one keeps its
 * own lines and its children of `SMALL` tokens or fewer as a header unit, and each larger child is cut
 * the same way. A unit's text is a breadcrumb line (`title: path`) and its source lines with
 * their common indent removed, so a schema read alone names where it sits.
 *
 * A node's parent is its nearest ancestor path that has a node (TOML has none for `tool` in
 * `[tool.ruff]`). A child of more than `SMALL` tokens lying outside its parent's span, such as a
 * `[package.metadata]` written after other tables, is cut on its own by the node whose span holds
 * it, and its lines are left out of that node's unit.
 *
 * Links: `ref` from the unit holding a `$ref: "#/..."` or an alias (YAML, or an XML reference to
 * an `id`) to the unit where its target starts.
 */
export function buildSubtrees(outline: Outline, title: string, charsPerToken = 4): { units: Unit[]; links: Link[] } {
    return new SubtreeCutter(outline, title, charsPerToken).build();
}

class SubtreeCutter {
    /** By path key; a repeated path keeps its first place and takes the last node. */
    private readonly entries = new Map<string, OutlineEntry>();

    private readonly children = new Map<string, string[]>();

    private readonly detached: string[] = [];

    private readonly done = new Set<string>();

    /** Per sibling group and relative path, how many same-name siblings hold each value. */
    private readonly values = new Map<string, Map<string, number>>();

    private readonly siblings = new Map<string, string[]>();

    private readonly units: Unit[] = [];

    private readonly owner = new Map<number, string>();

    constructor(
        private readonly outline: Outline,
        private readonly title: string,
        private readonly charsPerToken: number,
    ) {
        for (const entry of outline.entries) {
            this.entries.set(keyOf(entry.path), entry);
        }
        for (const [key, entry] of this.entries) {
            if (entry.path.length === 0) {
                continue;
            }

            const parent = this.parentOf(entry.path);
            this.children.set(parent, [...(this.children.get(parent) ?? []), key]);
            if (!within(entry, this.entries.get(parent)!) && this.tokens(entry.start, entry.end) > SMALL) {
                this.detached.push(key);
            }
        }
    }

    build(): { units: Unit[]; links: Link[] } {
        this.cut('');

        return { units: this.units, links: this.links() };
    }

    private cut(key: string): void {
        if (key === '' && this.outline.records === true && this.children.has('')) {
            this.cutRecords();

            return;
        }
        this.done.add(key);

        const entry = this.entries.get(key)!;
        const whole = key !== '' && (this.tokens(entry.start, entry.end) <= MAX || !this.children.has(key));
        const big = whole ? [] : (this.children.get(key) ?? []).filter((child) => {
            const c = this.entries.get(child)!;

            return this.tokens(c.start, c.end) > SMALL;
        });
        const detached = this.detached.filter((d) => !this.done.has(d) && within(this.entries.get(d)!, entry)
            && !big.some((b) => within(this.entries.get(d)!, this.entries.get(b)!)));
        const covered = new Set<number>();

        for (const child of [...big, ...detached]) {
            const c = this.entries.get(child)!;

            for (let i = c.start; i < c.end; i++) {
                covered.add(i);
            }
        }

        const own = range(entry.start, Math.max(entry.start, entry.end - 1)).filter((i) => !covered.has(i));
        const useful = own.filter((i) => !BLANK_OR_COMMENT.test(this.outline.lines[i] ?? ''));

        if (own.length > 0 && (whole || useful.length > 1 || big.length === 0)) {
            this.emitOwn(key, own, big);
        }
        for (const child of [...big, ...detached]) {
            if (!this.done.has(child)) {
                this.cut(child);
            }
        }
    }

    /**
     * Emits a node's own lines as one unit, or, past `MAX` tokens, as packs split at its small
     * children: lines outside them join the pack being filled, and a child that would take a pack
     * holding a child past `MAX` starts the next one. A pack's label ends with the first and last
     * child it holds: `(top level) (build … cascade > [3])`.
     */
    private emitOwn(key: string, own: readonly number[], big: readonly string[]): void {
        const entry = this.entries.get(key)!;
        const path = entry.path;

        if (tokens(this.text(own), this.charsPerToken) <= MAX) {
            this.emit(path, own);

            return;
        }

        const childOf = new Map<number, string>();

        for (const child of this.children.get(key) ?? []) {
            const c = this.entries.get(child)!;

            if (!big.includes(child) && within(c, entry)) {
                for (let i = c.start; i < c.end; i++) {
                    childOf.set(i, child);
                }
            }
        }

        const spans = new Map<string, number[]>();

        for (const line of own) {
            const child = childOf.get(line);

            if (child !== undefined) {
                spans.set(child, [...(spans.get(child) ?? []), line]);
            }
        }

        const packs: number[][] = [[]];
        const held: string[][] = [[]];
        let last = 0;

        own.forEach((line, n) => {
            const child = childOf.get(line);

            if (child !== undefined && (n === 0 || childOf.get(own[n - 1]!) !== child)) {
                if (held[last]!.length > 0 && tokens(this.text([...packs[last]!, ...spans.get(child)!]), this.charsPerToken) > MAX) {
                    last++;
                    packs[last] = [];
                    held[last] = [];
                }
                held[last]!.push(child);
            }
            packs[last]!.push(line);
        });

        packs.forEach((pack, p) => this.emit(path, pack, this.range(path, held[p]!)));
    }

    /**
     * Cuts the records of a JSON Lines file in order: a record of more than `SMALL` tokens is cut on
     * its own, and the runs of smaller records between them are packed into units of at most `MAX`
     * tokens, each labelled by its first and last record; a pack of one record is that record's
     * own unit.
     */
    private cutRecords(): void {
        this.done.add('');

        let pack: number[] = [];
        let held: string[] = [];

        for (const child of this.children.get('') ?? []) {
            const c = this.entries.get(child)!;
            const lines = range(c.start, Math.max(c.start, c.end - 1));

            if (this.tokens(c.start, c.end) > SMALL) {
                this.emitPack(pack, held);
                [pack, held] = [[], []];
                this.cut(child);
                continue;
            }
            if (held.length > 0 && tokens(this.text([...pack, ...lines]), this.charsPerToken) > MAX) {
                this.emitPack(pack, held);
                [pack, held] = [[], []];
            }
            pack.push(...lines);
            held.push(child);
        }
        this.emitPack(pack, held);
    }

    private emitPack(lines: readonly number[], held: readonly string[]): void {
        if (held.length === 1) {
            this.cut(held[0]!);
        } else if (held.length > 0) {
            this.emit([], lines, this.range([], held));
        }
    }

    /** `(<first> … <last>)` naming the first and last of the children, or `(<first>)`, each by its label segments after `path`; null for none. */
    private range(path: readonly string[], children: readonly string[]): string | null {
        const names = children.map((child) => this.label(this.entries.get(child)!.path).slice(path.length).join(' > '));

        if (names.length === 0) {
            return null;
        }

        return names.length === 1 ? `(${names[0]})` : `(${names[0]} … ${names[names.length - 1]})`;
    }

    private text(lineNumbers: readonly number[]): string {
        return lineNumbers.map((i) => this.outline.lines[i] ?? '').join('\n');
    }

    private emit(path: readonly string[], lineNumbers: readonly number[], span: string | null = null): void {
        const leading = (line: string): number => line.length - line.replace(/^ +/, '').length;
        let body = lineNumbers.map((i) => this.outline.lines[i] ?? '');
        const indents = body.filter((line) => phpTrim(line) !== '').map(leading);
        const indent = indents.length === 0 ? 0 : Math.min(...indents);
        body = body.map((line) => line.slice(Math.min(indent, leading(line))));

        const label = this.label(path);
        const id = `s${this.units.length + 1}`;
        const text = `${this.title}: ${path.length === 0 ? '(top level)' : label.join(' > ')}${span === null ? '' : ` ${span}`}\n${body.join('\n').replace(/[ \t\n\r\0\x0B]+$/, '')}`;

        this.units.push(new Unit(id, 'subtree', text, span === null ? label : [...label, span], {
            path: [...path],
            lines: [lineNumbers[0] ?? 0, (lineNumbers.at(-1) ?? 0) + 1],
            profile: MARKUP,
        }));
        for (const i of lineNumbers) {
            if (!this.owner.has(i)) {
                this.owner.set(i, id);
            }
        }
    }

    /**
     * The path with each sequence index named by one of the item's children: `steps > [name=Install
     * dependencies]`. An outline that lists its own label fields (XML adds the `@` forms) also
     * names a `tag[i]` segment: `item[name=Home]`.
     */
    private label(path: readonly string[]): string[] {
        const pattern = this.outline.labels === undefined ? /^()\[\d+\]\n?$/ : /^([^[\]]*)\[\d+\]\n?$/;

        return path.map((segment, i) => {
            const match = pattern.exec(segment);
            const name = match === null ? null : this.name(path.slice(0, i + 1));

            return name === null ? segment : `${match![1]}[${name[0]}=${name[1]}]`;
        });
    }

    /**
     * The field and value naming an item: the first label field with a value; else a child whose
     * key ends in `name`, `id`, `title` or `key` (any ASCII case) and has a value; else a child
     * with a non-empty one-line value, cut to 40 code points and `…` when longer than 60. In the
     * last two the first child whose value no same-name sibling item shares wins, else the first
     * child. Children that are items themselves are passed over.
     */
    private name(item: readonly string[]): [string, string] | null {
        for (const field of this.outline.labels ?? DEFAULT_LABELS) {
            const value = this.entries.get(keyOf([...item, field]))?.value ?? null;

            if (value !== null) {
                return [field.replace(/^@+/, ''), value];
            }
        }

        const children = (this.children.get(keyOf(item)) ?? [])
            .map((child) => this.entries.get(child)!)
            .filter((c) => c.value !== null && !/\[\d+\]\n?$/.test(c.path.at(-1)!));
        const ending = children.filter((c) => /(?:name|id|title|key)\n?$/i.test(c.path.at(-1)!.replace(/^@+/, '')));
        const plain = children.filter((c) => c.value !== '' && !c.value!.includes('\n'));
        const samePlain = ending.length === plain.length && ending.every((c, i) => c === plain[i]);

        for (const candidates of [ending, plain]) {
            if (candidates.length === 0) {
                continue;
            }

            const chosen = candidates.find((candidate) => this.unique(item, candidate)) ?? candidates[0]!;
            const value = chosen.value!;
            const truncate = (candidates === plain || samePlain) && length(value) > 60;

            return [chosen.path.at(-1)!.replace(/^@+/, ''), truncate ? `${Array.from(value).slice(0, 40).join('')}…` : value];
        }

        return null;
    }

    /** Whether no same-name sibling of the item has a node at the child's relative path with the same value. */
    private unique(item: readonly string[], child: OutlineEntry): boolean {
        const group = keyOf([...item.slice(0, -1), withoutIndex(item.at(-1)!)]);
        const relative = keyOf(child.path.slice(item.length));
        const cacheKey = `${group}\x1e${relative}`;
        let counts = this.values.get(cacheKey);

        if (counts === undefined) {
            counts = new Map();

            for (const sibling of this.siblingsOf(item, group)) {
                const value = this.entries.get(`${sibling}\x1f${relative}`)?.value ?? null;

                if (value !== null) {
                    counts.set(value, (counts.get(value) ?? 0) + 1);
                }
            }
            this.values.set(cacheKey, counts);
        }

        return (counts.get(child.value ?? '') ?? 0) === 1;
    }

    /** The items under the item's parent path whose last segment differs from the item's only in its index, the item included. */
    private siblingsOf(item: readonly string[], group: string): string[] {
        let found = this.siblings.get(group);

        if (found === undefined) {
            const parent = item.slice(0, -1);
            const tag = withoutIndex(item.at(-1)!);

            found = (this.children.get(this.parentOf(item)) ?? []).filter((key) => {
                const path = this.entries.get(key)!.path;
                const last = path[item.length - 1];

                return path.length === item.length
                    && path.slice(0, -1).every((segment, i) => segment === parent[i])
                    && last !== undefined && last.startsWith(tag) && /^\[\d+\]\n?$/.test(last.slice(tag.length));
            });
            this.siblings.set(group, found);
        }

        return found;
    }

    private links(): Link[] {
        const links = new Map<string, Link>();

        for (const entry of this.entries.values()) {
            const from = this.ownerOf(entry.path);
            const last = entry.path.at(-1);
            const target = last === '$ref' && (entry.value ?? '').startsWith('#/')
                ? entry.value!.slice(2).split('/').map((part) => part.replaceAll('~1', '/').replaceAll('~0', '~'))
                : entry.alias !== null ? this.outline.anchors.get(entry.alias) ?? null : null;
            const to = target === null ? null : this.ownerOf(target);

            if (from !== null && to !== null && to !== from && !links.has(`${from} ${to}`)) {
                links.set(`${from} ${to}`, new Link('ref', from, to));
            }
        }

        return [...links.values()];
    }

    /** The key of the nearest ancestor path that has a node; the root's otherwise. */
    private parentOf(path: readonly string[]): string {
        for (let n = path.length - 1; n > 0; n--) {
            const key = keyOf(path.slice(0, n));

            if (this.entries.has(key)) {
                return key;
            }
        }

        return '';
    }

    private ownerOf(path: readonly string[]): string | null {
        const entry = this.entries.get(keyOf(path));

        return entry === undefined ? null : this.owner.get(entry.start) ?? null;
    }

    private tokens(start: number, end: number): number {
        return tokens(this.outline.lines.slice(start, Math.max(start, end)).join('\n'), this.charsPerToken);
    }
}

/** A segment without its trailing `[<n>]`, as PHP's `preg_replace('/\[\d+\]$/', '')` leaves it (a final line feed is kept). */
function withoutIndex(segment: string): string {
    return segment.replace(/\[\d+\](?=\n?$)/, '');
}

function within(inner: OutlineEntry, outer: OutlineEntry): boolean {
    return inner.start >= outer.start && inner.end <= outer.end;
}

function keyOf(path: readonly string[]): string {
    return path.join('\x1f');
}

function range(from: number, to: number): number[] {
    return Array.from({ length: to - from + 1 }, (_, i) => from + i);
}
