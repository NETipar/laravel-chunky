import { ChunkUploader } from '@netipar/chunky-core';
import type { UploadResult } from '@netipar/chunky-core';
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
export declare function registerChunkUpload(Alpine: any): void;
