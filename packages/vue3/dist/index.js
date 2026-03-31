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

// src/index.ts
import { setDefaults, getDefaults, createDefaults } from "@netipar/chunky-core";
export {
  createDefaults,
  getDefaults,
  setDefaults,
  useChunkUpload
};
//# sourceMappingURL=index.js.map
