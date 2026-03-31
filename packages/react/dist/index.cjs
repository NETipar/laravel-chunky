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
  getDefaults: () => import_chunky_core2.getDefaults,
  setDefaults: () => import_chunky_core2.setDefaults,
  useChunkUpload: () => useChunkUpload
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

// src/index.ts
var import_chunky_core2 = require("@netipar/chunky-core");
