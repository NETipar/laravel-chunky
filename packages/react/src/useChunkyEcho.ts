import { useEffect } from 'react';
import { listenForUploadComplete, listenForBatchComplete } from '@netipar/chunky-core';
import type {
    EchoInstance,
    UploadCompletedData,
    BatchCompletedData,
    BatchPartiallyCompletedData,
} from '@netipar/chunky-core';

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
