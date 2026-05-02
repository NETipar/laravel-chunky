/**
 * JSON-serialisable metadata. The shape that survives a round-trip
 * through `JSON.stringify` / `JSON.parse` — i.e. no Date, Map, Set,
 * RegExp, function, or class instance. The previous wide
 * `Record<string, unknown>` accepted those types silently and they
 * vanished on the wire (`{}` for class instances, ISO string for
 * Date), which made the client and server disagree.
 */
export type JsonPrimitive = string | number | boolean | null;
export type JsonValue = JsonPrimitive | JsonValue[] | {
    [key: string]: JsonValue;
};
export type JsonObject = {
    [key: string]: JsonValue;
};
/**
 * Optional persistence hook for `BatchUploader.enqueue()` — lets the
 * pending-batch queue survive a page reload. The default in-memory
 * queue forgets enqueued batches when the tab navigates away; a
 * caller-supplied IndexedDB / localStorage adapter can rehydrate them.
 *
 * Files cannot be persisted directly (they're not serialisable), so an
 * adapter typically stores file references the host app can re-resolve
 * on load (e.g. a tuple of `(handle, name, size)` for the File System
 * Access API, or a server-side staged-upload id).
 */
export interface BatchPersistence<TSerialized = unknown> {
    save(payload: TSerialized): Promise<void> | void;
    load(): Promise<TSerialized | null> | TSerialized | null;
    clear(): Promise<void> | void;
}
export interface ChunkUploadOptions {
    chunkSize?: number;
    maxConcurrent?: number;
    /**
     * Retry policy for chunk POSTs. Three forms:
     * - `true` (default): retry every error up to `maxRetries`.
     * - `false`: never retry.
     * - `(error, context) => boolean`: callback decides per error. Useful
     *   for refusing to retry fatal HTTP statuses (401, 403, 422) — those
     *   won't change on retry. The context carries the chunkIndex and the
     *   number of retries left.
     */
    autoRetry?: boolean | ((error: UploadHttpError | Error, context: {
        chunkIndex: number;
        retriesLeft: number;
    }) => boolean);
    maxRetries?: number;
    /**
     * Custom headers added to every chunk POST. Accepts the three forms
     * `HeadersInit` does: `Record<string, string>`, `[string, string][]`,
     * or a `Headers` instance — buildHeaders() normalises to a flat
     * record internally.
     */
    headers?: HeadersInit;
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
export declare class UploadHttpError extends Error {
    readonly status: number;
    readonly body: unknown;
    constructor(status: number, body: unknown, message: string);
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
/**
 * Batch-specific endpoints. Split out from the per-chunk endpoints
 * object so the two surfaces can evolve independently. New code should
 * prefer `chunkEndpoints` / `batchEndpoints` over the legacy mixed
 * `endpoints` map.
 */
export interface BatchEndpoints {
    batchInitiate?: string;
    batchUpload?: string;
    batchStatus?: string;
}
export interface BatchUploadOptions extends ChunkUploadOptions {
    maxConcurrentFiles?: number;
    /**
     * Legacy mixed-shape endpoints map (per-chunk + batch fields in one
     * object). Still accepted for back-compat. New code should use
     * `chunkEndpoints` / `batchEndpoints` instead.
     */
    endpoints?: ChunkUploadOptions['endpoints'] & BatchEndpoints;
    /** Per-file ChunkUploader endpoints (override the global defaults). */
    chunkEndpoints?: ChunkUploadOptions['endpoints'];
    /** Batch-only endpoints. */
    batchEndpoints?: BatchEndpoints;
    /**
     * Adapter to persist the pending batch queue across page reloads.
     * The adapter is responsible for serialising whatever the host app
     * needs to re-resolve File references (handles, server-side staging
     * ids, etc.). When provided, BatchUploader saves the queue on
     * enqueue and clears it on a clean drain.
     */
    persistence?: BatchPersistence;
}
export interface BatchInitiateResponse {
    batch_id: string;
    /** Server-supplied extras pass through unchecked. */
    [extra: string]: unknown;
}
export interface BatchProgressEvent {
    batchId: string;
    completedFiles: number;
    totalFiles: number;
    failedFiles: number;
    percentage: number;
    currentFile: {
        name: string;
        progress: number;
    } | null;
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
