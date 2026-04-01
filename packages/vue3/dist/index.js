// src/useChunkUpload.ts
import { ref, onBeforeUnmount, getCurrentInstance } from "vue";
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
  if (getCurrentInstance()) {
    onBeforeUnmount(() => uploader.destroy());
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
    onProgress: (cb) => uploader.on("progress", cb),
    onChunkUploaded: (cb) => uploader.on("chunkUploaded", cb),
    onComplete: (cb) => uploader.on("complete", cb),
    onError: (cb) => uploader.on("error", cb)
  };
}

// src/useBatchUpload.ts
import { ref as ref2, onBeforeUnmount as onBeforeUnmount2, getCurrentInstance as getCurrentInstance2 } from "vue";
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
  if (getCurrentInstance2()) {
    onBeforeUnmount2(() => uploader.destroy());
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
    cancel: () => uploader.cancel(),
    pause: () => uploader.pause(),
    resume: () => uploader.resume(),
    onProgress: (cb) => uploader.on("progress", cb),
    onFileComplete: (cb) => uploader.on("fileComplete", cb),
    onFileError: (cb) => uploader.on("fileError", cb),
    onComplete: (cb) => uploader.on("complete", cb),
    onError: (cb) => uploader.on("error", cb)
  };
}

// src/useChunkyEcho.ts
import { watch, onBeforeUnmount as onBeforeUnmount3, getCurrentInstance as getCurrentInstance3 } from "vue";
import { listenForUploadComplete, listenForBatchComplete } from "@netipar/chunky-core";
function useUploadEcho(echo, uploadId, callback, channelPrefix) {
  let cleanup = null;
  watch(uploadId, (id) => {
    cleanup?.();
    cleanup = null;
    if (id) {
      cleanup = listenForUploadComplete(echo, id, callback, channelPrefix);
    }
  }, { immediate: true });
  if (getCurrentInstance3()) {
    onBeforeUnmount3(() => cleanup?.());
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
  if (getCurrentInstance3()) {
    onBeforeUnmount3(() => cleanup?.());
  }
}

// src/index.ts
import { setDefaults, getDefaults, createDefaults } from "@netipar/chunky-core";
export {
  createDefaults,
  getDefaults,
  setDefaults,
  useBatchEcho,
  useBatchUpload,
  useChunkUpload,
  useUploadEcho
};
//# sourceMappingURL=index.js.map
