import { useEffect } from 'react';
import { listenForUser, listenForUploadComplete, listenForBatchComplete } from '@netipar/chunky-core';
import type {
    EchoInstance,
    UploadCompletedData,
    BatchCompletedData,
    BatchPartiallyCompletedData,
} from '@netipar/chunky-core';

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
    useEffect(() => {
        if (!userId) {
            return;
        }

        return listenForUser(echo, userId, callbacks, channelPrefix);
    }, [userId]);
}

export function useUploadEcho(
    echo: EchoInstance,
    uploadId: string | null,
    callback: (data: UploadCompletedData) => void,
    channelPrefix?: string,
): void {
    useEffect(() => {
        if (!uploadId) {
            return;
        }

        return listenForUploadComplete(echo, uploadId, callback, channelPrefix);
    }, [uploadId]);
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
    useEffect(() => {
        if (!batchId) {
            return;
        }

        return listenForBatchComplete(echo, batchId, callbacks, channelPrefix);
    }, [batchId]);
}
