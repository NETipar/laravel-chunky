import { useState, useRef, useEffect, useCallback, useMemo } from 'react';
import { BatchUploader } from '@netipar/chunky-core';
import type {
    BatchProgressEvent,
    BatchResult,
    BatchUploadOptions,
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
    cancel: () => void;
    pause: () => void;
    resume: () => void;

    onProgress: (callback: (event: BatchProgressEvent) => void) => Unsubscribe;
    onFileComplete: (callback: (result: UploadResult) => void) => Unsubscribe;
    onFileError: (callback: (error: UploadError) => void) => Unsubscribe;
    onComplete: (callback: (result: BatchResult) => void) => Unsubscribe;
    onError: (callback: (error: UploadError) => void) => Unsubscribe;
}

export function useBatchUpload(options: BatchUploadOptions = {}): BatchUploadReturn {
    const optionsKey = useMemo(() => JSON.stringify(options), [options]);
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
        const uploader = new BatchUploader(options);
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
    }, [optionsKey]);

    const upload = useCallback(
        (files: File[], metadata?: Record<string, unknown>) => uploaderRef.current!.upload(files, metadata),
        [],
    );

    const cancel = useCallback(() => uploaderRef.current!.cancel(), []);
    const pause = useCallback(() => uploaderRef.current!.pause(), []);
    const resume = useCallback(() => uploaderRef.current!.resume(), []);

    const onProgress = useCallback(
        (cb: (event: BatchProgressEvent) => void) => uploaderRef.current!.on('progress', cb),
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
        cancel,
        pause,
        resume,

        onProgress,
        onFileComplete,
        onFileError,
        onComplete,
        onError,
    };
}
