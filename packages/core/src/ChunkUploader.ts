import { getDefaults } from './config';
import type { DefaultsScope } from './config';
import { buildHeaders } from './http';
import type {
    ChunkInfo,
    ChunkUploadOptions,
    ChunkUploadResponse,
    ChunkUploaderEventMap,
    ChunkUploaderState,
    InitiateResponse,
    ProgressEvent,
    StatusResponse,
    Unsubscribe,
    UploadError,
    UploadResult,
} from './types';
import { UploadHttpError } from './types';

const DEFAULT_ENDPOINTS = {
    initiate: '/api/chunky/upload',
    upload: '/api/chunky/upload/{uploadId}/chunks',
    status: '/api/chunky/upload/{uploadId}',
    cancel: '/api/chunky/upload/{uploadId}',
};

async function computeChecksum(data: Blob): Promise<string | null> {
    if (typeof crypto === 'undefined' || !crypto.subtle) {
        return null;
    }

    const buffer = await data.arrayBuffer();
    const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
    const hashArray = Array.from(new Uint8Array(hashBuffer));

    return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('');
}

export class ChunkUploader {
    progress = 0;
    isUploading = false;
    isPaused = false;
    isComplete = false;
    error: string | null = null;
    uploadId: string | null = null;
    uploadedChunks = 0;
    totalChunks = 0;
    currentFile: File | null = null;

    private readonly maxConcurrent: number;
    private readonly autoRetry: boolean;
    private readonly maxRetries: number;
    private readonly headers: Record<string, string>;
    private readonly withCredentials: boolean;
    private readonly context?: string;
    private readonly checksumEnabled: boolean;
    private readonly chunkSizeOverride?: number;
    private readonly endpoints: typeof DEFAULT_ENDPOINTS;

    private abortController: AbortController | null = null;
    private pendingChunks: number[] = [];
    private serverChunkSize: number | null = null;
    private lastFile: File | null = null;
    private lastMetadata?: Record<string, unknown>;
    private listeners = new Map<string, Set<Function>>();
    private lastComplete: UploadResult | null = null;
    private lastError: UploadError | null = null;

    constructor(options: ChunkUploadOptions = {}, scope?: DefaultsScope) {
        const defaults = scope ? scope.getDefaults() : getDefaults();
        const merged = { ...defaults, ...options };

        this.maxConcurrent = merged.maxConcurrent ?? 3;
        this.autoRetry = merged.autoRetry ?? true;
        this.maxRetries = merged.maxRetries ?? 3;
        this.headers = { ...defaults.headers, ...options.headers };
        this.withCredentials = merged.withCredentials ?? true;
        this.context = merged.context;
        this.checksumEnabled = merged.checksum ?? true;
        this.chunkSizeOverride = merged.chunkSize;
        this.endpoints = { ...DEFAULT_ENDPOINTS, ...defaults.endpoints, ...options.endpoints };

        this.validateEndpoints();
    }

    private validateEndpoints(): void {
        if (!this.endpoints.upload.includes('{uploadId}')) {
            throw new Error('Upload endpoint must contain "{uploadId}" placeholder.');
        }

        if (!this.endpoints.status.includes('{uploadId}')) {
            throw new Error('Status endpoint must contain "{uploadId}" placeholder.');
        }

        if (!this.endpoints.cancel.includes('{uploadId}')) {
            throw new Error('Cancel endpoint must contain "{uploadId}" placeholder.');
        }
    }

    on<K extends keyof ChunkUploaderEventMap>(event: K, callback: (data: ChunkUploaderEventMap[K]) => void): Unsubscribe {
        if (!this.listeners.has(event)) {
            this.listeners.set(event, new Set());
        }

        this.listeners.get(event)!.add(callback);

        if (event === 'complete' && this.lastComplete) {
            const sticky = this.lastComplete;
            queueMicrotask(() => (callback as (d: UploadResult) => void)(sticky));
        } else if (event === 'error' && this.lastError) {
            const sticky = this.lastError;
            queueMicrotask(() => (callback as (d: UploadError) => void)(sticky));
        }

        return () => {
            this.listeners.get(event)?.delete(callback);
        };
    }

    private emit<K extends keyof ChunkUploaderEventMap>(event: K, data: ChunkUploaderEventMap[K]): void {
        if (event === 'complete') {
            this.lastComplete = data as UploadResult;
            this.lastError = null;
        } else if (event === 'error') {
            this.lastError = data as UploadError;
        }

        this.listeners.get(event)?.forEach((cb) => cb(data));
    }

    private emitStateChange(): void {
        this.emit('stateChange', this.getState());
    }

    getState(): ChunkUploaderState {
        return {
            progress: this.progress,
            isUploading: this.isUploading,
            isPaused: this.isPaused,
            isComplete: this.isComplete,
            error: this.error,
            uploadId: this.uploadId,
            uploadedChunks: this.uploadedChunks,
            totalChunks: this.totalChunks,
            currentFile: this.currentFile,
        };
    }

    private getHeaders(): Record<string, string> {
        return buildHeaders(this.headers);
    }

    private async fetchJson<T>(url: string, init: RequestInit): Promise<T> {
        const response = await fetch(url, {
            ...init,
            credentials: this.withCredentials ? 'include' : 'same-origin',
            signal: this.abortController?.signal,
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

    private async initiateUpload(file: File, metadata?: Record<string, unknown>): Promise<InitiateResponse> {
        const body = JSON.stringify({
            file_name: file.name,
            file_size: file.size,
            mime_type: file.type || null,
            metadata: metadata ?? null,
            ...(this.context ? { context: this.context } : {}),
        });

        return this.fetchJson<InitiateResponse>(this.endpoints.initiate, {
            method: 'POST',
            headers: { ...this.getHeaders(), 'Content-Type': 'application/json' },
            body,
        });
    }

    private async uploadSingleChunk(
        id: string,
        chunkIndex: number,
        chunkBlob: Blob,
        total: number,
        retriesLeft: number,
    ): Promise<ChunkUploadResponse> {
        const checksum = this.checksumEnabled ? await computeChecksum(chunkBlob) : null;

        const formData = new FormData();
        formData.append('chunk', chunkBlob, `chunk_${chunkIndex}`);
        formData.append('chunk_index', String(chunkIndex));

        if (checksum) {
            formData.append('checksum', checksum);
        }

        const url = this.endpoints.upload.replace('{uploadId}', id);

        try {
            const result = await this.fetchJson<ChunkUploadResponse>(url, {
                method: 'POST',
                headers: this.getHeaders(),
                body: formData,
            });

            this.uploadedChunks = result.uploaded_count;
            this.progress = result.progress;
            this.emitStateChange();

            this.emit('progress', {
                uploadId: id,
                loaded: result.uploaded_count,
                total: result.total_chunks,
                percentage: result.progress,
                chunkIndex,
                totalChunks: total,
            });

            this.emit('chunkUploaded', {
                index: chunkIndex,
                size: chunkBlob.size,
                checksum,
                uploadId: id,
            });

            return result;
        } catch (err) {
            if (this.autoRetry && retriesLeft > 0) {
                const delay = Math.pow(2, this.maxRetries - retriesLeft) * 1000;
                await new Promise((resolve) => setTimeout(resolve, delay));

                return this.uploadSingleChunk(id, chunkIndex, chunkBlob, total, retriesLeft - 1);
            }

            throw err;
        }
    }

    private async uploadChunks(file: File, id: string, chunkSize: number, total: number): Promise<void> {
        const chunks = this.pendingChunks.length > 0 ? [...this.pendingChunks] : Array.from({ length: total }, (_, i) => i);

        let index = 0;
        let completed = false;

        const next = async (): Promise<void> => {
            while (index < chunks.length) {
                if (completed || this.isPaused || this.abortController?.signal.aborted) {
                    return;
                }

                const chunkIndex = chunks[index++];
                const start = chunkIndex * chunkSize;
                const end = Math.min(start + chunkSize, file.size);
                const chunkBlob = file.slice(start, end);

                const result = await this.uploadSingleChunk(id, chunkIndex, chunkBlob, total, this.maxRetries);

                this.pendingChunks = this.pendingChunks.filter((i) => i !== chunkIndex);

                if (result.is_complete) {
                    completed = true;
                    return;
                }
            }
        };

        const workers = Array.from({ length: Math.min(this.maxConcurrent, chunks.length) }, () => next());
        await Promise.all(workers);
    }

    private async fetchStatus(id: string): Promise<StatusResponse> {
        const url = this.endpoints.status.replace('{uploadId}', id);

        return this.fetchJson<StatusResponse>(url, {
            method: 'GET',
            headers: this.getHeaders(),
        });
    }

    async upload(file: File, metadata?: Record<string, unknown>): Promise<UploadResult> {
        if (this.isUploading && !this.isPaused) {
            throw new Error('Upload already in progress. Cancel or wait for completion before starting a new upload.');
        }

        this.abortController?.abort();

        this.lastFile = file;
        this.lastMetadata = metadata;
        this.currentFile = file;
        this.isUploading = true;
        this.isPaused = false;
        this.isComplete = false;
        this.error = null;
        this.progress = 0;
        this.uploadedChunks = 0;
        this.lastComplete = null;
        this.lastError = null;
        this.abortController = new AbortController();
        this.emitStateChange();

        try {
            if (this.uploadId) {
                const status = await this.fetchStatus(this.uploadId);
                const alreadyUploaded = new Set(status.uploaded_chunks);
                this.serverChunkSize = status.chunk_size;
                this.totalChunks = status.total_chunks;
                this.uploadedChunks = status.uploaded_count;
                this.pendingChunks = Array.from({ length: status.total_chunks }, (_, i) => i).filter(
                    (i) => !alreadyUploaded.has(i),
                );
            } else {
                const initResult = await this.initiateUpload(file, metadata);
                this.uploadId = initResult.upload_id;
                this.serverChunkSize = initResult.chunk_size;
                this.totalChunks = initResult.total_chunks;
                this.pendingChunks = Array.from({ length: initResult.total_chunks }, (_, i) => i);
            }

            this.emitStateChange();

            const chunkSize = this.chunkSizeOverride ?? this.serverChunkSize ?? 1024 * 1024;

            await this.uploadChunks(file, this.uploadId!, chunkSize, this.totalChunks);

            if (!this.isPaused && !this.abortController.signal.aborted) {
                const result: UploadResult = {
                    uploadId: this.uploadId!,
                    fileName: file.name,
                    fileSize: file.size,
                    totalChunks: this.totalChunks,
                };

                this.isComplete = true;
                this.progress = 100;
                this.uploadId = null;
                this.pendingChunks = [];
                this.lastFile = null;
                this.lastMetadata = undefined;
                this.emitStateChange();

                this.emit('complete', result);

                return result;
            }

            return {
                uploadId: this.uploadId!,
                fileName: file.name,
                fileSize: file.size,
                totalChunks: this.totalChunks,
            };
        } catch (err) {
            const message = err instanceof Error ? err.message : 'Upload failed';
            this.error = message;
            this.emitStateChange();

            const uploadError: UploadError = {
                uploadId: this.uploadId,
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

    pause(): void {
        this.isPaused = true;
        this.emitStateChange();
    }

    resume(): boolean {
        if (!this.isPaused || !this.lastFile || !this.uploadId) {
            return false;
        }

        this.isPaused = false;
        this.emitStateChange();
        this.upload(this.lastFile, this.lastMetadata).catch(() => {
            // Already surfaced via the 'error' event listener.
        });

        return true;
    }

    cancel(): void {
        const abandonedId = this.uploadId;

        this.abortController?.abort();
        this.isPaused = false;
        this.isUploading = false;
        this.uploadId = null;
        this.pendingChunks = [];
        this.progress = 0;
        this.uploadedChunks = 0;
        this.totalChunks = 0;
        this.currentFile = null;
        this.error = null;
        this.lastComplete = null;
        this.lastError = null;
        this.emitStateChange();

        if (abandonedId) {
            this.cancelOnServer(abandonedId).catch(() => {
                // Best-effort: the server cleanup is also driven by the expiration sweep.
            });
        }
    }

    private async cancelOnServer(id: string): Promise<void> {
        const url = this.endpoints.cancel.replace('{uploadId}', id);

        await fetch(url, {
            method: 'DELETE',
            credentials: this.withCredentials ? 'include' : 'same-origin',
            headers: this.getHeaders(),
        });
    }

    retry(): boolean {
        if (!this.lastFile || (this.isUploading && !this.isPaused)) {
            return false;
        }

        this.error = null;
        this.emitStateChange();
        this.upload(this.lastFile, this.lastMetadata).catch(() => {
            // Already surfaced via the 'error' event listener.
        });

        return true;
    }

    destroy(): void {
        this.abortController?.abort();
        this.listeners.clear();
    }
}
