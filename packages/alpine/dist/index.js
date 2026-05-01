// src/chunk-upload.ts
import { ChunkUploader } from "@netipar/chunky-core";
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
      this._uploader = new ChunkUploader(options);
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
      this._uploader.on("progress", (event) => {
        this.$dispatch("chunky:progress", event);
      });
      this._uploader.on("chunkUploaded", (chunk) => {
        this.$dispatch("chunky:chunk-uploaded", chunk);
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

// src/batch-upload.ts
import { BatchUploader } from "@netipar/chunky-core";
function registerBatchUpload(Alpine) {
  Alpine.data("batchUpload", (options = {}) => ({
    batchId: null,
    totalFiles: 0,
    completedFiles: 0,
    failedFiles: 0,
    progress: 0,
    isUploading: false,
    isComplete: false,
    error: null,
    currentFileName: null,
    _uploader: null,
    init() {
      this._uploader = new BatchUploader(options);
      this._uploader.on("stateChange", (state) => {
        this.batchId = state.batchId;
        this.totalFiles = state.totalFiles;
        this.completedFiles = state.completedFiles;
        this.failedFiles = state.failedFiles;
        this.progress = state.progress;
        this.isUploading = state.isUploading;
        this.isComplete = state.isComplete;
        this.error = state.error;
        this.currentFileName = state.currentFileName;
      });
      this._uploader.on("progress", (event) => {
        this.$dispatch("chunky:batch-progress", event);
      });
      this._uploader.on("fileProgress", (event) => {
        this.$dispatch("chunky:batch-file-progress", event);
      });
      this._uploader.on("fileComplete", (result) => {
        this.$dispatch("chunky:batch-file-complete", result);
      });
      this._uploader.on("fileError", (error) => {
        this.$dispatch("chunky:batch-file-error", error);
      });
      this._uploader.on("complete", (result) => {
        this.$dispatch("chunky:batch-complete", result);
      });
      this._uploader.on("error", (error) => {
        this.$dispatch("chunky:batch-error", error);
      });
    },
    destroy() {
      this._uploader?.destroy();
    },
    async upload(files, metadata) {
      return this._uploader.upload(files, metadata);
    },
    handleFileInput(event) {
      const input = event.target;
      const files = input?.files;
      if (files && files.length > 0) {
        this.upload(Array.from(files));
      }
    },
    cancel() {
      this._uploader.cancel();
    },
    pause() {
      this._uploader.pause();
    },
    resume() {
      this._uploader.resume();
    }
  }));
}

// src/index.ts
import { setDefaults, getDefaults, createDefaults } from "@netipar/chunky-core";
export {
  createDefaults,
  getDefaults,
  registerBatchUpload,
  registerChunkUpload,
  setDefaults
};
//# sourceMappingURL=index.js.map
