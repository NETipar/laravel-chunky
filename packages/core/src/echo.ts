export interface EchoInstance {
    private(channel: string): EchoChannel;
}

export interface EchoChannel {
    listen(event: string, callback: (data: any) => void): EchoChannel;
    stopListening(event: string): EchoChannel;
}

export interface UploadCompletedData {
    uploadId: string;
    finalPath: string;
    disk: string;
    fileName: string;
    fileSize: number;
    context: string | null;
    status: string;
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

export function listenForBatchComplete(
    echo: EchoInstance,
    batchId: string,
    callbacks: {
        onComplete?: (data: BatchCompletedData) => void;
        onPartiallyCompleted?: (data: BatchPartiallyCompletedData) => void;
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

    return () => {
        channel.stopListening('.BatchCompleted');
        channel.stopListening('.BatchPartiallyCompleted');
    };
}
