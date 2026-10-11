/**
 * Recursion on the heap. A walker written as a generator yields the frame of each call it makes
 * and receives that call's result, so a part nested millions of elements deep (which PHP's
 * reader handles) does not exhaust the call stack.
 */
export type Frame<T> = Generator<Frame<unknown>, T, unknown>;

/** Runs a frame and every frame it calls to completion, returning its result. */
export function run<T>(root: Frame<T>): T {
    const stack: Frame<unknown>[] = [root];
    let value: unknown;

    for (;;) {
        const step = stack[stack.length - 1]!.next(value);

        if (!step.done) {
            stack.push(step.value);
            value = undefined;
            continue;
        }

        stack.pop();
        if (stack.length === 0) {
            return step.value as T;
        }
        value = step.value;
    }
}
