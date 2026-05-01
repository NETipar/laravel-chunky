export interface ChunkUploadOptions {
    chunkSize?: number;
    maxConcurrent?: number;
    autoRetry?: boolean;
    maxRetries?: number;
    headers?: Record<string, string>;
    withCredentials?: boolean;
    context?: string;
    checksum?: boolean;
    endpoints?: {
        initiate?: string;
        upload?: string;
        status?: string;
        cancel?: string;
    };
}

export interface UploadResult {
    uploadId: string;
    fileName: string;
    fileSize: number;
    totalChunks: number;
}

export interface UploadError {
    uploadId: string | null;
    chunkIndex?: number;
    message: string;
    /**
     * The underlying error. UploadHttpError when the failure came from
     * a chunk POST response, a generic Error otherwise.
     */
    cause?: UploadHttpError | Error;
    /**
     * True when the failure is the result of an explicit cancel() call,
     * false / undefined for genuine transport / server failures. Lets
     * the caller distinguish "user cancelled" from "network died"
     * without parsing the error message.
     */
    cancelled?: boolean;
}

export class UploadHttpError extends Error {
    public readonly status: number;
    public readonly body: unknown;

    constructor(status: number, body: unknown, message: string) {
        super(message);
        this.name = 'UploadHttpError';
        this.status = status;
        this.body = body;
    }
}

export interface ChunkInfo {
    index: number;
    size: number;
    checksum: string | null;
    uploadId: string;
}

export interface ProgressEvent {
    uploadId: string;
    loaded: number;
    total: number;
    percentage: number;
    chunkIndex: number;
    totalChunks: number;
}

export interface InitiateResponse {
    upload_id: string;
    chunk_size: number;
    total_chunks: number;
}

export interface ChunkUploadResponse {
    chunk_index: number;
    is_complete: boolean;
    uploaded_count: number;
    total_chunks: number;
    progress: number;
}

export interface StatusResponse {
    upload_id: string;
    file_name: string;
    file_size: number;
    mime_type: string | null;
    chunk_size: number;
    total_chunks: number;
    uploaded_chunks: number[];
    uploaded_count: number;
    progress: number;
    status: string;
    final_path: string | null;
}

export interface ChunkUploaderState {
    progress: number;
    isUploading: boolean;
    isPaused: boolean;
    isComplete: boolean;
    error: string | null;
    uploadId: string | null;
    uploadedChunks: number;
    totalChunks: number;
    currentFile: File | null;
}

export type ChunkUploaderEventMap = {
    progress: ProgressEvent;
    chunkUploaded: ChunkInfo;
    complete: UploadResult;
    error: UploadError;
    stateChange: ChunkUploaderState;
};

export type Unsubscribe = () => void;

/**
 * Internal event-listener storage type. Public `on()` overloads keep the
 * caller strongly typed; the internal Set just needs to hold any callable
 * for any event payload.
 */
export type EventCallback<T = unknown> = (data: T) => void;

// Batch types

export interface BatchUploadOptions extends ChunkUploadOptions {
    maxConcurrentFiles?: number;
    endpoints?: ChunkUploadOptions['endpoints'] & {
        batchInitiate?: string;
        batchUpload?: string;
        batchStatus?: string;
    };
}

export interface BatchInitiateResponse {
    batch_id: string;
}

export interface BatchProgressEvent {
    batchId: string;
    completedFiles: number;
    totalFiles: number;
    failedFiles: number;
    percentage: number;
    currentFile: { name: string; progress: number } | null;
}

export interface FileProgressEvent {
    batchId: string;
    uploadId: string;
    fileName: string;
    loaded: number;
    total: number;
    percentage: number;
    chunkIndex: number;
    totalChunks: number;
}

export interface BatchResult {
    batchId: string;
    totalFiles: number;
    completedFiles: number;
    failedFiles: number;
    files: UploadResult[];
}

export interface BatchUploaderState {
    batchId: string | null;
    totalFiles: number;
    completedFiles: number;
    failedFiles: number;
    progress: number;
    isUploading: boolean;
    isComplete: boolean;
    error: string | null;
    currentFileName: string | null;
}

export interface BatchCancelEvent {
    batchId: string | null;
}

export type BatchUploaderEventMap = {
    progress: BatchProgressEvent;
    fileProgress: FileProgressEvent;
    fileComplete: UploadResult;
    fileError: UploadError;
    complete: BatchResult;
    error: UploadError;
    cancel: BatchCancelEvent;
    stateChange: BatchUploaderState;
};
