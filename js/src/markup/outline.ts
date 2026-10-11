/** A node of a markup file's key tree, with its line span `[start, end)`. */
export interface OutlineEntry {
    path: string[];
    start: number;
    end: number;
    value: string | null;
    alias: string | null;
}

export interface Outline {
    lines: string[];
    entries: OutlineEntry[];
    /** Anchor name (a YAML anchor, an XML `id`) to the path of the node it names. */
    anchors: Map<string, string[]>;
    /** The child fields that name an item segment, when the format has its own (XML adds `@` forms). */
    labels?: readonly string[];
    /** A JSON Lines file: the root's children are records, cut in order with no root unit. */
    records?: boolean;
}
