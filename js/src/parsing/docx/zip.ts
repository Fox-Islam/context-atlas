import { inflateRawSync } from 'node:zlib';

/** An entry of a zip archive's central directory. */
export interface ZipEntry {
    name: string;
    method: number;
    flags: number;
    compressedSize: number;
    /** The uncompressed size the directory declares. */
    size: number;
    localOffset: number;
}

/**
 * The central directory of a zip archive, enough to find a part, its declared size and its bytes.
 * Throws on anything that is not a readable zip directory.
 */
export class ZipArchive {
    private readonly entries = new Map<string, ZipEntry>();

    constructor(private readonly bytes: Uint8Array) {
        const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
        const end = findEndOfDirectory(view);
        let count = view.getUint16(end + 10, true);
        let offset = view.getUint32(end + 16, true);

        if (count === 0xffff || offset === 0xffffffff) {
            [count, offset] = zip64Directory(view, end);
        }

        for (let i = 0; i < count; i++) {
            if (offset + 46 > bytes.length || view.getUint32(offset, true) !== 0x02014b50) {
                throw new Error('Bad central directory entry.');
            }

            const nameLength = view.getUint16(offset + 28, true);
            const extraLength = view.getUint16(offset + 30, true);
            const commentLength = view.getUint16(offset + 32, true);
            const nameEnd = offset + 46 + nameLength;

            if (nameEnd + extraLength + commentLength > bytes.length) {
                throw new Error('Truncated central directory.');
            }

            const entry: ZipEntry = {
                name: new TextDecoder().decode(bytes.subarray(offset + 46, nameEnd)),
                flags: view.getUint16(offset + 8, true),
                method: view.getUint16(offset + 10, true),
                compressedSize: view.getUint32(offset + 20, true),
                size: view.getUint32(offset + 24, true),
                localOffset: view.getUint32(offset + 42, true),
            };
            readZip64Extra(view, nameEnd, extraLength, entry);

            if (entry.method === 99 && !hasExtra(view, nameEnd, extraLength, 0x9901)) {
                throw new Error('WinZip AES entry without its extra field.');
            }

            if (!this.entries.has(entry.name)) {
                this.entries.set(entry.name, entry);
            }
            offset = nameEnd + extraLength + commentLength;
        }
    }

    stat(name: string): ZipEntry | null {
        return this.entries.get(name) ?? null;
    }

    /**
     * The entry's bytes, at most `limit` of them; null when they cannot be read. An entry declared
     * empty is empty without being unpacked, as libzip stops reading at the declared size.
     */
    read(entry: ZipEntry, limit: number): Uint8Array | null {
        if (entry.size === 0) {
            return new Uint8Array(0);
        }

        try {
            const view = new DataView(this.bytes.buffer, this.bytes.byteOffset, this.bytes.byteLength);
            const at = entry.localOffset;

            if (entry.flags & 1 || at + 30 > this.bytes.length || view.getUint32(at, true) !== 0x04034b50) {
                return null;
            }

            const start = at + 30 + view.getUint16(at + 26, true) + view.getUint16(at + 28, true);
            const data = this.bytes.subarray(start, start + entry.compressedSize);

            if (data.length < entry.compressedSize) {
                return null;
            }
            if (entry.method === 0) {
                return data.subarray(0, limit);
            }
            if (entry.method !== 8) {
                return null;
            }

            return inflateRawSync(data, { maxOutputLength: Math.max(1, limit) });
        } catch (error) {
            return (error as NodeJS.ErrnoException).code === 'ERR_BUFFER_TOO_LARGE' ? new Uint8Array(limit) : null;
        }
    }
}

function findEndOfDirectory(view: DataView): number {
    const lowest = Math.max(0, view.byteLength - 22 - 0xffff);

    for (let at = view.byteLength - 22; at >= lowest; at--) {
        if (view.getUint32(at, true) === 0x06054b50) {
            return at;
        }
    }

    throw new Error('No end of central directory.');
}

function zip64Directory(view: DataView, end: number): [number, number] {
    const locator = end - 20;

    if (locator < 0 || view.getUint32(locator, true) !== 0x07064b50) {
        throw new Error('No zip64 locator.');
    }

    const record = Number(view.getBigUint64(locator + 8, true));

    if (record + 56 > view.byteLength || view.getUint32(record, true) !== 0x06064b50) {
        throw new Error('No zip64 end of central directory.');
    }

    return [Number(view.getBigUint64(record + 32, true)), Number(view.getBigUint64(record + 48, true))];
}

function hasExtra(view: DataView, start: number, length: number, wanted: number): boolean {
    for (let at = start; at + 4 <= start + length; at += 4 + view.getUint16(at + 2, true)) {
        if (view.getUint16(at, true) === wanted) {
            return true;
        }
    }

    return false;
}

function readZip64Extra(view: DataView, start: number, length: number, entry: ZipEntry): void {
    for (let at = start; at + 4 <= start + length;) {
        const id = view.getUint16(at, true);
        const size = view.getUint16(at + 2, true);

        if (id === 0x0001) {
            let field = at + 4;
            const next = (): number => {
                const value = Number(view.getBigUint64(field, true));
                field += 8;

                return value;
            };

            if (entry.size === 0xffffffff) {
                entry.size = next();
            }
            if (entry.compressedSize === 0xffffffff) {
                entry.compressedSize = next();
            }
            if (entry.localOffset === 0xffffffff) {
                entry.localOffset = next();
            }

            return;
        }
        at += 4 + size;
    }
}
