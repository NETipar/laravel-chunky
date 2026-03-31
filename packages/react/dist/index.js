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
    onProgress,
    onChunkUploaded,
    onComplete,
    onError
  };
}

// src/index.ts
import { setDefaults, getDefaults, createDefaults } from "@netipar/chunky-core";
export {
  createDefaults,
  getDefaults,
  setDefaults,
  useChunkUpload
};
//# sourceMappingURL=index.js.map
