import { type ComputedRef, type DeepReadonly, type Ref, computed, onScopeDispose, readonly, shallowRef } from 'vue';
import type { UploadOptions, UploadState, Uploader } from '@netipar/chunky-core';
import { useManager } from './manager';

export interface UseUpload {
    start(file: File, options?: UploadOptions): Uploader;
    state: DeepReadonly<Ref<UploadState | null>>;
    uploader: Ref<Uploader | null>;
    /** Object URL preview for image files; null otherwise. */
    previewUrl: ComputedRef<string | null>;
}

export function useUpload(): UseUpload {
    const manager = useManager();
    const state = shallowRef<UploadState | null>(null);
    const uploader = shallowRef<Uploader | null>(null);
    let unsubscribe: (() => void) | null = null;

    function start(file: File, options?: UploadOptions): Uploader {
        unsubscribe?.();
        const started = manager.upload(file, options);
        uploader.value = started;
        unsubscribe = started.subscribe((snapshot) => {
            state.value = snapshot;
        });

        return started;
    }

    // Re-evaluates when the tracked uploader (or its state) changes; the
    // object URL itself is cached inside the Uploader.
    const previewUrl = computed(() => {
        void state.value;

        return uploader.value?.previewUrl() ?? null;
    });

    // Unmount only unsubscribes — the upload keeps running inside the manager.
    onScopeDispose(() => unsubscribe?.());

    return { start, state: readonly(state), uploader, previewUrl };
}
