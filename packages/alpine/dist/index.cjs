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
  registerChunkUpload: () => registerChunkUpload
});
module.exports = __toCommonJS(src_exports);

// src/chunk-upload.ts
var import_chunky_core = require("@netipar/chunky-core");
function registerChunkUpload(Alpine) {
  Alpine.data("chunkUpload", (options = {}) => ({
    progress: 0,
    isUploading: false,
    isPaused: false,
    isComplete: false,
    error: null,
    uploadId: null,
    uploadedChunks: 0,
    totalChunks: 0,
    currentFile: null,
    _uploader: null,
    init() {
      this._uploader = new import_chunky_core.ChunkUploader(options);
      this._uploader.on("stateChange", (state) => {
        this.progress = state.progress;
        this.isUploading = state.isUploading;
        this.isPaused = state.isPaused;
        this.isComplete = state.isComplete;
        this.error = state.error;
        this.uploadId = state.uploadId;
        this.uploadedChunks = state.uploadedChunks;
        this.totalChunks = state.totalChunks;
        this.currentFile = state.currentFile;
      });
      this._uploader.on("complete", (result) => {
        this.$dispatch("chunky:complete", result);
      });
      this._uploader.on("error", (error) => {
        this.$dispatch("chunky:error", error);
      });
    },
    destroy() {
      this._uploader?.destroy();
    },
    async upload(file, metadata) {
      return this._uploader.upload(file, metadata);
    },
    handleFileInput(event) {
      const input = event.target;
      const file = input?.files?.[0];
      if (file) {
        this.upload(file);
      }
    },
    pause() {
      this._uploader.pause();
    },
    resume() {
      return this._uploader.resume();
    },
    cancel() {
      this._uploader.cancel();
    },
    retry() {
      return this._uploader.retry();
    }
  }));
}
