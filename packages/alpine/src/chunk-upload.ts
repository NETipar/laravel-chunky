import { ChunkUploader } from '@netipar/chunky-core';
import type { ChunkUploadOptions, UploadResult } from '@netipar/chunky-core';

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
    resume(): void;
    cancel(): void;
    retry(): void;
}

export function registerChunkUpload(Alpine: any): void {
    Alpine.data('chunkUpload', (options: ChunkUploadOptions = {}): AlpineChunkUploadData => ({
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

            this._uploader.on('complete', (result) => {
                (this as any).$dispatch('chunky:complete', result);
            });

            this._uploader.on('error', (error) => {
                (this as any).$dispatch('chunky:error', error);
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
            this._uploader!.resume();
        },

        cancel() {
            this._uploader!.cancel();
        },

        retry() {
            this._uploader!.retry();
        },
    }));
}
