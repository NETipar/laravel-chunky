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
var src_exports = {};
__export(src_exports, {
  createDefaults: () => import_chunky_core4.createDefaults,
  getDefaults: () => import_chunky_core4.getDefaults,
  setDefaults: () => import_chunky_core4.setDefaults,
  useBatchEcho: () => useBatchEcho,
  useBatchUpload: () => useBatchUpload,
  useChunkUpload: () => useChunkUpload,
  useUploadEcho: () => useUploadEcho,
  useUserEcho: () => useUserEcho
});
module.exports = __toCommonJS(src_exports);

// src/useChunkUpload.ts
var import_react = require("react");
var import_chunky_core = require("@netipar/chunky-core");
function useChunkUpload(options = {}) {
  const optionsKey = (0, import_react.useMemo)(() => JSON.stringify(options), [options]);
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
    const uploader = new import_chunky_core.ChunkUploader(options);
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
  const upload = (0, import_react.useCallback)(
    (file, metadata) => uploaderRef.current.upload(file, metadata),
    []
  );
  const pause = (0, import_react.useCallback)(() => uploaderRef.current.pause(), []);
  const resume = (0, import_react.useCallback)(() => uploaderRef.current.resume(), []);
  const cancel = (0, import_react.useCallback)(() => uploaderRef.current.cancel(), []);
  const retry = (0, import_react.useCallback)(() => uploaderRef.current.retry(), []);
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
  const optionsKey = (0, import_react2.useMemo)(() => JSON.stringify(options), [options]);
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
    const uploader = new import_chunky_core2.BatchUploader(options);
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
  const upload = (0, import_react2.useCallback)(
    (files, metadata) => uploaderRef.current.upload(files, metadata),
    []
  );
  const cancel = (0, import_react2.useCallback)(() => uploaderRef.current.cancel(), []);
  const pause = (0, import_react2.useCallback)(() => uploaderRef.current.pause(), []);
  const resume = (0, import_react2.useCallback)(() => uploaderRef.current.resume(), []);
  const onProgress = (0, import_react2.useCallback)(
    (cb) => uploaderRef.current.on("progress", cb),
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
    cancel,
    pause,
    resume,
    onProgress,
    onFileComplete,
    onFileError,
    onComplete,
    onError
  };
}

// src/useChunkyEcho.ts
var import_react3 = require("react");
var import_chunky_core3 = require("@netipar/chunky-core");
function useUserEcho(echo, userId, callbacks, channelPrefix) {
  (0, import_react3.useEffect)(() => {
    if (!userId) {
      return;
    }
    return (0, import_chunky_core3.listenForUser)(echo, userId, callbacks, channelPrefix);
  }, [userId]);
}
function useUploadEcho(echo, uploadId, callback, channelPrefix) {
  (0, import_react3.useEffect)(() => {
    if (!uploadId) {
      return;
    }
    return (0, import_chunky_core3.listenForUploadComplete)(echo, uploadId, callback, channelPrefix);
  }, [uploadId]);
}
function useBatchEcho(echo, batchId, callbacks, channelPrefix) {
  (0, import_react3.useEffect)(() => {
    if (!batchId) {
      return;
    }
    return (0, import_chunky_core3.listenForBatchComplete)(echo, batchId, callbacks, channelPrefix);
  }, [batchId]);
}

// src/index.ts
var import_chunky_core4 = require("@netipar/chunky-core");
//# sourceMappingURL=index.cjs.map
