import type { DefaultsScope } from './config';
import type { ChunkUploadOptions, ChunkUploaderEventMap, ChunkUploaderState, Unsubscribe, UploadResult } from './types';
export declare class ChunkUploader {
    /** @deprecated Read via `getState().progress`. The public field will become private in v1.0. */
    progress: number;
    /** @deprecated Read via `getState().isUploading`. The public field will become private in v1.0. */
    isUploading: boolean;
    /** @deprecated Read via `getState().isPaused`. The public field will become private in v1.0. */
    isPaused: boolean;
    /** @deprecated Read via `getState().isComplete`. The public field will become private in v1.0. */
    isComplete: boolean;
    /** @deprecated Read via `getState().error`. The public field will become private in v1.0. */
    error: string | null;
    /** @deprecated Read via `getState().uploadId`. The public field will become private in v1.0. */
    uploadId: string | null;
    /** @deprecated Read via `getState().uploadedChunks`. The public field will become private in v1.0. */
    uploadedChunks: number;
    /** @deprecated Read via `getState().totalChunks`. The public field will become private in v1.0. */
    totalChunks: number;
    /** @deprecated Read via `getState().currentFile`. The public field will become private in v1.0. */
    currentFile: File | null;
    private readonly maxConcurrent;
    private readonly autoRetry;
    private readonly maxRetries;
    /**
     * HTTP statuses that are inherently non-retryable: client errors that
     * won't change on retry. Auth (401/403), not found (404, 410),
     * payload-too-large (413), unsupported media (415), and validation
     * errors (422). The default retry callback short-circuits on these.
     */
    private static readonly FATAL_STATUSES;
    private readonly headers;
    private readonly withCredentials;
    private readonly context?;
    private readonly checksumEnabled;
    private readonly chunkSizeOverride?;
    private readonly endpoints;
    private abortController;
    private pendingChunks;
    private serverChunkSize;
    private lastFile;
    private lastMetadata?;
    private listeners;
    private lastComplete;
    private lastError;
    constructor(options?: ChunkUploadOptions, scope?: DefaultsScope);
    private validateEndpoints;
    on<K extends keyof ChunkUploaderEventMap>(event: K, callback: (data: ChunkUploaderEventMap[K]) => void): Unsubscribe;
    private emit;
    private emitStateChange;
    getState(): ChunkUploaderState;
    private getHeaders;
    private fetchJson;
    private initiateUpload;
    private uploadSingleChunk;
    private shouldRetry;
    private uploadChunks;
    private fetchStatus;
    upload(file: File, metadata?: Record<string, unknown>): Promise<UploadResult>;
    pause(): void;
    resume(): boolean;
    cancel(): void;
    private cancelOnServer;
    retry(): boolean;
    destroy(): void;
}
