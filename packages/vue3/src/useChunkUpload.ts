import { ref, getCurrentScope, onScopeDispose, type Ref } from 'vue';
import { ChunkUploader } from '@netipar/chunky-core';
import type {
    ChunkInfo,
    ChunkUploadOptions,
    ProgressEvent,
    Unsubscribe,
    UploadError,
    UploadResult,
} from '@netipar/chunky-core';

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

export function useChunkUpload(options: ChunkUploadOptions = {}): ChunkUploadReturn {
    const uploader = new ChunkUploader(options);

    const progress = ref(0);
    const isUploading = ref(false);
    const isPaused = ref(false);
    const isComplete = ref(false);
    const error = ref<string | null>(null);
    const uploadId = ref<string | null>(null);
    const uploadedChunks = ref(0);
    const totalChunks = ref(0);
    const currentFile = ref<File | null>(null);

    uploader.on('stateChange', (state) => {
        progress.value = state.progress;
        isUploading.value = state.isUploading;
        isPaused.value = state.isPaused;
        isComplete.value = state.isComplete;
        error.value = state.error;
        uploadId.value = state.uploadId;
        uploadedChunks.value = state.uploadedChunks;
        totalChunks.value = state.totalChunks;
        currentFile.value = state.currentFile;
    });

    if (getCurrentScope()) {
        onScopeDispose(() => uploader.destroy());
    }

    return {
        progress,
        isUploading,
        isPaused,
        isComplete,
        error,
        uploadId,
        uploadedChunks,
        totalChunks,
        currentFile,

        upload: (file, metadata) => uploader.upload(file, metadata),
        pause: () => uploader.pause(),
        resume: () => uploader.resume(),
        cancel: () => uploader.cancel(),
        retry: () => uploader.retry(),
        destroy: () => uploader.destroy(),

        onProgress: (cb) => uploader.on('progress', cb),
        onChunkUploaded: (cb) => uploader.on('chunkUploaded', cb),
        onComplete: (cb) => uploader.on('complete', cb),
        onError: (cb) => uploader.on('error', cb),
    };
}
