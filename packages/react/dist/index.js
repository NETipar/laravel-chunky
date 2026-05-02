// src/useChunkUpload.ts
import { useState, useRef, useEffect, useCallback } from "react";
import { ChunkUploader } from "@netipar/chunky-core";
function useChunkUpload(options = {}) {
  const optionsRef = useRef(options);
  const uploaderRef = useRef(null);
  const [progress, setProgress] = useState(0);
  const [isUploading, setIsUploading] = useState(false);
  const [isPaused, setIsPaused] = useState(false);
  const [isComplete, setIsComplete] = useState(false);
  const [error, setError] = useState(null);
  const [uploadId, setUploadId] = useState(null);
  const [uploadedChunks, setUploadedChunks] = useState(0);
  const [totalChunks, setTotalChunks] = useState(0);
  const [currentFile, setCurrentFile] = useState(null);
  useEffect(() => {
    const uploader = new ChunkUploader(optionsRef.current);
    uploaderRef.current = uploader;
    const unsub = uploader.on("stateChange", (state) => {
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
    (file, metadata) => uploaderRef.current.upload(file, metadata),
    []
  );
  const pause = useCallback(() => uploaderRef.current.pause(), []);
  const resume = useCallback(() => uploaderRef.current.resume(), []);
  const cancel = useCallback(() => uploaderRef.current.cancel(), []);
  const retry = useCallback(() => uploaderRef.current.retry(), []);
  const destroy = useCallback(() => uploaderRef.current.destroy(), []);
  const onProgress = useCallback(
    (cb) => uploaderRef.current.on("progress", cb),
    []
  );
  const onChunkUploaded = useCallback(
    (cb) => uploaderRef.current.on("chunkUploaded", cb),
    []
  );
  const onComplete = useCallback(
    (cb) => uploaderRef.current.on("complete", cb),
    []
  );
  const onError = useCallback(
    (cb) => uploaderRef.current.on("error", cb),
    []
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
    destroy,
    onProgress,
    onChunkUploaded,
    onComplete,
    onError
  };
}

// src/useBatchUpload.ts
import { useState as useState2, useRef as useRef2, useEffect as useEffect2, useCallback as useCallback2 } from "react";
import { BatchUploader } from "@netipar/chunky-core";
function useBatchUpload(options = {}) {
  const optionsRef = useRef2(options);
  const uploaderRef = useRef2(null);
  const [batchId, setBatchId] = useState2(null);
  const [totalFiles, setTotalFiles] = useState2(0);
  const [completedFiles, setCompletedFiles] = useState2(0);
  const [failedFiles, setFailedFiles] = useState2(0);
  const [progress, setProgress] = useState2(0);
  const [isUploading, setIsUploading] = useState2(false);
  const [isComplete, setIsComplete] = useState2(false);
  const [error, setError] = useState2(null);
  const [currentFileName, setCurrentFileName] = useState2(null);
  useEffect2(() => {
    const uploader = new BatchUploader(optionsRef.current);
    uploaderRef.current = uploader;
    const unsub = uploader.on("stateChange", (state) => {
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
  }, []);
  const upload = useCallback2(
    (files, metadata) => uploaderRef.current.upload(files, metadata),
    []
  );
  const enqueue = useCallback2(
    (files, metadata) => uploaderRef.current.enqueue(files, metadata),
    []
  );
  const cancel = useCallback2(() => uploaderRef.current.cancel(), []);
  const pause = useCallback2(() => uploaderRef.current.pause(), []);
  const resume = useCallback2(() => uploaderRef.current.resume(), []);
  const destroy = useCallback2(() => uploaderRef.current.destroy(), []);
  const onProgress = useCallback2(
    (cb) => uploaderRef.current.on("progress", cb),
    []
  );
  const onFileProgress = useCallback2(
    (cb) => uploaderRef.current.on("fileProgress", cb),
    []
  );
  const onFileComplete = useCallback2(
    (cb) => uploaderRef.current.on("fileComplete", cb),
    []
  );
  const onFileError = useCallback2(
    (cb) => uploaderRef.current.on("fileError", cb),
    []
  );
  const onComplete = useCallback2(
    (cb) => uploaderRef.current.on("complete", cb),
    []
  );
  const onError = useCallback2(
    (cb) => uploaderRef.current.on("error", cb),
    []
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
    onError
  };
}

// src/useUpload.ts
function useUpload(options = {}) {
  const inner = useBatchUpload(options);
  const toArray = (input) => Array.isArray(input) ? input : [input];
  return {
    ...inner,
    upload: (input, metadata) => inner.upload(toArray(input), metadata),
    enqueue: (input, metadata) => inner.enqueue(toArray(input), metadata)
  };
}

// src/useBatchCompletion.ts
import { useEffect as useEffect3, useRef as useRef3, useState as useState3 } from "react";
import { watchBatchCompletion } from "@netipar/chunky-core";
function useBatchCompletion(batchId, options = {}) {
  const [isWaiting, setIsWaiting] = useState3(false);
  const [receivedVia, setReceivedVia] = useState3(null);
  const [result, setResult] = useState3(null);
  const optionsRef = useRef3(options);
  optionsRef.current = options;
  const cleanupRef = useRef3(null);
  const stop = () => {
    cleanupRef.current?.();
    cleanupRef.current = null;
    setIsWaiting(false);
  };
  const cancelRef = useRef3(stop);
  cancelRef.current = stop;
  useEffect3(() => {
    if (!batchId) {
      stop();
      return;
    }
    const debounceMs = optionsRef.current.debounceMs ?? 50;
    const start = () => {
      setResult(null);
      setReceivedVia(null);
      setIsWaiting(true);
      const handleResult = (kind, data) => {
        setResult(data);
        setReceivedVia(data.source);
        setIsWaiting(false);
        cleanupRef.current = null;
        if (kind === "partial") {
          optionsRef.current.onPartiallyCompleted?.(data);
        } else {
          optionsRef.current.onComplete?.(data);
        }
      };
      cleanupRef.current = watchBatchCompletion({
        batchId,
        statusEndpoint: optionsRef.current.statusEndpoint,
        echo: optionsRef.current.echo,
        channelPrefix: optionsRef.current.channelPrefix,
        pollStartDelayMs: optionsRef.current.pollStartDelayMs,
        pollIntervalMs: optionsRef.current.pollIntervalMs,
        pollMaxIntervalMs: optionsRef.current.pollMaxIntervalMs,
        pollBackoffFactor: optionsRef.current.pollBackoffFactor,
        timeoutMs: optionsRef.current.timeoutMs,
        headers: optionsRef.current.headers,
        withCredentials: optionsRef.current.withCredentials,
        onSubscribed: () => optionsRef.current.onSubscribed?.(),
        onSubscribeError: (err) => optionsRef.current.onSubscribeError?.(err),
        onComplete: (data) => handleResult("complete", data),
        onPartiallyCompleted: (data) => handleResult("partial", data),
        onTimeout: () => {
          setIsWaiting(false);
          cleanupRef.current = null;
          optionsRef.current.onTimeout?.();
        },
        onError: (err, isFatal) => {
          if (isFatal) {
            setIsWaiting(false);
            cleanupRef.current = null;
          }
          optionsRef.current.onError?.(err, isFatal);
        }
      });
    };
    if (debounceMs <= 0) {
      start();
      return () => stop();
    }
    const debounceTimer = setTimeout(start, debounceMs);
    return () => {
      clearTimeout(debounceTimer);
      stop();
    };
  }, [batchId]);
  return {
    isWaiting,
    receivedVia,
    result,
    cancel: () => cancelRef.current()
  };
}

// src/useChunkyEcho.ts
import { useEffect as useEffect4, useRef as useRef4 } from "react";
import { listenForUser, listenForUploadComplete, listenForBatchComplete } from "@netipar/chunky-core";
function useCallbackRef(value) {
  const ref = useRef4(value);
  ref.current = value;
  return ref;
}
function useUserEcho(echo, userId, callbacks, channelPrefix) {
  const cbRef = useCallbackRef(callbacks);
  useEffect4(() => {
    if (!userId) {
      return;
    }
    return listenForUser(
      echo,
      userId,
      {
        onUploadComplete: (data) => cbRef.current.onUploadComplete?.(data),
        onBatchComplete: (data) => cbRef.current.onBatchComplete?.(data),
        onBatchPartiallyCompleted: (data) => cbRef.current.onBatchPartiallyCompleted?.(data)
      },
      channelPrefix
    );
  }, [echo, userId, channelPrefix]);
}
function useUploadEcho(echo, uploadId, callback, channelPrefix) {
  const cbRef = useCallbackRef(callback);
  useEffect4(() => {
    if (!uploadId) {
      return;
    }
    return listenForUploadComplete(echo, uploadId, (data) => cbRef.current(data), channelPrefix);
  }, [echo, uploadId, channelPrefix]);
}
function useBatchEcho(echo, batchId, callbacks, channelPrefix) {
  const cbRef = useCallbackRef(callbacks);
  useEffect4(() => {
    if (!batchId) {
      return;
    }
    return listenForBatchComplete(
      echo,
      batchId,
      {
        onComplete: (data) => cbRef.current.onComplete?.(data),
        onPartiallyCompleted: (data) => cbRef.current.onPartiallyCompleted?.(data)
      },
      channelPrefix
    );
  }, [echo, batchId, channelPrefix]);
}

// src/index.ts
import {
  setDefaults,
  getDefaults,
  createDefaults,
  watchBatchCompletion as watchBatchCompletion2,
  UploadHttpError
} from "@netipar/chunky-core";
export {
  UploadHttpError,
  createDefaults,
  getDefaults,
  setDefaults,
  useBatchCompletion,
  useBatchEcho,
  useBatchUpload,
  useChunkUpload,
  useUpload,
  useUploadEcho,
  useUserEcho,
  watchBatchCompletion2 as watchBatchCompletion
};
//# sourceMappingURL=index.js.map
