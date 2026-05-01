import { type Ref } from 'vue';
import type { ChunkInfo, ChunkUploadOptions, ProgressEvent, Unsubscribe, UploadError, UploadResult } from '@netipar/chunky-core';
export interface ChunkUploadReturn {
    progress: Ref<number>;
    isUploading: Ref<boolean>;
    isPaused: Ref<boolean>;
    isComplete: Ref<boolean>;
    error: Ref<string | null>;
    uploadId: Ref<string | null>;
    uploadedChunks: Ref<number>;
    totalChunks: Ref<number>;
    currentFile: Ref<File | null>;
    upload: (file: File, metadata?: Record<string, unknown>) => Promise<UploadResult>;
    pause: () => void;
    resume: () => boolean;
    cancel: () => void;
    retry: () => boolean;
    /**
     * Tear down the uploader manually. Required when the composable is used
     * outside a component scope (e.g. in a Pinia store) where the automatic
     * `onScopeDispose` cleanup does not fire.
     */
    destroy: () => void;
    onProgress: (callback: (event: ProgressEvent) => void) => Unsubscribe;
    onChunkUploaded: (callback: (chunk: ChunkInfo) => void) => Unsubscribe;
    onComplete: (callback: (result: UploadResult) => void) => Unsubscribe;
    onError: (callback: (error: UploadError) => void) => Unsubscribe;
}
export declare function useChunkUpload(options?: ChunkUploadOptions): ChunkUploadReturn;
