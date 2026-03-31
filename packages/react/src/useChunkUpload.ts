import { useState, useRef, useEffect, useCallback } from 'react';
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
    resume: () => boolean;
    cancel: () => void;
    retry: () => boolean;

    onProgress: (callback: (event: ProgressEvent) => void) => Unsubscribe;
    onChunkUploaded: (callback: (chunk: ChunkInfo) => void) => Unsubscribe;
    onComplete: (callback: (result: UploadResult) => void) => Unsubscribe;
    onError: (callback: (error: UploadError) => void) => Unsubscribe;
}

export function useChunkUpload(options: ChunkUploadOptions = {}): ChunkUploadReturn {
    const uploaderRef = useRef<ChunkUploader | null>(null);

    if (!uploaderRef.current) {
        uploaderRef.current = new ChunkUploader(options);
    }

    const [progress, setProgress] = useState(0);
    const [isUploading, setIsUploading] = useState(false);
    const [isPaused, setIsPaused] = useState(false);
    const [isComplete, setIsComplete] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [uploadId, setUploadId] = useState<string | null>(null);
    const [uploadedChunks, setUploadedChunks] = useState(0);
    const [totalChunks, setTotalChunks] = useState(0);
    const [currentFile, setCurrentFile] = useState<File | null>(null);

    useEffect(() => {
        const uploader = uploaderRef.current!;

        const unsub = uploader.on('stateChange', (state) => {
            setProgress(state.progress);
            setIsUploading(state.isUploading);
            setIsPaused(state.isPaused);
            setIsComplete(state.isComplete);
            setError(state.error);
            setUploadId(state.uploadId);
            setUploadedChunks(state.uploadedChunks);
            setTotalChunks(state.totalChunks);
            setCurrentFile(state.currentFile);
        });

        return () => {
            unsub();
            uploader.destroy();
        };
    }, []);

    const upload = useCallback(
        (file: File, metadata?: Record<string, unknown>) => uploaderRef.current!.upload(file, metadata),
        [],
    );

    const pause = useCallback(() => uploaderRef.current!.pause(), []);
    const resume = useCallback(() => uploaderRef.current!.resume(), []);
    const cancel = useCallback(() => uploaderRef.current!.cancel(), []);
    const retry = useCallback(() => uploaderRef.current!.retry(), []);

    const onProgress = useCallback(
        (cb: (event: ProgressEvent) => void) => uploaderRef.current!.on('progress', cb),
        [],
    );

    const onChunkUploaded = useCallback(
        (cb: (chunk: ChunkInfo) => void) => uploaderRef.current!.on('chunkUploaded', cb),
        [],
    );

    const onComplete = useCallback(
        (cb: (result: UploadResult) => void) => uploaderRef.current!.on('complete', cb),
        [],
    );

    const onError = useCallback(
        (cb: (error: UploadError) => void) => uploaderRef.current!.on('error', cb),
        [],
    );

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

        upload,
        pause,
        resume,
        cancel,
        retry,

        onProgress,
        onChunkUploaded,
        onComplete,
        onError,
    };
}
