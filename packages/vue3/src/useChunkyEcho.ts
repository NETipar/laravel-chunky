import { watch, onBeforeUnmount, getCurrentInstance, type Ref } from 'vue';
import { listenForUploadComplete, listenForBatchComplete } from '@netipar/chunky-core';
import type {
    EchoInstance,
    UploadCompletedData,
    BatchCompletedData,
    BatchPartiallyCompletedData,
} from '@netipar/chunky-core';

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

    if (getCurrentInstance()) {
        onBeforeUnmount(() => cleanup?.());
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

    if (getCurrentInstance()) {
        onBeforeUnmount(() => cleanup?.());
    }
}
