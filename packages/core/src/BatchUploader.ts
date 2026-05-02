import { getDefaults } from './config';
import type { DefaultsScope } from './config';
import { ChunkUploader } from './ChunkUploader';
import { buildHeaders } from './http';
import type {
    BatchInitiateResponse,
    BatchProgressEvent,
    BatchResult,
    BatchUploadOptions,
    BatchUploaderEventMap,
    BatchUploaderState,
    EventCallback,
    ProgressEvent,
    Unsubscribe,
    UploadError,
    UploadResult,
} from './types';
import { UploadHttpError } from './types';

const DEFAULT_BATCH_ENDPOINTS = {
    batchInitiate: '/api/chunky/batch',
    batchUpload: '/api/chunky/batch/{batchId}/upload',
    batchStatus: '/api/chunky/batch/{batchId}',
};

interface PendingBatch {
    files: File[];
    metadata?: Record<string, unknown>;
    resolve: (result: BatchResult) => void;
    reject: (error: unknown) => void;
}

export class BatchUploader {
    /** @deprecated Read via `getState().batchId`. The public field will become private in v1.0. */
    batchId: string | null = null;
    /** @deprecated Read via `getState().totalFiles`. The public field will become private in v1.0. */
    totalFiles = 0;
    /** @deprecated Read via `getState().completedFiles`. The public field will become private in v1.0. */
    completedFiles = 0;
    /** @deprecated Read via `getState().failedFiles`. The public field will become private in v1.0. */
    failedFiles = 0;
    /** @deprecated Read via `getState().progress`. The public field will become private in v1.0. */
    progress = 0;
    /** @deprecated Read via `getState().isUploading`. The public field will become private in v1.0. */
    isUploading = false;
    /** @deprecated Read via `getState().isComplete`. The public field will become private in v1.0. */
    isComplete = false;
    /** @deprecated Read via `getState().error`. The public field will become private in v1.0. */
    error: string | null = null;
    /** @deprecated Read via `getState().currentFileName`. The public field will become private in v1.0. */
    currentFileName: string | null = null;

    private readonly maxConcurrentFiles: number;
    private readonly options: BatchUploadOptions;
    private readonly batchEndpoints: typeof DEFAULT_BATCH_ENDPOINTS;
    private readonly scope?: DefaultsScope;

    private uploaders: ChunkUploader[] = [];
    private results: UploadResult[] = [];
    private abortController: AbortController | null = null;
    private listeners = new Map<keyof BatchUploaderEventMap, Set<EventCallback>>();
    private lastComplete: BatchResult | null = null;
    private lastError: UploadError | null = null;
    private isPausedBatch = false;
    private resumeBarrier: Promise<void> | null = null;
    private resumeBarrierResolve: (() => void) | null = null;
    private pendingQueue: PendingBatch[] = [];
    // Set by `cancel()` for the duration of the current upload() call so the
    // catch/finally branches can tell "the user cancelled" apart from "an
    // unrelated error happened" and avoid emitting a redundant `error` event
    // or a late `complete` after a `cancel`.
    private cancelledThisRun = false;

    constructor(options: BatchUploadOptions = {}, scope?: DefaultsScope) {
        // Pull defaults from the scope (or the global singleton) and
        // merge into the call-site options so the BatchUploader's own
        // fetchJson() respects them too. The previous implementation
        // forwarded `scope` to the per-file ChunkUploader but the batch
        // initiate / status calls always read the bare global defaults
        // — confusing in a multi-scope setup.
        const defaults = scope ? scope.getDefaults() : getDefaults();
        const merged: BatchUploadOptions = {
            ...defaults,
            ...options,
            headers: { ...(defaults.headers as Record<string, string> | undefined), ...(options.headers as Record<string, string> | undefined) },
            endpoints: { ...defaults.endpoints, ...options.endpoints },
        };

        // Snapshot the options at construction time so a caller mutating
        // the original object after the fact (common in reactive
        // frameworks) does not bleed into mid-flight uploads.
        this.options = merged;
        this.scope = scope;
        this.maxConcurrentFiles = merged.maxConcurrentFiles ?? 2;
        this.batchEndpoints = {
            ...DEFAULT_BATCH_ENDPOINTS,
            // Legacy mixed shape: pull batch-only fields out of
            // `endpoints` if present.
            ...(options.endpoints?.batchInitiate ? { batchInitiate: options.endpoints.batchInitiate } : {}),
            ...(options.endpoints?.batchUpload ? { batchUpload: options.endpoints.batchUpload } : {}),
            ...(options.endpoints?.batchStatus ? { batchStatus: options.endpoints.batchStatus } : {}),
            // New explicit batch-only override.
            ...(options.batchEndpoints ?? {}),
        };

        this.validateBatchEndpoints();
    }

    /**
     * Refuse to construct with malformed batch endpoints. Without this
     * a typo (`/api/chunky/batch/{batch_id}/upload` with the wrong
     * casing) would leave the `{batchId}` placeholder unsubstituted at
     * call time, and the server would 404 on a URL containing the
     * literal `{batchId}` token — extremely confusing to debug.
     */
    private validateBatchEndpoints(): void {
        if (!this.batchEndpoints.batchUpload.includes('{batchId}')) {
            throw new Error('BatchUploader: batchUpload endpoint must contain a {batchId} placeholder.');
        }

        if (!this.batchEndpoints.batchStatus.includes('{batchId}')) {
            throw new Error('BatchUploader: batchStatus endpoint must contain a {batchId} placeholder.');
        }
    }

    on<K extends keyof BatchUploaderEventMap>(event: K, callback: (data: BatchUploaderEventMap[K]) => void): Unsubscribe {
        if (!this.listeners.has(event)) {
            this.listeners.set(event, new Set());
        }

        const set = this.listeners.get(event)!;
        const stored = callback as EventCallback;
        set.add(stored);

        // Sticky-replay: re-deliver the last `complete` / `error` to a
        // late subscriber so React/Vue cleanup-then-resubscribe patterns
        // don't lose terminal events. The microtask delivery has a
        // late-arrival guard: if the caller synchronously unsubscribes
        // before the microtask runs (e.g. `useEffect` cleanup right
        // after subscription), skip the replay — otherwise the callback
        // fires *after* explicit unsubscribe.
        if (event === 'complete' && this.lastComplete) {
            const sticky = this.lastComplete;
            queueMicrotask(() => {
                if (set.has(stored)) {
                    (callback as (d: BatchResult) => void)(sticky);
                }
            });
        } else if (event === 'error' && this.lastError) {
            const sticky = this.lastError;
            queueMicrotask(() => {
                if (set.has(stored)) {
                    (callback as (d: UploadError) => void)(sticky);
                }
            });
        }

        return () => {
            set.delete(stored);
        };
    }

    private emit<K extends keyof BatchUploaderEventMap>(event: K, data: BatchUploaderEventMap[K]): void {
        if (event === 'complete') {
            this.lastComplete = data as BatchResult;
            this.lastError = null;
        } else if (event === 'error') {
            this.lastError = data as UploadError;
        }

        this.listeners.get(event)?.forEach((cb) => (cb as EventCallback<BatchUploaderEventMap[K]>)(data));
    }

    private emitStateChange(): void {
        this.emit('stateChange', this.getState());
    }

    getState(): BatchUploaderState {
        return {
            batchId: this.batchId,
            totalFiles: this.totalFiles,
            completedFiles: this.completedFiles,
            failedFiles: this.failedFiles,
            progress: this.progress,
            isUploading: this.isUploading,
            isComplete: this.isComplete,
            error: this.error,
            currentFileName: this.currentFileName,
        };
    }

    private async fetchJson<T>(url: string, init: RequestInit, signal?: AbortSignal): Promise<T> {
        const response = await fetch(url, {
            ...init,
            credentials: this.options.withCredentials !== false ? 'include' : 'same-origin',
            signal: signal ?? this.abortController?.signal,
        });

        if (!response.ok) {
            const text = await response.text();
            let body: unknown = text;

            try {
                body = JSON.parse(text);
            } catch {
                // Non-JSON body — keep the raw text.
            }

            throw new UploadHttpError(
                response.status,
                body,
                `HTTP ${response.status}: ${text || response.statusText}`,
            );
        }

        return response.json();
    }

    async upload(files: File[], metadata?: Record<string, unknown>): Promise<BatchResult> {
        if (this.isUploading) {
            throw new Error('Batch upload already in progress.');
        }

        this.abortController = new AbortController();
        this.totalFiles = files.length;
        this.completedFiles = 0;
        this.failedFiles = 0;
        this.progress = 0;
        this.isUploading = true;
        this.isComplete = false;
        this.error = null;
        this.results = [];
        this.uploaders = [];
        this.lastComplete = null;
        this.lastError = null;
        this.cancelledThisRun = false;
        this.emitStateChange();

        const signal = this.abortController.signal;

        try {
            // Initiate batch on server
            const batchResponse = await this.fetchJson<BatchInitiateResponse>(
                this.batchEndpoints.batchInitiate,
                {
                    method: 'POST',
                    headers: { ...buildHeaders(this.options.headers), 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        total_files: files.length,
                        context: this.options.context ?? null,
                        metadata: metadata ?? null,
                    }),
                },
                signal,
            );

            this.batchId = batchResponse.batch_id;
            this.emitStateChange();

            // Upload files with concurrency limit
            const fileQueue = [...files];
            let fileIndex = 0;

            const next = async (): Promise<void> => {
                while (fileIndex < fileQueue.length) {
                    if (this.abortController?.signal.aborted) {
                        return;
                    }

                    if (this.isPausedBatch && this.resumeBarrier) {
                        await this.resumeBarrier;

                        if (this.abortController?.signal.aborted) {
                            return;
                        }
                    }

                    const currentIndex = fileIndex++;
                    const file = fileQueue[currentIndex];

                    this.currentFileName = file.name;
                    this.emitStateChange();

                    try {
                        const result = await this.uploadFileInBatch(file, metadata);
                        this.results.push(result);
                        this.completedFiles++;

                        this.emit('fileComplete', result);
                    } catch (err) {
                        this.failedFiles++;

                        const uploadError: UploadError = {
                            uploadId: null,
                            message: err instanceof Error ? err.message : 'File upload failed',
                            cause: err instanceof UploadHttpError || err instanceof Error ? err : undefined,
                            cancelled: this.cancelledThisRun,
                        };

                        this.emit('fileError', uploadError);
                    }

                    this.emitProgress();
                }
            };

            const workers = Array.from(
                { length: Math.min(this.maxConcurrentFiles, files.length) },
                () => next(),
            );

            await Promise.all(workers);

            // If `cancel()` ran during the very last tick (after all workers
            // resolved but before this point), don't emit `complete` —
            // honour the cancel that already went out and surface the
            // partial result without a sticky-cache replay.
            if (this.cancelledThisRun || this.abortController?.signal.aborted) {
                return {
                    batchId: this.batchId ?? '',
                    totalFiles: this.totalFiles,
                    completedFiles: this.completedFiles,
                    failedFiles: this.failedFiles,
                    files: this.results,
                };
            }

            this.isComplete = true;
            this.currentFileName = null;
            this.progress = 100;
            this.emitStateChange();

            const result: BatchResult = {
                batchId: this.batchId!,
                totalFiles: this.totalFiles,
                completedFiles: this.completedFiles,
                failedFiles: this.failedFiles,
                files: this.results,
            };

            this.emit('complete', result);

            return result;
        } catch (err) {
            // Tear down any in-flight per-file uploaders so they stop
            // POSTing chunks against a batch the caller has already given
            // up on. Without this, `error` fires on the public surface but
            // the network keeps churning until each ChunkUploader naturally
            // finishes.
            for (const uploader of this.uploaders) {
                uploader.cancel();
            }

            // If the caller already cancelled, the `cancel` event has gone
            // out and `error` would be redundant noise. Re-throw so the
            // returned promise still rejects (pause/resume helpers depend
            // on this), but skip the public `error` notification.
            if (this.cancelledThisRun) {
                throw err;
            }

            const message = err instanceof Error ? err.message : 'Batch upload failed';
            this.error = message;
            this.emitStateChange();

            const uploadError: UploadError = {
                uploadId: null,
                message,
                cause: err instanceof UploadHttpError || err instanceof Error ? err : undefined,
                cancelled: this.cancelledThisRun,
            };

            this.emit('error', uploadError);
            throw err;
        } finally {
            this.isUploading = false;
            this.emitStateChange();
            this.drainQueue();
        }
    }

    /**
     * Queue files for upload. If no batch is currently running, this behaves
     * exactly like `upload()`. If a batch is in progress, the files are held
     * until the current batch completes (or fails) and then run in their own
     * batch. The returned promise resolves with the eventual `BatchResult`.
     *
     * If `cancel()` or `destroy()` is invoked before the queued batch starts,
     * the returned promise rejects with the corresponding error.
     */
    enqueue(files: File[], metadata?: Record<string, unknown>): Promise<BatchResult> {
        if (!this.isUploading) {
            return this.upload(files, metadata);
        }

        return new Promise<BatchResult>((resolve, reject) => {
            this.pendingQueue.push({ files, metadata, resolve, reject });
        });
    }

    private drainQueue(): void {
        if (this.isUploading || this.pendingQueue.length === 0) {
            return;
        }

        // Defer to a microtask so we don't recurse inside the previous
        // upload's `finally` stack frame. Re-check state inside the microtask
        // because `cancel()` / `destroy()` may have flushed the queue between
        // schedule time and run time.
        queueMicrotask(() => {
            if (this.isUploading || this.pendingQueue.length === 0) {
                return;
            }

            const next = this.pendingQueue.shift()!;

            this.upload(next.files, next.metadata).then(next.resolve, next.reject);
        });
    }

    private rejectPendingQueue(reason: string): void {
        if (this.pendingQueue.length === 0) {
            return;
        }

        const pending = this.pendingQueue.splice(0);
        const error = new Error(reason);

        for (const item of pending) {
            item.reject(error);
        }
    }

    private async uploadFileInBatch(file: File, metadata?: Record<string, unknown>): Promise<UploadResult> {
        const batchUploadEndpoint = this.batchEndpoints.batchUpload.replace('{batchId}', this.batchId!);

        const uploader = new ChunkUploader(
            {
                ...this.options,
                endpoints: {
                    ...this.options.endpoints,
                    initiate: batchUploadEndpoint,
                },
            },
            this.scope,
        );

        this.uploaders.push(uploader);

        uploader.on('progress', (event: ProgressEvent) => {
            this.emit('fileProgress', {
                batchId: this.batchId ?? '',
                uploadId: event.uploadId,
                fileName: file.name,
                loaded: event.loaded,
                total: event.total,
                percentage: event.percentage,
                chunkIndex: event.chunkIndex,
                totalChunks: event.totalChunks,
            });

            this.emitProgress(uploader);
        });

        try {
            return await uploader.upload(file, metadata);
        } finally {
            // Drop the per-file uploader as soon as it's done — without
            // this the array grows forever for the lifetime of the
            // BatchUploader, retaining 100MB+ File references and the
            // closure-captured lastFile state. The aggregate progress
            // walk (line ~410) skipped finished uploaders already, so
            // the only callers of `this.uploaders` after this are
            // `cancel()` / `pause()` / `destroy()`, which are no-ops on
            // already-completed uploaders.
            const idx = this.uploaders.indexOf(uploader);

            if (idx >= 0) {
                this.uploaders.splice(idx, 1);
            }

            uploader.destroy();
        }
    }

    private aggregateProgress(): number {
        if (this.totalFiles === 0) {
            return 0;
        }

        // Walk only the uploaders that are currently in flight. For a
        // 1000-file batch with a maxConcurrentFiles of 2, this scans 2
        // entries per progress event instead of 1000 — keeps the
        // event-emit hot path O(maxConcurrentFiles) regardless of batch
        // size.
        let inProgressContribution = 0;

        for (const uploader of this.uploaders) {
            if (uploader.isUploading && !uploader.isComplete) {
                inProgressContribution += uploader.progress / 100;
            }
        }

        const finishedFiles = this.completedFiles + this.failedFiles;
        const total = finishedFiles + inProgressContribution;

        return Math.min(100, (total / this.totalFiles) * 100);
    }

    private emitProgress(uploader?: ChunkUploader): void {
        this.progress = this.aggregateProgress();

        this.emit('progress', {
            batchId: this.batchId ?? '',
            completedFiles: this.completedFiles,
            totalFiles: this.totalFiles,
            failedFiles: this.failedFiles,
            percentage: this.progress,
            currentFile: uploader?.currentFile
                ? { name: uploader.currentFile.name, progress: uploader.progress }
                : null,
        });

        this.emitStateChange();
    }

    cancel(): void {
        const cancelledBatchId = this.batchId;

        this.cancelledThisRun = true;
        this.abortController?.abort();

        // Release the pause barrier so any awaiting workers can observe the abort.
        this.isPausedBatch = false;
        const resolve = this.resumeBarrierResolve;
        this.resumeBarrier = null;
        this.resumeBarrierResolve = null;
        resolve?.();

        for (const uploader of this.uploaders) {
            uploader.cancel();
        }

        this.rejectPendingQueue('Batch upload cancelled before queued upload could start.');

        this.isUploading = false;
        this.isComplete = false;
        this.currentFileName = null;
        this.lastComplete = null;
        this.lastError = null;
        this.emit('cancel', { batchId: cancelledBatchId });
        this.emitStateChange();
    }

    pause(): void {
        if (!this.isUploading) {
            return;
        }

        this.isPausedBatch = true;

        if (!this.resumeBarrier) {
            this.resumeBarrier = new Promise<void>((resolve) => {
                this.resumeBarrierResolve = resolve;
            });
        }

        for (const uploader of this.uploaders) {
            uploader.pause();
        }

        this.emitStateChange();
    }

    resume(): void {
        if (!this.isPausedBatch) {
            return;
        }

        this.isPausedBatch = false;

        const resolve = this.resumeBarrierResolve;
        this.resumeBarrier = null;
        this.resumeBarrierResolve = null;
        resolve?.();

        for (const uploader of this.uploaders) {
            uploader.resume();
        }

        this.emitStateChange();
    }

    destroy(): void {
        // Reject queued items with the destroy reason BEFORE cancel() runs,
        // because cancel() will otherwise empty the queue under the generic
        // "cancelled" reason and the destroy-specific reject becomes a no-op.
        this.rejectPendingQueue('BatchUploader destroyed before queued upload could start.');
        this.cancel();
        this.listeners.clear();

        for (const uploader of this.uploaders) {
            uploader.destroy();
        }

        this.uploaders = [];
    }
}
