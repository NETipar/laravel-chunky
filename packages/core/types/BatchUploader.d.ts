import type { DefaultsScope } from './config';
import type { BatchResult, BatchUploadOptions, BatchUploaderEventMap, BatchUploaderState, Unsubscribe } from './types';
export declare class BatchUploader {
    /** @deprecated Read via `getState().batchId`. The public field will become private in v1.0. */
    batchId: string | null;
    /** @deprecated Read via `getState().totalFiles`. The public field will become private in v1.0. */
    totalFiles: number;
    /** @deprecated Read via `getState().completedFiles`. The public field will become private in v1.0. */
    completedFiles: number;
    /** @deprecated Read via `getState().failedFiles`. The public field will become private in v1.0. */
    failedFiles: number;
    /** @deprecated Read via `getState().progress`. The public field will become private in v1.0. */
    progress: number;
    /** @deprecated Read via `getState().isUploading`. The public field will become private in v1.0. */
    isUploading: boolean;
    /** @deprecated Read via `getState().isComplete`. The public field will become private in v1.0. */
    isComplete: boolean;
    /** @deprecated Read via `getState().error`. The public field will become private in v1.0. */
    error: string | null;
    /** @deprecated Read via `getState().currentFileName`. The public field will become private in v1.0. */
    currentFileName: string | null;
    private readonly maxConcurrentFiles;
    private readonly options;
    private readonly batchEndpoints;
    private readonly scope?;
    private uploaders;
    private results;
    private abortController;
    private listeners;
    private lastComplete;
    private lastError;
    private isPausedBatch;
    private resumeBarrier;
    private resumeBarrierResolve;
    private pendingQueue;
    private cancelledThisRun;
    constructor(options?: BatchUploadOptions, scope?: DefaultsScope);
    /**
     * Refuse to construct with malformed batch endpoints. Without this
     * a typo (`/api/chunky/batch/{batch_id}/upload` with the wrong
     * casing) would leave the `{batchId}` placeholder unsubstituted at
     * call time, and the server would 404 on a URL containing the
     * literal `{batchId}` token — extremely confusing to debug.
     */
    private validateBatchEndpoints;
    on<K extends keyof BatchUploaderEventMap>(event: K, callback: (data: BatchUploaderEventMap[K]) => void): Unsubscribe;
    private emit;
    private emitStateChange;
    getState(): BatchUploaderState;
    private fetchJson;
    upload(files: File[], metadata?: Record<string, unknown>): Promise<BatchResult>;
    /**
     * Queue files for upload. If no batch is currently running, this behaves
     * exactly like `upload()`. If a batch is in progress, the files are held
     * until the current batch completes (or fails) and then run in their own
     * batch. The returned promise resolves with the eventual `BatchResult`.
     *
     * If `cancel()` or `destroy()` is invoked before the queued batch starts,
     * the returned promise rejects with the corresponding error.
     */
    enqueue(files: File[], metadata?: Record<string, unknown>): Promise<BatchResult>;
    private drainQueue;
    private rejectPendingQueue;
    private uploadFileInBatch;
    private aggregateProgress;
    private emitProgress;
    cancel(): void;
    pause(): void;
    resume(): void;
    destroy(): void;
}
