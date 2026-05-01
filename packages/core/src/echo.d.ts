export interface EchoInstance {
    private<EventMap = Record<string, unknown>>(channel: string): EchoChannel<EventMap>;
}
/**
 * Echo channel surface used by the package wrappers. Generic over the
 * event map of the channel — Laravel Echo wrappers (laravel-echo,
 * pusher-js) deliver typed payloads, but the channel object historically
 * had `(data: any)` callbacks. The default `Record<string, unknown>`
 * keeps existing untyped wrappers compatible while letting callers
 * narrow the events when they want.
 */
export interface EchoChannel<EventMap = Record<string, unknown>> {
    listen<K extends keyof EventMap & string>(event: K, callback: (data: EventMap[K]) => void): EchoChannel<EventMap>;
    stopListening<K extends keyof EventMap & string>(event: K): EchoChannel<EventMap>;
    subscribed?(callback: () => void): EchoChannel<EventMap>;
    error?(callback: (err: unknown) => void): EchoChannel<EventMap>;
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
