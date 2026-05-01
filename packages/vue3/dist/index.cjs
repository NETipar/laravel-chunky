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
  createDefaults: () => import_chunky_core5.createDefaults,
  getDefaults: () => import_chunky_core5.getDefaults,
  setDefaults: () => import_chunky_core5.setDefaults,
  useBatchCompletion: () => useBatchCompletion,
  useBatchEcho: () => useBatchEcho,
  useBatchUpload: () => useBatchUpload,
  useChunkUpload: () => useChunkUpload,
  useUploadEcho: () => useUploadEcho,
  useUserEcho: () => useUserEcho,
  watchBatchCompletion: () => import_chunky_core5.watchBatchCompletion
});
module.exports = __toCommonJS(src_exports);

// src/useChunkUpload.ts
var import_vue = require("vue");
var import_chunky_core = require("@netipar/chunky-core");
function useChunkUpload(options = {}) {
  const uploader = new import_chunky_core.ChunkUploader(options);
  const progress = (0, import_vue.ref)(0);
  const isUploading = (0, import_vue.ref)(false);
  const isPaused = (0, import_vue.ref)(false);
  const isComplete = (0, import_vue.ref)(false);
  const error = (0, import_vue.ref)(null);
  const uploadId = (0, import_vue.ref)(null);
  const uploadedChunks = (0, import_vue.ref)(0);
  const totalChunks = (0, import_vue.ref)(0);
  const currentFile = (0, import_vue.ref)(null);
  uploader.on("stateChange", (state) => {
    progress.value = state.progress;
    isUploading.value = state.isUploading;
    isPaused.value = state.isPaused;
    isComplete.value = state.isComplete;
    error.value = state.error;
    uploadId.value = state.uploadId;
    uploadedChunks.value = state.uploadedChunks;
    totalChunks.value = state.totalChunks;
    currentFile.value = state.currentFile;
  });
  if ((0, import_vue.getCurrentScope)()) {
    (0, import_vue.onScopeDispose)(() => uploader.destroy());
  }
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
    upload: (file, metadata) => uploader.upload(file, metadata),
    pause: () => uploader.pause(),
    resume: () => uploader.resume(),
    cancel: () => uploader.cancel(),
    retry: () => uploader.retry(),
    destroy: () => uploader.destroy(),
    onProgress: (cb) => uploader.on("progress", cb),
    onChunkUploaded: (cb) => uploader.on("chunkUploaded", cb),
    onComplete: (cb) => uploader.on("complete", cb),
    onError: (cb) => uploader.on("error", cb)
  };
}

// src/useBatchUpload.ts
var import_vue2 = require("vue");
var import_chunky_core2 = require("@netipar/chunky-core");
function useBatchUpload(options = {}) {
  const uploader = new import_chunky_core2.BatchUploader(options);
  const batchId = (0, import_vue2.ref)(null);
  const totalFiles = (0, import_vue2.ref)(0);
  const completedFiles = (0, import_vue2.ref)(0);
  const failedFiles = (0, import_vue2.ref)(0);
  const progress = (0, import_vue2.ref)(0);
  const isUploading = (0, import_vue2.ref)(false);
  const isComplete = (0, import_vue2.ref)(false);
  const error = (0, import_vue2.ref)(null);
  const currentFileName = (0, import_vue2.ref)(null);
  uploader.on("stateChange", (state) => {
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
  if ((0, import_vue2.getCurrentScope)()) {
    (0, import_vue2.onScopeDispose)(() => uploader.destroy());
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
    onProgress: (cb) => uploader.on("progress", cb),
    onFileProgress: (cb) => uploader.on("fileProgress", cb),
    onFileComplete: (cb) => uploader.on("fileComplete", cb),
    onFileError: (cb) => uploader.on("fileError", cb),
    onComplete: (cb) => uploader.on("complete", cb),
    onError: (cb) => uploader.on("error", cb)
  };
}

// src/useChunkyEcho.ts
var import_vue3 = require("vue");
var import_chunky_core3 = require("@netipar/chunky-core");
function useUserEcho(echo, userId, callbacks, channelPrefix) {
  let cleanup = null;
  (0, import_vue3.watch)(userId, (id) => {
    cleanup?.();
    cleanup = null;
    if (id) {
      cleanup = (0, import_chunky_core3.listenForUser)(echo, id, callbacks, channelPrefix);
    }
  }, { immediate: true });
  if ((0, import_vue3.getCurrentScope)()) {
    (0, import_vue3.onScopeDispose)(() => cleanup?.());
  }
}
function useUploadEcho(echo, uploadId, callback, channelPrefix) {
  let cleanup = null;
  (0, import_vue3.watch)(uploadId, (id) => {
    cleanup?.();
    cleanup = null;
    if (id) {
      cleanup = (0, import_chunky_core3.listenForUploadComplete)(echo, id, callback, channelPrefix);
    }
  }, { immediate: true });
  if ((0, import_vue3.getCurrentScope)()) {
    (0, import_vue3.onScopeDispose)(() => cleanup?.());
  }
}
function useBatchEcho(echo, batchId, callbacks, channelPrefix) {
  let cleanup = null;
  (0, import_vue3.watch)(batchId, (id) => {
    cleanup?.();
    cleanup = null;
    if (id) {
      cleanup = (0, import_chunky_core3.listenForBatchComplete)(echo, id, callbacks, channelPrefix);
    }
  }, { immediate: true });
  if ((0, import_vue3.getCurrentScope)()) {
    (0, import_vue3.onScopeDispose)(() => cleanup?.());
  }
}

// src/useBatchCompletion.ts
var import_vue4 = require("vue");
var import_chunky_core4 = require("@netipar/chunky-core");
function useBatchCompletion(batchId, options = {}) {
  const isWaiting = (0, import_vue4.ref)(false);
  const receivedVia = (0, import_vue4.ref)(null);
  const result = (0, import_vue4.ref)(null);
  let cleanup = null;
  let debounceTimer = null;
  const stop = () => {
    if (debounceTimer) {
      clearTimeout(debounceTimer);
      debounceTimer = null;
    }
    cleanup?.();
    cleanup = null;
    isWaiting.value = false;
  };
  const handleResult = (kind, data) => {
    result.value = data;
    receivedVia.value = data.source;
    isWaiting.value = false;
    cleanup = null;
    if (kind === "partial") {
      options.onPartiallyCompleted?.(data);
    } else {
      options.onComplete?.(data);
    }
  };
  const debounceMs = options.debounceMs ?? 50;
  const startWatcher = (id) => {
    result.value = null;
    receivedVia.value = null;
    isWaiting.value = true;
    cleanup = (0, import_chunky_core4.watchBatchCompletion)({
      batchId: id,
      statusEndpoint: options.statusEndpoint,
      echo: options.echo,
      channelPrefix: options.channelPrefix,
      pollStartDelayMs: options.pollStartDelayMs,
      pollIntervalMs: options.pollIntervalMs,
      pollMaxIntervalMs: options.pollMaxIntervalMs,
      pollBackoffFactor: options.pollBackoffFactor,
      timeoutMs: options.timeoutMs,
      headers: options.headers,
      withCredentials: options.withCredentials,
      onSubscribed: options.onSubscribed,
      onSubscribeError: options.onSubscribeError,
      onComplete: (data) => handleResult("complete", data),
      onPartiallyCompleted: (data) => handleResult("partial", data),
      onTimeout: () => {
        isWaiting.value = false;
        cleanup = null;
        options.onTimeout?.();
      },
      onError: (err, isFatal) => {
        if (isFatal) {
          isWaiting.value = false;
          cleanup = null;
        }
        options.onError?.(err, isFatal);
      }
    });
  };
  (0, import_vue4.watch)(
    batchId,
    (id) => {
      stop();
      if (!id) {
        return;
      }
      if (debounceMs <= 0) {
        startWatcher(id);
        return;
      }
      debounceTimer = setTimeout(() => {
        debounceTimer = null;
        startWatcher(id);
      }, debounceMs);
    },
    { immediate: true }
  );
  if ((0, import_vue4.getCurrentScope)()) {
    (0, import_vue4.onScopeDispose)(stop);
  }
  return {
    isWaiting,
    receivedVia,
    result,
    cancel: stop
  };
}

// src/index.ts
var import_chunky_core5 = require("@netipar/chunky-core");
//# sourceMappingURL=index.cjs.map
