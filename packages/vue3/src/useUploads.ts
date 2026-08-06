import { type ComputedRef, type DeepReadonly, type Ref, computed, onScopeDispose, readonly, shallowRef } from 'vue';
import type { Uploader } from '@netipar/chunky-core';
import { useManager } from './manager';

export interface UseUploads {
    uploads: DeepReadonly<Ref<readonly Uploader[]>>;
    totalProgress: ComputedRef<number>;
}

/**
 * Reactive view of all uploads the manager is tracking — for a global upload
 * tray that any page can render.
 */
export function useUploads(): UseUploads {
    const manager = useManager();
    const uploads = shallowRef<readonly Uploader[]>(manager.uploads());
    const unsubscribe = manager.subscribe((list) => {
        uploads.value = list;
    });

    onScopeDispose(unsubscribe);

    const totalProgress = computed(() => {
        void uploads.value;

        return manager.totalProgress();
    });

    return { uploads: readonly(uploads), totalProgress };
}
