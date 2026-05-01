import { BatchUploader } from '@netipar/chunky-core';
import type { BatchUploadOptions, BatchResult } from '@netipar/chunky-core';

export interface AlpineBatchUploadData {
    batchId: string | null;
    totalFiles: number;
    completedFiles: number;
    failedFiles: number;
    progress: number;
    isUploading: boolean;
    isComplete: boolean;
    error: string | null;
    currentFileName: string | null;

    _uploader: BatchUploader | null;

    init(): void;
    destroy(): void;
    upload(files: File[], metadata?: Record<string, unknown>): Promise<BatchResult>;
    handleFileInput(event: Event): void;
    cancel(): void;
    pause(): void;
    resume(): void;
}

export function registerBatchUpload(Alpine: any): void {
    Alpine.data('batchUpload', (options: BatchUploadOptions = {}): AlpineBatchUploadData => ({
        batchId: null,
        totalFiles: 0,
        completedFiles: 0,
        failedFiles: 0,
        progress: 0,
        isUploading: false,
        isComplete: false,
        error: null,
        currentFileName: null,

        _uploader: null,

        init() {
            this._uploader = new BatchUploader(options);

            this._uploader.on('stateChange', (state) => {
                this.batchId = state.batchId;
                this.totalFiles = state.totalFiles;
                this.completedFiles = state.completedFiles;
                this.failedFiles = state.failedFiles;
                this.progress = state.progress;
                this.isUploading = state.isUploading;
                this.isComplete = state.isComplete;
                this.error = state.error;
                this.currentFileName = state.currentFileName;
            });

            this._uploader.on('progress', (event) => {
                (this as any).$dispatch('chunky:batch-progress', event);
            });

            this._uploader.on('fileProgress', (event) => {
                (this as any).$dispatch('chunky:batch-file-progress', event);
            });

            this._uploader.on('fileComplete', (result) => {
                (this as any).$dispatch('chunky:batch-file-complete', result);
            });

            this._uploader.on('fileError', (error) => {
                (this as any).$dispatch('chunky:batch-file-error', error);
            });

            this._uploader.on('complete', (result) => {
                (this as any).$dispatch('chunky:batch-complete', result);
            });

            this._uploader.on('error', (error) => {
                (this as any).$dispatch('chunky:batch-error', error);
            });
        },

        destroy() {
            this._uploader?.destroy();
        },

        async upload(files: File[], metadata?: Record<string, unknown>): Promise<BatchResult> {
            return this._uploader!.upload(files, metadata);
        },

        handleFileInput(event: Event) {
            const input = event.target as HTMLInputElement;
            const files = input?.files;

            if (files && files.length > 0) {
                this.upload(Array.from(files));
            }
        },

        cancel() {
            this._uploader!.cancel();
        },

        pause() {
            this._uploader!.pause();
        },

        resume() {
            this._uploader!.resume();
        },
    }));
}
