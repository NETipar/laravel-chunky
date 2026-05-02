import { watch, getCurrentScope, onScopeDispose, ref, type Ref } from 'vue';
import { listenForUser, listenForUploadComplete, listenForBatchComplete } from '@netipar/chunky-core';
import type {
    EchoInstance,
    UploadCompletedData,
    BatchCompletedData,
    BatchPartiallyCompletedData,
} from '@netipar/chunky-core';

/**
 * Wrap each callback so the listener captures a *ref* to the latest
 * caller-supplied callback rather than the one passed at subscription
 * time. Without this a re-render that closes over fresh state never
 * sees that state — the Echo callback fires the stale closure.
 *
 * The trade-off: the caller passes callbacks once at subscription and
 * we proxy each delivery through the ref. Cheap, and avoids the
 * "remount every time `callbacks` is a new object" anti-pattern.
 */
function trackCallbackRefs<T extends Record<string, ((...args: unknown[]) => void) | undefined>>(callbacks: T): {
    refs: { [K in keyof T]: { value: T[K] } };
    proxy: T;
} {
    const refs = {} as { [K in keyof T]: { value: T[K] } };
    const proxy = {} as T;

    for (const key of Object.keys(callbacks) as Array<keyof T & string>) {
        const r = ref(callbacks[key]);
        refs[key] = r as unknown as { value: T[typeof key] };
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        proxy[key] = ((...args: any[]) => r.value?.(...args)) as T[typeof key];
    }

    return { refs, proxy };
}

export function useUserEcho(
    echo: EchoInstance,
    userId: Ref<string | number | null>,
    callbacks: {
        onUploadComplete?: (data: UploadCompletedData) => void;
        onBatchComplete?: (data: BatchCompletedData) => void;
        onBatchPartiallyCompleted?: (data: BatchPartiallyCompletedData) => void;
    },
    channelPrefix?: string,
): void {
    let cleanup: (() => void) | null = null;
    const { refs, proxy } = trackCallbackRefs(callbacks as Record<string, ((...args: unknown[]) => void) | undefined>);

    // Re-bind refs whenever the caller passes a new callbacks object —
    // refs are reactive, so the Echo proxy always sees the freshest
    // callback. Without this each re-render of the parent component
    // that creates a new `callbacks = { ... }` literal would invalidate
    // the closure inside the Echo subscription.
    watch(
        () => callbacks,
        (next) => {
            for (const k of Object.keys(refs)) {
                (refs as Record<string, { value: unknown }>)[k].value = (next as Record<string, unknown>)[k];
            }
        },
        { deep: true, flush: 'sync' },
    );

    watch(userId, (id) => {
        cleanup?.();
        cleanup = null;

        if (id) {
            cleanup = listenForUser(echo, id, proxy as Parameters<typeof listenForUser>[2], channelPrefix);
        }
    }, { immediate: true });

    if (getCurrentScope()) {
        onScopeDispose(() => cleanup?.());
    }
}

export function useUploadEcho(
    echo: EchoInstance,
    uploadId: Ref<string | null>,
    callback: (data: UploadCompletedData) => void,
    channelPrefix?: string,
): void {
    let cleanup: (() => void) | null = null;
    const callbackRef = ref(callback);

    watch(() => callback, (next) => { callbackRef.value = next; }, { flush: 'sync' });

    watch(uploadId, (id) => {
        cleanup?.();
        cleanup = null;

        if (id) {
            cleanup = listenForUploadComplete(echo, id, (data) => callbackRef.value(data), channelPrefix);
        }
    }, { immediate: true });

    if (getCurrentScope()) {
        onScopeDispose(() => cleanup?.());
    }
}

export function useBatchEcho(
    echo: EchoInstance,
    batchId: Ref<string | null>,
    callbacks: {
        onComplete?: (data: BatchCompletedData) => void;
        onPartiallyCompleted?: (data: BatchPartiallyCompletedData) => void;
    },
    channelPrefix?: string,
): void {
    let cleanup: (() => void) | null = null;
    const { refs, proxy } = trackCallbackRefs(callbacks as Record<string, ((...args: unknown[]) => void) | undefined>);

    watch(
        () => callbacks,
        (next) => {
            for (const k of Object.keys(refs)) {
                (refs as Record<string, { value: unknown }>)[k].value = (next as Record<string, unknown>)[k];
            }
        },
        { deep: true, flush: 'sync' },
    );

    watch(batchId, (id) => {
        cleanup?.();
        cleanup = null;

        if (id) {
            cleanup = listenForBatchComplete(echo, id, proxy as Parameters<typeof listenForBatchComplete>[2], channelPrefix);
        }
    }, { immediate: true });

    if (getCurrentScope()) {
        onScopeDispose(() => cleanup?.());
    }
}
