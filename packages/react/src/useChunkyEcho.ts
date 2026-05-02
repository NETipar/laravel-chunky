import { useEffect, useRef } from 'react';
import { listenForUser, listenForUploadComplete, listenForBatchComplete } from '@netipar/chunky-core';
import type {
    EchoInstance,
    UploadCompletedData,
    BatchCompletedData,
    BatchPartiallyCompletedData,
} from '@netipar/chunky-core';

/**
 * Track caller-supplied callbacks via a ref so each delivery invokes
 * the freshest closure even when the parent component re-renders with
 * a new `callbacks` literal. Without this every render would either
 * trigger a re-subscription (if `callbacks` is in the deps) or
 * silently dispatch to a stale closure (if it isn't).
 */
function useCallbackRef<T>(value: T): { current: T } {
    const ref = useRef(value);
    ref.current = value;
    return ref;
}

export function useUserEcho(
    echo: EchoInstance,
    userId: string | number | null,
    callbacks: {
        onUploadComplete?: (data: UploadCompletedData) => void;
        onBatchComplete?: (data: BatchCompletedData) => void;
        onBatchPartiallyCompleted?: (data: BatchPartiallyCompletedData) => void;
    },
    channelPrefix?: string,
): void {
    const cbRef = useCallbackRef(callbacks);

    useEffect(() => {
        if (!userId) {
            return;
        }

        return listenForUser(
            echo,
            userId,
            {
                onUploadComplete: (data) => cbRef.current.onUploadComplete?.(data),
                onBatchComplete: (data) => cbRef.current.onBatchComplete?.(data),
                onBatchPartiallyCompleted: (data) => cbRef.current.onBatchPartiallyCompleted?.(data),
            },
            channelPrefix,
        );
        // The callbacks ref is stable; we only re-subscribe when userId
        // (or the channel prefix) genuinely changes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [echo, userId, channelPrefix]);
}

export function useUploadEcho(
    echo: EchoInstance,
    uploadId: string | null,
    callback: (data: UploadCompletedData) => void,
    channelPrefix?: string,
): void {
    const cbRef = useCallbackRef(callback);

    useEffect(() => {
        if (!uploadId) {
            return;
        }

        return listenForUploadComplete(echo, uploadId, (data) => cbRef.current(data), channelPrefix);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [echo, uploadId, channelPrefix]);
}

export function useBatchEcho(
    echo: EchoInstance,
    batchId: string | null,
    callbacks: {
        onComplete?: (data: BatchCompletedData) => void;
        onPartiallyCompleted?: (data: BatchPartiallyCompletedData) => void;
    },
    channelPrefix?: string,
): void {
    const cbRef = useCallbackRef(callbacks);

    useEffect(() => {
        if (!batchId) {
            return;
        }

        return listenForBatchComplete(
            echo,
            batchId,
            {
                onComplete: (data) => cbRef.current.onComplete?.(data),
                onPartiallyCompleted: (data) => cbRef.current.onPartiallyCompleted?.(data),
            },
            channelPrefix,
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [echo, batchId, channelPrefix]);
}
