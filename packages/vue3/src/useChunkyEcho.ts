import { watch, getCurrentScope, onScopeDispose, type Ref } from 'vue';
import { listenForUser, listenForUploadComplete, listenForBatchComplete } from '@netipar/chunky-core';
import type {
    EchoInstance,
    UploadCompletedData,
    BatchCompletedData,
    BatchPartiallyCompletedData,
} from '@netipar/chunky-core';

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

    watch(userId, (id) => {
        cleanup?.();
        cleanup = null;

        if (id) {
            cleanup = listenForUser(echo, id, callbacks, channelPrefix);
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

    watch(uploadId, (id) => {
        cleanup?.();
        cleanup = null;

        if (id) {
            cleanup = listenForUploadComplete(echo, id, callback, channelPrefix);
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

    watch(batchId, (id) => {
        cleanup?.();
        cleanup = null;

        if (id) {
            cleanup = listenForBatchComplete(echo, id, callbacks, channelPrefix);
        }
    }, { immediate: true });

    if (getCurrentScope()) {
        onScopeDispose(() => cleanup?.());
    }
}
