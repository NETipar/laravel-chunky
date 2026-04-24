import { getDefaults } from './config';
import type { DefaultsScope } from './config';
import { ChunkUploader } from './ChunkUploader';
import type {
    BatchInitiateResponse,
    BatchProgressEvent,
    BatchResult,
    BatchUploadOptions,
    BatchUploaderEventMap,
    BatchUploaderState,
    Unsubscribe,
    UploadError,
    UploadResult,
} from './types';

const DEFAULT_BATCH_ENDPOINTS = {
    batchInitiate: '/api/chunky/batch',
    batchUpload: '/api/chunky/batch/{batchId}/upload',
    batchStatus: '/api/chunky/batch/{batchId}',
};

export class BatchUploader {
    batchId: string | null = null;
    totalFiles = 0;
    completedFiles = 0;
    failedFiles = 0;
    progress = 0;
    isUploading = false;
    isComplete = false;
    error: string | null = null;
    currentFileName: string | null = null;

    private readonly maxConcurrentFiles: number;
    private readonly options: BatchUploadOptions;
    private readonly batchEndpoints: typeof DEFAULT_BATCH_ENDPOINTS;
    private readonly scope?: DefaultsScope;

    private uploaders: ChunkUploader[] = [];
    private results: UploadResult[] = [];
    private abortController: AbortController | null = null;
    private listeners = new Map<string, Set<Function>>();

    constructor(options: BatchUploadOptions = {}, scope?: DefaultsScope) {
        this.options = options;
        this.scope = scope;
        this.maxConcurrentFiles = options.maxConcurrentFiles ?? 2;
        this.batchEndpoints = {
            ...DEFAULT_BATCH_ENDPOINTS,
            ...options.endpoints,
        };
    }

    on<K extends keyof BatchUploaderEventMap>(event: K, callback: (data: BatchUploaderEventMap[K]) => void): Unsubscribe {
        if (!this.listeners.has(event)) {
            this.listeners.set(event, new Set());
        }

        this.listeners.get(event)!.add(callback);

        return () => {
            this.listeners.get(event)?.delete(callback);
        };
    }

    private emit<K extends keyof BatchUploaderEventMap>(event: K, data: BatchUploaderEventMap[K]): void {
        this.listeners.get(event)?.forEach((cb) => cb(data));
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

    private getCsrfFromCookie(): string | null {
        if (typeof document === 'undefined') {
            return null;
        }

        const match = document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='));

        if (!match) {
            return null;
        }

        return decodeURIComponent(match.split('=')[1]);
    }

    private getHeaders(): Record<string, string> {
        const headers: Record<string, string> = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(this.options.headers ?? {}),
        };

        if (!headers['X-XSRF-TOKEN']) {
            const token = this.getCsrfFromCookie();

            if (token) {
                headers['X-XSRF-TOKEN'] = token;
            }
        }

        return headers;
    }

    private async fetchJson<T>(url: string, init: RequestInit): Promise<T> {
        const response = await fetch(url, {
            ...init,
            credentials: this.options.withCredentials !== false ? 'include' : 'same-origin',
            signal: this.abortController?.signal,
        });

        if (!response.ok) {
            const body = await response.text();
            throw new Error(`HTTP ${response.status}: ${body}`);
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
        this.emitStateChange();

        try {
            // Initiate batch on server
            const batchResponse = await this.fetchJson<BatchInitiateResponse>(
                this.batchEndpoints.batchInitiate,
                {
                    method: 'POST',
                    headers: { ...this.getHeaders(), 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        total_files: files.length,
                        context: this.options.context ?? null,
                        metadata: metadata ?? null,
                    }),
                },
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
                            cause: err,
                        };

                        this.emit('fileError', uploadError);
                    }

                    this.progress = ((this.completedFiles + this.failedFiles) / this.totalFiles) * 100;
                    this.emitStateChange();

                    this.emitProgress();
                }
            };

            const workers = Array.from(
                { length: Math.min(this.maxConcurrentFiles, files.length) },
                () => next(),
            );

            await Promise.all(workers);

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
            const message = err instanceof Error ? err.message : 'Batch upload failed';
            this.error = message;
            this.emitStateChange();

            const uploadError: UploadError = {
                uploadId: null,
                message,
                cause: err,
            };

            this.emit('error', uploadError);
            throw err;
        } finally {
            this.isUploading = false;
            this.emitStateChange();
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

        uploader.on('progress', () => {
            this.emitProgress(uploader);
        });

        return uploader.upload(file, metadata);
    }

    private emitProgress(uploader?: ChunkUploader): void {
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
    }

    cancel(): void {
        this.abortController?.abort();

        for (const uploader of this.uploaders) {
            uploader.cancel();
        }

        this.isUploading = false;
        this.currentFileName = null;
        this.emitStateChange();
    }

    pause(): void {
        for (const uploader of this.uploaders) {
            uploader.pause();
        }
    }

    resume(): void {
        for (const uploader of this.uploaders) {
            uploader.resume();
        }
    }

    destroy(): void {
        this.cancel();
        this.listeners.clear();

        for (const uploader of this.uploaders) {
            uploader.destroy();
        }

        this.uploaders = [];
    }
}
