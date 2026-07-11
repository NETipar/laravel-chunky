import { type DeepReadonly, type Ref, onScopeDispose, readonly, shallowRef } from 'vue';
import type { Batch, BatchOptions, BatchState } from '@netipar/chunky-core';
import { useManager } from './manager';

export interface UseBatch {
    start(files: File[], options?: BatchOptions): Batch;
    state: DeepReadonly<Ref<BatchState | null>>;
    batch: Ref<Batch | null>;
}

export function useBatch(): UseBatch {
    const manager = useManager();
    const state = shallowRef<BatchState | null>(null);
    const batch = shallowRef<Batch | null>(null);
    let unsubscribe: (() => void) | null = null;

    function start(files: File[], options?: BatchOptions): Batch {
        unsubscribe?.();
        const started = manager.batch(files, options);
        batch.value = started;
        unsubscribe = started.subscribe((snapshot) => {
            state.value = snapshot;
        });

        return started;
    }

    onScopeDispose(() => unsubscribe?.());

    return { start, state: readonly(state), batch };
}
