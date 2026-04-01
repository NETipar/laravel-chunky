import type { EchoInstance, UploadCompletedData, BatchCompletedData, BatchPartiallyCompletedData } from '@netipar/chunky-core';
export declare function useUserEcho(echo: EchoInstance, userId: string | number | null, callbacks: {
    onUploadComplete?: (data: UploadCompletedData) => void;
    onBatchComplete?: (data: BatchCompletedData) => void;
    onBatchPartiallyCompleted?: (data: BatchPartiallyCompletedData) => void;
}, channelPrefix?: string): void;
export declare function useUploadEcho(echo: EchoInstance, uploadId: string | null, callback: (data: UploadCompletedData) => void, channelPrefix?: string): void;
export declare function useBatchEcho(echo: EchoInstance, batchId: string | null, callbacks: {
    onComplete?: (data: BatchCompletedData) => void;
    onPartiallyCompleted?: (data: BatchPartiallyCompletedData) => void;
}, channelPrefix?: string): void;
