import type { ChunkInfo, ChunkUploadOptions, ProgressEvent, Unsubscribe, UploadError, UploadResult } from '@netipar/chunky-core';
export interface ChunkUploadReturn {
    progress: number;
    isUploading: boolean;
    isPaused: boolean;
    isComplete: boolean;
    error: string | null;
    uploadId: string | null;
    uploadedChunks: number;
    totalChunks: number;
    currentFile: File | null;
    upload: (file: File, metadata?: Record<string, unknown>) => Promise<UploadResult>;
    pause: () => void;
    resume: () => void;
    cancel: () => void;
    retry: () => void;
    onProgress: (callback: (event: ProgressEvent) => void) => Unsubscribe;
    onChunkUploaded: (callback: (chunk: ChunkInfo) => void) => Unsubscribe;
    onComplete: (callback: (result: UploadResult) => void) => Unsubscribe;
    onError: (callback: (error: UploadError) => void) => Unsubscribe;
}
export declare function useChunkUpload(options?: ChunkUploadOptions): ChunkUploadReturn;
