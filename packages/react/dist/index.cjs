"use strict";
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __export = (target, all) => {
  for (var name in all)
    __defProp(target, name, { get: all[name], enumerable: true });
};
var __copyProps = (to, from, except, desc) => {
  if (from && typeof from === "object" || typeof from === "function") {
    for (let key of __getOwnPropNames(from))
      if (!__hasOwnProp.call(to, key) && key !== except)
        __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
  }
  return to;
};
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

// src/index.ts
var index_exports = {};
__export(index_exports, {
  UploadHttpError: () => import_chunky_core5.UploadHttpError,
  createDefaults: () => import_chunky_core5.createDefaults,
  getDefaults: () => import_chunky_core5.getDefaults,
  setDefaults: () => import_chunky_core5.setDefaults,
  useBatchCompletion: () => useBatchCompletion,
  useBatchEcho: () => useBatchEcho,
  useBatchUpload: () => useBatchUpload,
  useChunkUpload: () => useChunkUpload,
  useUpload: () => useUpload,
  useUploadEcho: () => useUploadEcho,
  useUserEcho: () => useUserEcho,
  watchBatchCompletion: () => import_chunky_core5.watchBatchCompletion
});
module.exports = __toCommonJS(index_exports);

// src/useChunkUpload.ts
var import_react = require("react");
var import_chunky_core = require("@netipar/chunky-core");
function useChunkUpload(options = {}) {
  const optionsRef = (0, import_react.useRef)(options);
  const uploaderRef = (0, import_react.useRef)(null);
  const [progress, setProgress] = (0, import_react.useState)(0);
  const [isUploading, setIsUploading] = (0, import_react.useState)(false);
  const [isPaused, setIsPaused] = (0, import_react.useState)(false);
  const [isComplete, setIsComplete] = (0, import_react.useState)(false);
  const [error, setError] = (0, import_react.useState)(null);
  const [uploadId, setUploadId] = (0, import_react.useState)(null);
  const [uploadedChunks, setUploadedChunks] = (0, import_react.useState)(0);
  const [totalChunks, setTotalChunks] = (0, import_react.useState)(0);
  const [currentFile, setCurrentFile] = (0, import_react.useState)(null);
  (0, import_react.useEffect)(() => {
    const uploader = new import_chunky_core.ChunkUploader(optionsRef.current);
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
  const upload = (0, import_react.useCallback)(
    (file, metadata) => uploaderRef.current.upload(file, metadata),
    []
  );
  const pause = (0, import_react.useCallback)(() => uploaderRef.current.pause(), []);
  const resume = (0, import_react.useCallback)(() => uploaderRef.current.resume(), []);
  const cancel = (0, import_react.useCallback)(() => uploaderRef.current.cancel(), []);
  const retry = (0, import_react.useCallback)(() => uploaderRef.current.retry(), []);
  const destroy = (0, import_react.useCallback)(() => uploaderRef.current.destroy(), []);
  const onProgress = (0, import_react.useCallback)(
    (cb) => uploaderRef.current.on("progress", cb),
    []
  );
  const onChunkUploaded = (0, import_react.useCallback)(
    (cb) => uploaderRef.current.on("chunkUploaded", cb),
    []
  );
  const onComplete = (0, import_react.useCallback)(
    (cb) => uploaderRef.current.on("complete", cb),
    []
  );
  const onError = (0, import_react.useCallback)(
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
var import_react2 = require("react");
var import_chunky_core2 = require("@netipar/chunky-core");
function useBatchUpload(options = {}) {
  const optionsRef = (0, import_react2.useRef)(options);
  const uploaderRef = (0, import_react2.useRef)(null);
  const [batchId, setBatchId] = (0, import_react2.useState)(null);
  const [totalFiles, setTotalFiles] = (0, import_react2.useState)(0);
  const [completedFiles, setCompletedFiles] = (0, import_react2.useState)(0);
  const [failedFiles, setFailedFiles] = (0, import_react2.useState)(0);
  const [progress, setProgress] = (0, import_react2.useState)(0);
  const [isUploading, setIsUploading] = (0, import_react2.useState)(false);
  const [isComplete, setIsComplete] = (0, import_react2.useState)(false);
  const [error, setError] = (0, import_react2.useState)(null);
  const [currentFileName, setCurrentFileName] = (0, import_react2.useState)(null);
  (0, import_react2.useEffect)(() => {
    const uploader = new import_chunky_core2.BatchUploader(optionsRef.current);
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
  const upload = (0, import_react2.useCallback)(
    (files, metadata) => uploaderRef.current.upload(files, metadata),
    []
  );
  const enqueue = (0, import_react2.useCallback)(
    (files, metadata) => uploaderRef.current.enqueue(files, metadata),
    []
  );
  const cancel = (0, import_react2.useCallback)(() => uploaderRef.current.cancel(), []);
  const pause = (0, import_react2.useCallback)(() => uploaderRef.current.pause(), []);
  const resume = (0, import_react2.useCallback)(() => uploaderRef.current.resume(), []);
  const destroy = (0, import_react2.useCallback)(() => uploaderRef.current.destroy(), []);
  const onProgress = (0, import_react2.useCallback)(
    (cb) => uploaderRef.current.on("progress", cb),
    []
  );
  const onFileProgress = (0, import_react2.useCallback)(
    (cb) => uploaderRef.current.on("fileProgress", cb),
    []
  );
  const onFileComplete = (0, import_react2.useCallback)(
    (cb) => uploaderRef.current.on("fileComplete", cb),
    []
  );
  const onFileError = (0, import_react2.useCallback)(
    (cb) => uploaderRef.current.on("fileError", cb),
    []
  );
  const onComplete = (0, import_react2.useCallback)(
    (cb) => uploaderRef.current.on("complete", cb),
    []
  );
  const onError = (0, import_react2.useCallback)(
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
var import_react3 = require("react");
var import_chunky_core3 = require("@netipar/chunky-core");
function useBatchCompletion(batchId, options = {}) {
  const [isWaiting, setIsWaiting] = (0, import_react3.useState)(false);
  const [receivedVia, setReceivedVia] = (0, import_react3.useState)(null);
  const [result, setResult] = (0, import_react3.useState)(null);
  const optionsRef = (0, import_react3.useRef)(options);
  optionsRef.current = options;
  const cleanupRef = (0, import_react3.useRef)(null);
  const stop = () => {
    cleanupRef.current?.();
    cleanupRef.current = null;
    setIsWaiting(false);
  };
  const cancelRef = (0, import_react3.useRef)(stop);
  cancelRef.current = stop;
  (0, import_react3.useEffect)(() => {
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
      cleanupRef.current = (0, import_chunky_core3.watchBatchCompletion)({
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
var import_react4 = require("react");
var import_chunky_core4 = require("@netipar/chunky-core");
function useCallbackRef(value) {
  const ref = (0, import_react4.useRef)(value);
  ref.current = value;
  return ref;
}
function useUserEcho(echo, userId, callbacks, channelPrefix) {
  const cbRef = useCallbackRef(callbacks);
  (0, import_react4.useEffect)(() => {
    if (!userId) {
      return;
    }
    return (0, import_chunky_core4.listenForUser)(
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
  (0, import_react4.useEffect)(() => {
    if (!uploadId) {
      return;
    }
    return (0, import_chunky_core4.listenForUploadComplete)(echo, uploadId, (data) => cbRef.current(data), channelPrefix);
  }, [echo, uploadId, channelPrefix]);
}
function useBatchEcho(echo, batchId, callbacks, channelPrefix) {
  const cbRef = useCallbackRef(callbacks);
  (0, import_react4.useEffect)(() => {
    if (!batchId) {
      return;
    }
    return (0, import_chunky_core4.listenForBatchComplete)(
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
var import_chunky_core5 = require("@netipar/chunky-core");
//# sourceMappingURL=index.cjs.map
