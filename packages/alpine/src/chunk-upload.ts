import { ChunkUploader } from '@netipar/chunky-core';
import type { ChunkUploadOptions, UploadResult } from '@netipar/chunky-core';

/**
 * Minimal contract for the bits of Alpine.js the factory needs. Lets
 * the package compile without Alpine itself as a peer/dev dep, while
 * still keeping a type-safe entry point. Alpine 3's actual `Alpine`
 * object satisfies this interface (and many more methods we don't
 * use here).
 */
export interface AlpineLike {
    data<T>(name: string, factory: (...args: unknown[]) => T): void;
}

/**
 * Minimal type for the `this` context Alpine binds to component
 * methods. Alpine adds `$dispatch` (and `$watch`, `$el`, …) as
 * implicit fields; we only call `$dispatch`, so that's the only one
 * we type here.
 */
export interface AlpineContext {
    $dispatch(event: string, detail?: unknown): void;
}

export interface AlpineChunkUploadData {
    progress: number;
    isUploading: boolean;
    isPaused: boolean;
    isComplete: boolean;
    error: string | null;
    uploadId: string | null;
    uploadedChunks: number;
    totalChunks: number;
    currentFile: File | null;

    _uploader: ChunkUploader | null;

    init(): void;
    destroy(): void;
    upload(file: File, metadata?: Record<string, unknown>): Promise<UploadResult>;
    handleFileInput(event: Event): void;
    pause(): void;
    resume(): boolean;
    cancel(): void;
    retry(): boolean;
}

export function registerChunkUpload(Alpine: AlpineLike): void {
    Alpine.data('chunkUpload', ((options: ChunkUploadOptions = {}): AlpineChunkUploadData => ({
        progress: 0,
        isUploading: false,
        isPaused: false,
        isComplete: false,
        error: null,
        uploadId: null,
        uploadedChunks: 0,
        totalChunks: 0,
        currentFile: null,

        _uploader: null,

        init() {
            this._uploader = new ChunkUploader(options);

            this._uploader.on('stateChange', (state) => {
                this.progress = state.progress;
                this.isUploading = state.isUploading;
                this.isPaused = state.isPaused;
                this.isComplete = state.isComplete;
                this.error = state.error;
                this.uploadId = state.uploadId;
                this.uploadedChunks = state.uploadedChunks;
                this.totalChunks = state.totalChunks;
                this.currentFile = state.currentFile;
            });

            this._uploader.on('progress', (event) => {
                (this as unknown as AlpineContext).$dispatch('chunky:progress', event);
            });

            this._uploader.on('chunkUploaded', (chunk) => {
                (this as unknown as AlpineContext).$dispatch('chunky:chunk-uploaded', chunk);
            });

            this._uploader.on('complete', (result) => {
                (this as unknown as AlpineContext).$dispatch('chunky:complete', result);
            });

            this._uploader.on('error', (error) => {
                (this as unknown as AlpineContext).$dispatch('chunky:error', error);
            });
        },

        destroy() {
            this._uploader?.destroy();
        },

        async upload(file: File, metadata?: Record<string, unknown>): Promise<UploadResult> {
            return this._uploader!.upload(file, metadata);
        },

        handleFileInput(event: Event) {
            const input = event.target as HTMLInputElement;
            const file = input?.files?.[0];

            if (file) {
                this.upload(file);
            }
        },

        pause() {
            this._uploader!.pause();
        },

        resume() {
            return this._uploader!.resume();
        },

        cancel() {
            this._uploader!.cancel();
        },

        retry() {
            return this._uploader!.retry();
        },
    })) as (...args: unknown[]) => AlpineChunkUploadData);
}
