import { useCallback, useSyncExternalStore } from 'react';
import type { Uploader } from '@netipar/chunky-core';
import { useManager } from './context';

export interface UseUploads {
    uploads: readonly Uploader[];
    totalProgress: number;
}

/**
 * Reactive view of every tracked upload — the manager returns a cached snapshot
 * with a stable reference, so useSyncExternalStore stays happy.
 */
export function useUploads(): UseUploads {
    const manager = useManager();

    const subscribe = useCallback((onChange: () => void) => manager.subscribe(() => onChange()), [manager]);
    const uploads = useSyncExternalStore(subscribe, () => manager.uploads(), () => manager.uploads());

    return { uploads, totalProgress: manager.totalProgress() };
}
