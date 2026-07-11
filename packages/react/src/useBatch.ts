import { useCallback, useState, useSyncExternalStore } from 'react';
import type { Batch, BatchOptions, BatchState } from '@netipar/chunky-core';
import { useManager } from './context';

export interface UseBatch {
    start(files: File[], options?: BatchOptions): Batch;
    state: BatchState | null;
    batch: Batch | null;
}

export function useBatch(): UseBatch {
    const manager = useManager();
    const [batch, setBatch] = useState<Batch | null>(null);

    const subscribe = useCallback(
        (onChange: () => void) => (batch ? batch.on('stateChange', () => onChange()) : () => {}),
        [batch],
    );
    const getSnapshot = useCallback(() => (batch ? batch.getState() : null), [batch]);

    const state = useSyncExternalStore(subscribe, getSnapshot, getSnapshot);

    const start = useCallback((files: File[], options?: BatchOptions): Batch => {
        const started = manager.batch(files, options);
        setBatch(started);

        return started;
    }, [manager]);

    return { start, state, batch };
}
