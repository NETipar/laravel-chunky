export interface EchoInstance {
    private(channel: string): EchoChannel;
}
export interface EchoChannel {
    listen(event: string, callback: (data: any) => void): EchoChannel;
    stopListening(event: string): EchoChannel;
    subscribed?(callback: () => void): EchoChannel;
    error?(callback: (err: unknown) => void): EchoChannel;
}
export interface UploadCompletedData {
    uploadId: string;
    /** Only present when chunky.broadcasting.expose_internal_paths is true. */
    finalPath?: string;
    /** Only present when chunky.broadcasting.expose_internal_paths is true. */
    disk?: string;
    fileName: string;
    fileSize: number;
    context: string | null;
    status: string;
}
export interface UploadFailedData {
    uploadId: string;
    /** Only present when chunky.broadcasting.expose_internal_paths is true. */
    disk?: string;
    fileName: string;
    fileSize: number;
    context: string | null;
    reason: string;
}
export interface BatchCompletedData {
    batchId: string;
    totalFiles: number;
}
export interface BatchPartiallyCompletedData {
    batchId: string;
    completedFiles: number;
    failedFiles: number;
    totalFiles: number;
}
export declare function listenForUser(echo: EchoInstance, userId: string | number, callbacks: {
    onUploadComplete?: (data: UploadCompletedData) => void;
    onUploadFailed?: (data: UploadFailedData) => void;
    onBatchComplete?: (data: BatchCompletedData) => void;
    onBatchPartiallyCompleted?: (data: BatchPartiallyCompletedData) => void;
    onSubscribed?: () => void;
    onSubscribeError?: (err: unknown) => void;
}, channelPrefix?: string): () => void;
export declare function listenForUploadComplete(echo: EchoInstance, uploadId: string, callback: (data: UploadCompletedData) => void, channelPrefix?: string): () => void;
export declare function listenForUploadEvents(echo: EchoInstance, uploadId: string, callbacks: {
    onComplete?: (data: UploadCompletedData) => void;
    onFailed?: (data: UploadFailedData) => void;
    onSubscribed?: () => void;
    onSubscribeError?: (err: unknown) => void;
}, channelPrefix?: string): () => void;
export declare function listenForBatchComplete(echo: EchoInstance, batchId: string, callbacks: {
    onComplete?: (data: BatchCompletedData) => void;
    onPartiallyCompleted?: (data: BatchPartiallyCompletedData) => void;
    onSubscribed?: () => void;
    onSubscribeError?: (err: unknown) => void;
}, channelPrefix?: string): () => void;
