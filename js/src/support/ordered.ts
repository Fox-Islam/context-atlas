/**
 * An object that lists its keys in the order they were added, whatever they look like.
 *
 * A plain JavaScript object lists a key that looks like an array index, such as `2019`, before
 * every other key. `JSON.stringify`, which the SDK uses for the request body, walks the keys a
 * Proxy's `ownKeys` returns in that order, so a map built here reaches Jev in the order PHP
 * sends it, through any client or hook.
 */
export function ordered<T>(entries: Iterable<readonly [string, T]>): Record<string, T> {
    const target: Record<string, T> = {};
    const keys: string[] = [];

    for (const [key, value] of entries) {
        if (!Object.hasOwn(target, key)) {
            keys.push(key);
        }
        target[key] = value;
    }

    return new Proxy(target, {
        ownKeys: (object) => {
            const own = Reflect.ownKeys(object);
            const kept = keys.filter((key) => own.includes(key));

            return [...kept, ...own.filter((key) => typeof key !== 'string' || !kept.includes(key))];
        },
        set: (object, key, value) => {
            if (typeof key === 'string' && !keys.includes(key)) {
                keys.push(key);
            }

            return Reflect.set(object, key, value);
        },
    });
}
