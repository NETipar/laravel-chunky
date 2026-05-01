import { BatchUploader } from '@netipar/chunky-core';
import type { BatchResult } from '@netipar/chunky-core';
import type { AlpineLike } from './chunk-upload';
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
export declare function registerBatchUpload(Alpine: AlpineLike): void;
