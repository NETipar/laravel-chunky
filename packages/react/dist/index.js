// src/useChunkUpload.ts
import { useState, useRef, useEffect, useCallback, useMemo } from "react";
import { ChunkUploader } from "@netipar/chunky-core";
function useChunkUpload(options = {}) {
  const optionsKey = useMemo(() => JSON.stringify(options), [options]);
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
    const uploader = new ChunkUploader(options);
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
  }, [optionsKey]);
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
import { useState as useState2, useRef as useRef2, useEffect as useEffect2, useCallback as useCallback2, useMemo as useMemo2 } from "react";
import { BatchUploader } from "@netipar/chunky-core";
function useBatchUpload(options = {}) {
  const optionsKey = useMemo2(() => JSON.stringify(options), [options]);
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
    const uploader = new BatchUploader(options);
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
  }, [optionsKey]);
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

// src/useChunkyEcho.ts
import { useEffect as useEffect3 } from "react";
import { listenForUser, listenForUploadComplete, listenForBatchComplete } from "@netipar/chunky-core";
function useUserEcho(echo, userId, callbacks, channelPrefix) {
  useEffect3(() => {
    if (!userId) {
      return;
    }
    return listenForUser(echo, userId, callbacks, channelPrefix);
  }, [userId]);
}
function useUploadEcho(echo, uploadId, callback, channelPrefix) {
  useEffect3(() => {
    if (!uploadId) {
      return;
    }
    return listenForUploadComplete(echo, uploadId, callback, channelPrefix);
  }, [uploadId]);
}
function useBatchEcho(echo, batchId, callbacks, channelPrefix) {
  useEffect3(() => {
    if (!batchId) {
      return;
    }
    return listenForBatchComplete(echo, batchId, callbacks, channelPrefix);
  }, [batchId]);
}

// src/index.ts
import {
  setDefaults,
  getDefaults,
  createDefaults,
  watchBatchCompletion,
  UploadHttpError
} from "@netipar/chunky-core";
export {
  UploadHttpError,
  createDefaults,
  getDefaults,
  setDefaults,
  useBatchEcho,
  useBatchUpload,
  useChunkUpload,
  useUploadEcho,
  useUserEcho,
  watchBatchCompletion
};
//# sourceMappingURL=index.js.map
