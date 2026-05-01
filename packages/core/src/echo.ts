export interface EchoInstance {
    private<EventMap = Record<string, any>>(channel: string): EchoChannel<EventMap>;
}

/**
 * Echo channel surface used by the package wrappers. Generic over the
 * event map of the channel — Laravel Echo wrappers (laravel-echo,
 * pusher-js) deliver typed payloads, but the channel object
 * historically had `(data: any)` callbacks. The default
 * `Record<string, any>` keeps existing untyped wrappers compatible
 * while letting callers narrow the events when they want by passing
 * a concrete event map.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export interface EchoChannel<EventMap = Record<string, any>> {
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

export function listenForUser(
    echo: EchoInstance,
    userId: string | number,
    callbacks: {
        onUploadComplete?: (data: UploadCompletedData) => void;
        onUploadFailed?: (data: UploadFailedData) => void;
        onBatchComplete?: (data: BatchCompletedData) => void;
        onBatchPartiallyCompleted?: (data: BatchPartiallyCompletedData) => void;
        onSubscribed?: () => void;
        onSubscribeError?: (err: unknown) => void;
    },
    channelPrefix = 'chunky',
): () => void {
    const channel = echo.private(`${channelPrefix}.user.${userId}`);
    const stopFns: Array<() => void> = [];

    if (callbacks.onUploadComplete) {
        channel.listen('.UploadCompleted', callbacks.onUploadComplete);
        stopFns.push(() => channel.stopListening('.UploadCompleted'));
    }

    if (callbacks.onUploadFailed) {
        channel.listen('.UploadFailed', callbacks.onUploadFailed);
        stopFns.push(() => channel.stopListening('.UploadFailed'));
    }

    if (callbacks.onBatchComplete) {
        channel.listen('.BatchCompleted', callbacks.onBatchComplete);
        stopFns.push(() => channel.stopListening('.BatchCompleted'));
    }

    if (callbacks.onBatchPartiallyCompleted) {
        channel.listen('.BatchPartiallyCompleted', callbacks.onBatchPartiallyCompleted);
        stopFns.push(() => channel.stopListening('.BatchPartiallyCompleted'));
    }

    if (callbacks.onSubscribed && typeof channel.subscribed === 'function') {
        channel.subscribed(callbacks.onSubscribed);
    }

    if (callbacks.onSubscribeError && typeof channel.error === 'function') {
        channel.error(callbacks.onSubscribeError);
    }

    // Only stopListening for the events this caller registered. The old
    // implementation called stopListening for every event whether or
    // not the caller subscribed — which detaches a sibling consumer
    // (two components watching the same user channel) on cleanup.
    return () => {
        stopFns.forEach((fn) => fn());
    };
}

export function listenForUploadComplete(
    echo: EchoInstance,
    uploadId: string,
    callback: (data: UploadCompletedData) => void,
    channelPrefix = 'chunky',
): () => void {
    const channel = echo.private(`${channelPrefix}.uploads.${uploadId}`);
    channel.listen('.UploadCompleted', callback);

    return () => {
        channel.stopListening('.UploadCompleted');
    };
}

export function listenForUploadEvents(
    echo: EchoInstance,
    uploadId: string,
    callbacks: {
        onComplete?: (data: UploadCompletedData) => void;
        onFailed?: (data: UploadFailedData) => void;
        onSubscribed?: () => void;
        onSubscribeError?: (err: unknown) => void;
    },
    channelPrefix = 'chunky',
): () => void {
    const channel = echo.private(`${channelPrefix}.uploads.${uploadId}`);
    const stopFns: Array<() => void> = [];

    if (callbacks.onComplete) {
        channel.listen('.UploadCompleted', callbacks.onComplete);
        stopFns.push(() => channel.stopListening('.UploadCompleted'));
    }

    if (callbacks.onFailed) {
        channel.listen('.UploadFailed', callbacks.onFailed);
        stopFns.push(() => channel.stopListening('.UploadFailed'));
    }

    if (callbacks.onSubscribed && typeof channel.subscribed === 'function') {
        channel.subscribed(callbacks.onSubscribed);
    }

    if (callbacks.onSubscribeError && typeof channel.error === 'function') {
        channel.error(callbacks.onSubscribeError);
    }

    return () => {
        stopFns.forEach((fn) => fn());
    };
}

export function listenForBatchComplete(
    echo: EchoInstance,
    batchId: string,
    callbacks: {
        onComplete?: (data: BatchCompletedData) => void;
        onPartiallyCompleted?: (data: BatchPartiallyCompletedData) => void;
        onSubscribed?: () => void;
        onSubscribeError?: (err: unknown) => void;
    },
    channelPrefix = 'chunky',
): () => void {
    const channel = echo.private(`${channelPrefix}.batches.${batchId}`);
    const stopFns: Array<() => void> = [];

    if (callbacks.onComplete) {
        channel.listen('.BatchCompleted', callbacks.onComplete);
        stopFns.push(() => channel.stopListening('.BatchCompleted'));
    }

    if (callbacks.onPartiallyCompleted) {
        channel.listen('.BatchPartiallyCompleted', callbacks.onPartiallyCompleted);
        stopFns.push(() => channel.stopListening('.BatchPartiallyCompleted'));
    }

    if (callbacks.onSubscribed && typeof channel.subscribed === 'function') {
        channel.subscribed(callbacks.onSubscribed);
    }

    if (callbacks.onSubscribeError && typeof channel.error === 'function') {
        channel.error(callbacks.onSubscribeError);
    }

    return () => {
        stopFns.forEach((fn) => fn());
    };
}
