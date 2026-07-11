import { type DeepReadonly, type Ref, onScopeDispose, readonly, shallowRef } from 'vue';
import type { UploadOptions, UploadState, Uploader } from '@netipar/chunky-core';
import { useManager } from './manager';

export interface UseUpload {
    start(file: File, options?: UploadOptions): Uploader;
    state: DeepReadonly<Ref<UploadState | null>>;
    uploader: Ref<Uploader | null>;
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

    // Unmount only unsubscribes — the upload keeps running inside the manager.
    onScopeDispose(() => unsubscribe?.());

    return { start, state: readonly(state), uploader };
}
