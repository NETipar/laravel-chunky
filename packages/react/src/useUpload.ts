import { useCallback, useState, useSyncExternalStore } from 'react';
import type { UploadOptions, UploadState, Uploader } from '@netipar/chunky-core';
import { useManager } from './context';

export interface UseUpload {
    start(file: File, options?: UploadOptions): Uploader;
    state: UploadState | null;
    uploader: Uploader | null;
}

export function useUpload(): UseUpload {
    const manager = useManager();
    const [uploader, setUploader] = useState<Uploader | null>(null);

    // stateChange is non-sticky, so subscribing never fires synchronously —
    // exactly what useSyncExternalStore's subscribe requires. Unmount only
    // unsubscribes; the upload keeps running in the manager.
    const subscribe = useCallback(
        (onChange: () => void) => (uploader ? uploader.on('stateChange', () => onChange()) : () => {}),
        [uploader],
    );
    const getSnapshot = useCallback(() => (uploader ? uploader.getState() : null), [uploader]);

    const state = useSyncExternalStore(subscribe, getSnapshot, getSnapshot);

    const start = useCallback((file: File, options?: UploadOptions): Uploader => {
        const started = manager.upload(file, options);
        setUploader(started);

        return started;
    }, [manager]);

    return { start, state, uploader };
}
