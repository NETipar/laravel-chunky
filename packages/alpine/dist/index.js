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
export {
  registerChunkUpload
};
