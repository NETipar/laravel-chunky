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
  useChunkUpload: () => useChunkUpload
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
  if ((0, import_vue.getCurrentInstance)()) {
    (0, import_vue.onBeforeUnmount)(() => uploader.destroy());
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
