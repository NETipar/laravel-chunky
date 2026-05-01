// src/useChunkUpload.ts
import { ref, getCurrentScope, onScopeDispose } from "vue";
import { ChunkUploader } from "@netipar/chunky-core";
function useChunkUpload(options = {}) {
  const uploader = new ChunkUploader(options);
  const progress = ref(0);
  const isUploading = ref(false);
  const isPaused = ref(false);
  const isComplete = ref(false);
  const error = ref(null);
  const uploadId = ref(null);
  const uploadedChunks = ref(0);
  const totalChunks = ref(0);
  const currentFile = ref(null);
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
  if (getCurrentScope()) {
    onScopeDispose(() => uploader.destroy());
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
import { ref as ref2, getCurrentScope as getCurrentScope2, onScopeDispose as onScopeDispose2 } from "vue";
import { BatchUploader } from "@netipar/chunky-core";
function useBatchUpload(options = {}) {
  const uploader = new BatchUploader(options);
  const batchId = ref2(null);
  const totalFiles = ref2(0);
  const completedFiles = ref2(0);
  const failedFiles = ref2(0);
  const progress = ref2(0);
  const isUploading = ref2(false);
  const isComplete = ref2(false);
  const error = ref2(null);
  const currentFileName = ref2(null);
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
  if (getCurrentScope2()) {
    onScopeDispose2(() => uploader.destroy());
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
import { watch, getCurrentScope as getCurrentScope3, onScopeDispose as onScopeDispose3 } from "vue";
import { listenForUser, listenForUploadComplete, listenForBatchComplete } from "@netipar/chunky-core";
function useUserEcho(echo, userId, callbacks, channelPrefix) {
  let cleanup = null;
  watch(userId, (id) => {
    cleanup?.();
    cleanup = null;
    if (id) {
      cleanup = listenForUser(echo, id, callbacks, channelPrefix);
    }
  }, { immediate: true });
  if (getCurrentScope3()) {
    onScopeDispose3(() => cleanup?.());
  }
}
function useUploadEcho(echo, uploadId, callback, channelPrefix) {
  let cleanup = null;
  watch(uploadId, (id) => {
    cleanup?.();
    cleanup = null;
    if (id) {
      cleanup = listenForUploadComplete(echo, id, callback, channelPrefix);
    }
  }, { immediate: true });
  if (getCurrentScope3()) {
    onScopeDispose3(() => cleanup?.());
  }
}
function useBatchEcho(echo, batchId, callbacks, channelPrefix) {
  let cleanup = null;
  watch(batchId, (id) => {
    cleanup?.();
    cleanup = null;
    if (id) {
      cleanup = listenForBatchComplete(echo, id, callbacks, channelPrefix);
    }
  }, { immediate: true });
  if (getCurrentScope3()) {
    onScopeDispose3(() => cleanup?.());
  }
}

// src/useBatchCompletion.ts
import { ref as ref3, watch as watch2, getCurrentScope as getCurrentScope4, onScopeDispose as onScopeDispose4 } from "vue";
import { watchBatchCompletion } from "@netipar/chunky-core";
function useBatchCompletion(batchId, options = {}) {
  const isWaiting = ref3(false);
  const receivedVia = ref3(null);
  const result = ref3(null);
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
    cleanup = watchBatchCompletion({
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
  watch2(
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
  if (getCurrentScope4()) {
    onScopeDispose4(stop);
  }
  return {
    isWaiting,
    receivedVia,
    result,
    cancel: stop
  };
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
  useUploadEcho,
  useUserEcho,
  watchBatchCompletion2 as watchBatchCompletion
};
//# sourceMappingURL=index.js.map
