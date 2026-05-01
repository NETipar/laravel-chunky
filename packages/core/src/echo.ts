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

    if (callbacks.onUploadComplete) {
        channel.listen('.UploadCompleted', callbacks.onUploadComplete);
    }

    if (callbacks.onUploadFailed) {
        channel.listen('.UploadFailed', callbacks.onUploadFailed);
    }

    if (callbacks.onBatchComplete) {
        channel.listen('.BatchCompleted', callbacks.onBatchComplete);
    }

    if (callbacks.onBatchPartiallyCompleted) {
        channel.listen('.BatchPartiallyCompleted', callbacks.onBatchPartiallyCompleted);
    }

    if (callbacks.onSubscribed && typeof channel.subscribed === 'function') {
        channel.subscribed(callbacks.onSubscribed);
    }

    if (callbacks.onSubscribeError && typeof channel.error === 'function') {
        channel.error(callbacks.onSubscribeError);
    }

    return () => {
        channel.stopListening('.UploadCompleted');
        channel.stopListening('.UploadFailed');
        channel.stopListening('.BatchCompleted');
        channel.stopListening('.BatchPartiallyCompleted');
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

    if (callbacks.onComplete) {
        channel.listen('.UploadCompleted', callbacks.onComplete);
    }

    if (callbacks.onFailed) {
        channel.listen('.UploadFailed', callbacks.onFailed);
    }

    if (callbacks.onSubscribed && typeof channel.subscribed === 'function') {
        channel.subscribed(callbacks.onSubscribed);
    }

    if (callbacks.onSubscribeError && typeof channel.error === 'function') {
        channel.error(callbacks.onSubscribeError);
    }

    return () => {
        channel.stopListening('.UploadCompleted');
        channel.stopListening('.UploadFailed');
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

    if (callbacks.onComplete) {
        channel.listen('.BatchCompleted', callbacks.onComplete);
    }

    if (callbacks.onPartiallyCompleted) {
        channel.listen('.BatchPartiallyCompleted', callbacks.onPartiallyCompleted);
    }

    if (callbacks.onSubscribed && typeof channel.subscribed === 'function') {
        channel.subscribed(callbacks.onSubscribed);
    }

    if (callbacks.onSubscribeError && typeof channel.error === 'function') {
        channel.error(callbacks.onSubscribeError);
    }

    return () => {
        channel.stopListening('.BatchCompleted');
        channel.stopListening('.BatchPartiallyCompleted');
    };
}
