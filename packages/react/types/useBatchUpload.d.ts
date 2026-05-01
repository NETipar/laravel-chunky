import type { BatchProgressEvent, BatchResult, BatchUploadOptions, FileProgressEvent, Unsubscribe, UploadError, UploadResult } from '@netipar/chunky-core';
export interface BatchUploadReturn {
    batchId: string | null;
    totalFiles: number;
    completedFiles: number;
    failedFiles: number;
    progress: number;
    isUploading: boolean;
    isComplete: boolean;
    error: string | null;
    currentFileName: string | null;
    upload: (files: File[], metadata?: Record<string, unknown>) => Promise<BatchResult>;
    enqueue: (files: File[], metadata?: Record<string, unknown>) => Promise<BatchResult>;
    cancel: () => void;
    pause: () => void;
    resume: () => void;
    onProgress: (callback: (event: BatchProgressEvent) => void) => Unsubscribe;
    onFileProgress: (callback: (event: FileProgressEvent) => void) => Unsubscribe;
    onFileComplete: (callback: (result: UploadResult) => void) => Unsubscribe;
    onFileError: (callback: (error: UploadError) => void) => Unsubscribe;
    onComplete: (callback: (result: BatchResult) => void) => Unsubscribe;
    onError: (callback: (error: UploadError) => void) => Unsubscribe;
}
export declare function useBatchUpload(options?: BatchUploadOptions): BatchUploadReturn;
