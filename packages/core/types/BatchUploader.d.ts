import type { DefaultsScope } from './config';
import type { BatchResult, BatchUploadOptions, BatchUploaderEventMap, BatchUploaderState, Unsubscribe } from './types';
export declare class BatchUploader {
    batchId: string | null;
    totalFiles: number;
    completedFiles: number;
    failedFiles: number;
    progress: number;
    isUploading: boolean;
    isComplete: boolean;
    error: string | null;
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
