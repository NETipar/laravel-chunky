import { ref, getCurrentScope, onScopeDispose, type Ref } from 'vue';
import { BatchUploader } from '@netipar/chunky-core';
import type {
    BatchProgressEvent,
    BatchResult,
    BatchUploadOptions,
    FileProgressEvent,
    Unsubscribe,
    UploadError,
    UploadResult,
} from '@netipar/chunky-core';

export interface BatchUploadReturn {
    batchId: Ref<string | null>;
    totalFiles: Ref<number>;
    completedFiles: Ref<number>;
    failedFiles: Ref<number>;
    progress: Ref<number>;
    isUploading: Ref<boolean>;
    isComplete: Ref<boolean>;
    error: Ref<string | null>;
    currentFileName: Ref<string | null>;

    upload: (files: File[], metadata?: Record<string, unknown>) => Promise<BatchResult>;
    enqueue: (files: File[], metadata?: Record<string, unknown>) => Promise<BatchResult>;
    cancel: () => void;
    pause: () => void;
    resume: () => void;
    /**
     * Tear down the uploader manually. Required when the composable is used
     * outside a component scope (e.g. in a Pinia store) where the automatic
     * `onScopeDispose` cleanup does not fire.
     */
    destroy: () => void;

    onProgress: (callback: (event: BatchProgressEvent) => void) => Unsubscribe;
    onFileProgress: (callback: (event: FileProgressEvent) => void) => Unsubscribe;
    onFileComplete: (callback: (result: UploadResult) => void) => Unsubscribe;
    onFileError: (callback: (error: UploadError) => void) => Unsubscribe;
    onComplete: (callback: (result: BatchResult) => void) => Unsubscribe;
    onError: (callback: (error: UploadError) => void) => Unsubscribe;
}

export function useBatchUpload(options: BatchUploadOptions = {}): BatchUploadReturn {
    const uploader = new BatchUploader(options);

    const batchId = ref<string | null>(null);
    const totalFiles = ref(0);
    const completedFiles = ref(0);
    const failedFiles = ref(0);
    const progress = ref(0);
    const isUploading = ref(false);
    const isComplete = ref(false);
    const error = ref<string | null>(null);
    const currentFileName = ref<string | null>(null);

    uploader.on('stateChange', (state) => {
        batchId.value = state.batchId;
        totalFiles.value = state.totalFiles;
        completedFiles.value = state.completedFiles;
        failedFiles.value = state.failedFiles;
        progress.value = state.progress;
        isUploading.value = state.isUploading;
        isComplete.value = state.isComplete;
        error.value = state.error;
        currentFileName.value = state.currentFileName;
    });

    // `getCurrentScope()` works in component setup() AND in Pinia stores,
    // effectScope blocks, etc. — anywhere with a reactive scope. The old
    // `getCurrentInstance()` check was component-only, so consumers using
    // the composable from a store leaked the uploader forever.
    if (getCurrentScope()) {
        onScopeDispose(() => uploader.destroy());
    }

    return {
        batchId,
        totalFiles,
        completedFiles,
        failedFiles,
        progress,
        isUploading,
        isComplete,
        error,
        currentFileName,

        upload: (files, metadata) => uploader.upload(files, metadata),
        enqueue: (files, metadata) => uploader.enqueue(files, metadata),
        cancel: () => uploader.cancel(),
        pause: () => uploader.pause(),
        resume: () => uploader.resume(),
        destroy: () => uploader.destroy(),

        onProgress: (cb) => uploader.on('progress', cb),
        onFileProgress: (cb) => uploader.on('fileProgress', cb),
        onFileComplete: (cb) => uploader.on('fileComplete', cb),
        onFileError: (cb) => uploader.on('fileError', cb),
        onComplete: (cb) => uploader.on('complete', cb),
        onError: (cb) => uploader.on('error', cb),
    };
}
