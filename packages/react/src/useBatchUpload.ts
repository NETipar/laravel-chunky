import { useState, useRef, useEffect, useCallback } from 'react';
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
    /**
     * Queue a batch for upload after the current one finishes. If no
     * batch is in flight, behaves like `upload()`. The returned
     * promise rejects if `cancel()` / `destroy()` is invoked before
     * the queued batch starts.
     */
    enqueue: (files: File[], metadata?: Record<string, unknown>) => Promise<BatchResult>;
    cancel: () => void;
    pause: () => void;
    resume: () => void;
    /**
     * Tear down the uploader manually. The hook's useEffect cleanup
     * already does this on unmount, but having it on the return value
     * lets components that hold the uploader in a ref or context cancel
     * it deterministically (e.g. before navigating away).
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
    // Snapshot the options once on mount via a ref. The previous
    // implementation depended on `JSON.stringify(options)` to detect
    // changes, which threw on circular references / Headers /
    // functions and silently lost data on Date / Map values. Treat
    // options as a mount-time-only config; callers that need to
    // change them at runtime should remount the component or use the
    // imperative core BatchUploader directly.
    const optionsRef = useRef(options);
    const uploaderRef = useRef<BatchUploader | null>(null);

    const [batchId, setBatchId] = useState<string | null>(null);
    const [totalFiles, setTotalFiles] = useState(0);
    const [completedFiles, setCompletedFiles] = useState(0);
    const [failedFiles, setFailedFiles] = useState(0);
    const [progress, setProgress] = useState(0);
    const [isUploading, setIsUploading] = useState(false);
    const [isComplete, setIsComplete] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [currentFileName, setCurrentFileName] = useState<string | null>(null);

    useEffect(() => {
        const uploader = new BatchUploader(optionsRef.current);
        uploaderRef.current = uploader;

        const unsub = uploader.on('stateChange', (state) => {
            setBatchId(state.batchId);
            setTotalFiles(state.totalFiles);
            setCompletedFiles(state.completedFiles);
            setFailedFiles(state.failedFiles);
            setProgress(state.progress);
            setIsUploading(state.isUploading);
            setIsComplete(state.isComplete);
            setError(state.error);
            setCurrentFileName(state.currentFileName);
        });

        return () => {
            unsub();
            uploader.destroy();
        };
        // Mount-only: the BatchUploader is constructed once with the
        // initial options. See optionsRef snapshot above for the
        // rationale.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const upload = useCallback(
        (files: File[], metadata?: Record<string, unknown>) => uploaderRef.current!.upload(files, metadata),
        [],
    );

    const enqueue = useCallback(
        (files: File[], metadata?: Record<string, unknown>) => uploaderRef.current!.enqueue(files, metadata),
        [],
    );

    const cancel = useCallback(() => uploaderRef.current!.cancel(), []);
    const pause = useCallback(() => uploaderRef.current!.pause(), []);
    const resume = useCallback(() => uploaderRef.current!.resume(), []);
    const destroy = useCallback(() => uploaderRef.current!.destroy(), []);

    const onProgress = useCallback(
        (cb: (event: BatchProgressEvent) => void) => uploaderRef.current!.on('progress', cb),
        [],
    );

    const onFileProgress = useCallback(
        (cb: (event: FileProgressEvent) => void) => uploaderRef.current!.on('fileProgress', cb),
        [],
    );

    const onFileComplete = useCallback(
        (cb: (result: UploadResult) => void) => uploaderRef.current!.on('fileComplete', cb),
        [],
    );

    const onFileError = useCallback(
        (cb: (error: UploadError) => void) => uploaderRef.current!.on('fileError', cb),
        [],
    );

    const onComplete = useCallback(
        (cb: (result: BatchResult) => void) => uploaderRef.current!.on('complete', cb),
        [],
    );

    const onError = useCallback(
        (cb: (error: UploadError) => void) => uploaderRef.current!.on('error', cb),
        [],
    );

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

        upload,
        enqueue,
        cancel,
        pause,
        resume,
        destroy,

        onProgress,
        onFileProgress,
        onFileComplete,
        onFileError,
        onComplete,
        onError,
    };
}
