// src/config.ts
var globalDefaults = {};
function setDefaults(options) {
  globalDefaults = { ...options };
}
function getDefaults() {
  return globalDefaults;
}
function createDefaults(initial = {}) {
  let defaults = { ...initial };
  return {
    setDefaults(options) {
      defaults = { ...options };
    },
    getDefaults() {
      return defaults;
    }
  };
}

// src/ChunkUploader.ts
var DEFAULT_ENDPOINTS = {
  initiate: "/api/chunky/upload",
  upload: "/api/chunky/upload/{uploadId}/chunks",
  status: "/api/chunky/upload/{uploadId}"
};
async function computeChecksum(data) {
  if (typeof crypto === "undefined" || !crypto.subtle) {
    return null;
  }
  const buffer = await data.arrayBuffer();
  const hashBuffer = await crypto.subtle.digest("SHA-256", buffer);
  const hashArray = Array.from(new Uint8Array(hashBuffer));
  return hashArray.map((b) => b.toString(16).padStart(2, "0")).join("");
}
var ChunkUploader = class {
  constructor(options = {}, scope) {
    this.progress = 0;
    this.isUploading = false;
    this.isPaused = false;
    this.isComplete = false;
    this.error = null;
    this.uploadId = null;
    this.uploadedChunks = 0;
    this.totalChunks = 0;
    this.currentFile = null;
    this.abortController = null;
    this.pendingChunks = [];
    this.serverChunkSize = null;
    this.lastFile = null;
    this.listeners = /* @__PURE__ */ new Map();
    const defaults = scope ? scope.getDefaults() : getDefaults();
    const merged = { ...defaults, ...options };
    this.maxConcurrent = merged.maxConcurrent ?? 3;
    this.autoRetry = merged.autoRetry ?? true;
    this.maxRetries = merged.maxRetries ?? 3;
    this.headers = { ...defaults.headers, ...options.headers };
    this.withCredentials = merged.withCredentials ?? true;
    this.context = merged.context;
    this.checksumEnabled = merged.checksum ?? true;
    this.chunkSizeOverride = merged.chunkSize;
    this.endpoints = { ...DEFAULT_ENDPOINTS, ...defaults.endpoints, ...options.endpoints };
    this.validateEndpoints();
  }
  validateEndpoints() {
    if (!this.endpoints.upload.includes("{uploadId}")) {
      throw new Error('Upload endpoint must contain "{uploadId}" placeholder.');
    }
    if (!this.endpoints.status.includes("{uploadId}")) {
      throw new Error('Status endpoint must contain "{uploadId}" placeholder.');
    }
  }
  on(event, callback) {
    if (!this.listeners.has(event)) {
      this.listeners.set(event, /* @__PURE__ */ new Set());
    }
    this.listeners.get(event).add(callback);
    return () => {
      this.listeners.get(event)?.delete(callback);
    };
  }
  emit(event, data) {
    this.listeners.get(event)?.forEach((cb) => cb(data));
  }
  emitStateChange() {
    this.emit("stateChange", this.getState());
  }
  getState() {
    return {
      progress: this.progress,
      isUploading: this.isUploading,
      isPaused: this.isPaused,
      isComplete: this.isComplete,
      error: this.error,
      uploadId: this.uploadId,
      uploadedChunks: this.uploadedChunks,
      totalChunks: this.totalChunks,
      currentFile: this.currentFile
    };
  }
  getCsrfFromCookie() {
    if (typeof document === "undefined") {
      return null;
    }
    const match = document.cookie.split("; ").find((row) => row.startsWith("XSRF-TOKEN="));
    if (!match) {
      return null;
    }
    return decodeURIComponent(match.split("=")[1]);
  }
  getHeaders() {
    const headers = {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
      ...this.headers
    };
    if (!headers["X-XSRF-TOKEN"]) {
      const token = this.getCsrfFromCookie();
      if (token) {
        headers["X-XSRF-TOKEN"] = token;
      }
    }
    return headers;
  }
  async fetchJson(url, init) {
    const response = await fetch(url, {
      ...init,
      credentials: this.withCredentials ? "include" : "same-origin",
      signal: this.abortController?.signal
    });
    if (!response.ok) {
      const body = await response.text();
      throw new Error(`HTTP ${response.status}: ${body}`);
    }
    return response.json();
  }
  async initiateUpload(file, metadata) {
    const body = JSON.stringify({
      file_name: file.name,
      file_size: file.size,
      mime_type: file.type || null,
      metadata: metadata ?? null,
      ...this.context ? { context: this.context } : {}
    });
    return this.fetchJson(this.endpoints.initiate, {
      method: "POST",
      headers: { ...this.getHeaders(), "Content-Type": "application/json" },
      body
    });
  }
  async uploadSingleChunk(id, chunkIndex, chunkBlob, total, retriesLeft) {
    const checksum = this.checksumEnabled ? await computeChecksum(chunkBlob) : null;
    const formData = new FormData();
    formData.append("chunk", chunkBlob, `chunk_${chunkIndex}`);
    formData.append("chunk_index", String(chunkIndex));
    if (checksum) {
      formData.append("checksum", checksum);
    }
    const url = this.endpoints.upload.replace("{uploadId}", id);
    try {
      const result = await this.fetchJson(url, {
        method: "POST",
        headers: this.getHeaders(),
        body: formData
      });
      this.uploadedChunks = result.uploaded_count;
      this.progress = result.progress;
      this.emitStateChange();
      this.emit("progress", {
        uploadId: id,
        loaded: result.uploaded_count,
        total: result.total_chunks,
        percentage: result.progress,
        chunkIndex,
        totalChunks: total
      });
      this.emit("chunkUploaded", {
        index: chunkIndex,
        size: chunkBlob.size,
        checksum,
        uploadId: id
      });
      return result;
    } catch (err) {
      if (this.autoRetry && retriesLeft > 0) {
        const delay = Math.pow(2, this.maxRetries - retriesLeft) * 1e3;
        await new Promise((resolve) => setTimeout(resolve, delay));
        return this.uploadSingleChunk(id, chunkIndex, chunkBlob, total, retriesLeft - 1);
      }
      throw err;
    }
  }
  async uploadChunks(file, id, chunkSize, total) {
    const chunks = this.pendingChunks.length > 0 ? [...this.pendingChunks] : Array.from({ length: total }, (_, i) => i);
    let index = 0;
    const next = async () => {
      while (index < chunks.length) {
        if (this.isPaused || this.abortController?.signal.aborted) {
          return;
        }
        const chunkIndex = chunks[index++];
        const start = chunkIndex * chunkSize;
        const end = Math.min(start + chunkSize, file.size);
        const chunkBlob = file.slice(start, end);
        const result = await this.uploadSingleChunk(id, chunkIndex, chunkBlob, total, this.maxRetries);
        this.pendingChunks = this.pendingChunks.filter((i) => i !== chunkIndex);
        if (result.is_complete) {
          return;
        }
      }
    };
    const workers = Array.from({ length: Math.min(this.maxConcurrent, chunks.length) }, () => next());
    await Promise.all(workers);
  }
  async fetchStatus(id) {
    const url = this.endpoints.status.replace("{uploadId}", id);
    return this.fetchJson(url, {
      method: "GET",
      headers: this.getHeaders()
    });
  }
  async upload(file, metadata) {
    if (this.isUploading && !this.isPaused) {
      throw new Error("Upload already in progress. Cancel or wait for completion before starting a new upload.");
    }
    this.abortController?.abort();
    this.lastFile = file;
    this.lastMetadata = metadata;
    this.currentFile = file;
    this.isUploading = true;
    this.isPaused = false;
    this.isComplete = false;
    this.error = null;
    this.progress = 0;
    this.uploadedChunks = 0;
    this.abortController = new AbortController();
    this.emitStateChange();
    try {
      if (this.uploadId) {
        const status = await this.fetchStatus(this.uploadId);
        const alreadyUploaded = new Set(status.uploaded_chunks);
        this.serverChunkSize = status.chunk_size;
        this.totalChunks = status.total_chunks;
        this.uploadedChunks = status.uploaded_count;
        this.pendingChunks = Array.from({ length: status.total_chunks }, (_, i) => i).filter(
          (i) => !alreadyUploaded.has(i)
        );
      } else {
        const initResult = await this.initiateUpload(file, metadata);
        this.uploadId = initResult.upload_id;
        this.serverChunkSize = initResult.chunk_size;
        this.totalChunks = initResult.total_chunks;
        this.pendingChunks = Array.from({ length: initResult.total_chunks }, (_, i) => i);
      }
      this.emitStateChange();
      const chunkSize = this.chunkSizeOverride ?? this.serverChunkSize ?? 1024 * 1024;
      await this.uploadChunks(file, this.uploadId, chunkSize, this.totalChunks);
      if (!this.isPaused && !this.abortController.signal.aborted) {
        this.isComplete = true;
        this.progress = 100;
        this.emitStateChange();
        const result = {
          uploadId: this.uploadId,
          fileName: file.name,
          fileSize: file.size,
          totalChunks: this.totalChunks
        };
        this.emit("complete", result);
        return result;
      }
      return {
        uploadId: this.uploadId,
        fileName: file.name,
        fileSize: file.size,
        totalChunks: this.totalChunks
      };
    } catch (err) {
      const message = err instanceof Error ? err.message : "Upload failed";
      this.error = message;
      this.emitStateChange();
      const uploadError = {
        uploadId: this.uploadId,
        message,
        cause: err
      };
      this.emit("error", uploadError);
      throw err;
    } finally {
      this.isUploading = false;
      this.emitStateChange();
    }
  }
  pause() {
    this.isPaused = true;
    this.emitStateChange();
  }
  resume() {
    if (!this.isPaused || !this.lastFile || !this.uploadId) {
      return false;
    }
    this.isPaused = false;
    this.emitStateChange();
    this.upload(this.lastFile, this.lastMetadata);
    return true;
  }
  cancel() {
    this.abortController?.abort();
    this.isPaused = false;
    this.isUploading = false;
    this.uploadId = null;
    this.pendingChunks = [];
    this.progress = 0;
    this.uploadedChunks = 0;
    this.totalChunks = 0;
    this.currentFile = null;
    this.error = null;
    this.emitStateChange();
  }
  retry() {
    if (!this.lastFile || this.isUploading && !this.isPaused) {
      return false;
    }
    this.error = null;
    this.emitStateChange();
    this.upload(this.lastFile, this.lastMetadata);
    return true;
  }
  destroy() {
    this.abortController?.abort();
    this.listeners.clear();
  }
};

// src/BatchUploader.ts
var DEFAULT_BATCH_ENDPOINTS = {
  batchInitiate: "/api/chunky/batch",
  batchUpload: "/api/chunky/batch/{batchId}/upload",
  batchStatus: "/api/chunky/batch/{batchId}"
};
var BatchUploader = class {
  constructor(options = {}, scope) {
    this.batchId = null;
    this.totalFiles = 0;
    this.completedFiles = 0;
    this.failedFiles = 0;
    this.progress = 0;
    this.isUploading = false;
    this.isComplete = false;
    this.error = null;
    this.currentFileName = null;
    this.uploaders = [];
    this.results = [];
    this.abortController = null;
    this.listeners = /* @__PURE__ */ new Map();
    this.options = options;
    this.scope = scope;
    this.maxConcurrentFiles = options.maxConcurrentFiles ?? 2;
    this.batchEndpoints = {
      ...DEFAULT_BATCH_ENDPOINTS,
      ...options.endpoints
    };
  }
  on(event, callback) {
    if (!this.listeners.has(event)) {
      this.listeners.set(event, /* @__PURE__ */ new Set());
    }
    this.listeners.get(event).add(callback);
    return () => {
      this.listeners.get(event)?.delete(callback);
    };
  }
  emit(event, data) {
    this.listeners.get(event)?.forEach((cb) => cb(data));
  }
  emitStateChange() {
    this.emit("stateChange", this.getState());
  }
  getState() {
    return {
      batchId: this.batchId,
      totalFiles: this.totalFiles,
      completedFiles: this.completedFiles,
      failedFiles: this.failedFiles,
      progress: this.progress,
      isUploading: this.isUploading,
      isComplete: this.isComplete,
      error: this.error,
      currentFileName: this.currentFileName
    };
  }
  getCsrfFromCookie() {
    if (typeof document === "undefined") {
      return null;
    }
    const match = document.cookie.split("; ").find((row) => row.startsWith("XSRF-TOKEN="));
    if (!match) {
      return null;
    }
    return decodeURIComponent(match.split("=")[1]);
  }
  getHeaders() {
    const headers = {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
      ...this.options.headers ?? {}
    };
    if (!headers["X-XSRF-TOKEN"]) {
      const token = this.getCsrfFromCookie();
      if (token) {
        headers["X-XSRF-TOKEN"] = token;
      }
    }
    return headers;
  }
  async fetchJson(url, init) {
    const response = await fetch(url, {
      ...init,
      credentials: this.options.withCredentials !== false ? "include" : "same-origin",
      signal: this.abortController?.signal
    });
    if (!response.ok) {
      const body = await response.text();
      throw new Error(`HTTP ${response.status}: ${body}`);
    }
    return response.json();
  }
  async upload(files, metadata) {
    if (this.isUploading) {
      throw new Error("Batch upload already in progress.");
    }
    this.abortController = new AbortController();
    this.totalFiles = files.length;
    this.completedFiles = 0;
    this.failedFiles = 0;
    this.progress = 0;
    this.isUploading = true;
    this.isComplete = false;
    this.error = null;
    this.results = [];
    this.uploaders = [];
    this.emitStateChange();
    try {
      const batchResponse = await this.fetchJson(
        this.batchEndpoints.batchInitiate,
        {
          method: "POST",
          headers: { ...this.getHeaders(), "Content-Type": "application/json" },
          body: JSON.stringify({
            total_files: files.length,
            context: this.options.context ?? null,
            metadata: metadata ?? null
          })
        }
      );
      this.batchId = batchResponse.batch_id;
      this.emitStateChange();
      const fileQueue = [...files];
      let fileIndex = 0;
      const next = async () => {
        while (fileIndex < fileQueue.length) {
          if (this.abortController?.signal.aborted) {
            return;
          }
          const currentIndex = fileIndex++;
          const file = fileQueue[currentIndex];
          this.currentFileName = file.name;
          this.emitStateChange();
          try {
            const result2 = await this.uploadFileInBatch(file, metadata);
            this.results.push(result2);
            this.completedFiles++;
            this.emit("fileComplete", result2);
          } catch (err) {
            this.failedFiles++;
            const uploadError = {
              uploadId: null,
              message: err instanceof Error ? err.message : "File upload failed",
              cause: err
            };
            this.emit("fileError", uploadError);
          }
          this.progress = (this.completedFiles + this.failedFiles) / this.totalFiles * 100;
          this.emitStateChange();
          this.emitProgress();
        }
      };
      const workers = Array.from(
        { length: Math.min(this.maxConcurrentFiles, files.length) },
        () => next()
      );
      await Promise.all(workers);
      this.isComplete = true;
      this.currentFileName = null;
      this.progress = 100;
      this.emitStateChange();
      const result = {
        batchId: this.batchId,
        totalFiles: this.totalFiles,
        completedFiles: this.completedFiles,
        failedFiles: this.failedFiles,
        files: this.results
      };
      this.emit("complete", result);
      return result;
    } catch (err) {
      const message = err instanceof Error ? err.message : "Batch upload failed";
      this.error = message;
      this.emitStateChange();
      const uploadError = {
        uploadId: null,
        message,
        cause: err
      };
      this.emit("error", uploadError);
      throw err;
    } finally {
      this.isUploading = false;
      this.emitStateChange();
    }
  }
  async uploadFileInBatch(file, metadata) {
    const batchUploadEndpoint = this.batchEndpoints.batchUpload.replace("{batchId}", this.batchId);
    const uploader = new ChunkUploader(
      {
        ...this.options,
        endpoints: {
          ...this.options.endpoints,
          initiate: batchUploadEndpoint
        }
      },
      this.scope
    );
    this.uploaders.push(uploader);
    uploader.on("progress", () => {
      this.emitProgress(uploader);
    });
    return uploader.upload(file, metadata);
  }
  emitProgress(uploader) {
    this.emit("progress", {
      batchId: this.batchId ?? "",
      completedFiles: this.completedFiles,
      totalFiles: this.totalFiles,
      failedFiles: this.failedFiles,
      percentage: this.progress,
      currentFile: uploader?.currentFile ? { name: uploader.currentFile.name, progress: uploader.progress } : null
    });
  }
  cancel() {
    this.abortController?.abort();
    for (const uploader of this.uploaders) {
      uploader.cancel();
    }
    this.isUploading = false;
    this.currentFileName = null;
    this.emitStateChange();
  }
  pause() {
    for (const uploader of this.uploaders) {
      uploader.pause();
    }
  }
  resume() {
    for (const uploader of this.uploaders) {
      uploader.resume();
    }
  }
  destroy() {
    this.cancel();
    this.listeners.clear();
    for (const uploader of this.uploaders) {
      uploader.destroy();
    }
    this.uploaders = [];
  }
};

// src/echo.ts
function listenForUser(echo, userId, callbacks, channelPrefix = "chunky") {
  const channel = echo.private(`${channelPrefix}.user.${userId}`);
  if (callbacks.onUploadComplete) {
    channel.listen(".UploadCompleted", callbacks.onUploadComplete);
  }
  if (callbacks.onBatchComplete) {
    channel.listen(".BatchCompleted", callbacks.onBatchComplete);
  }
  if (callbacks.onBatchPartiallyCompleted) {
    channel.listen(".BatchPartiallyCompleted", callbacks.onBatchPartiallyCompleted);
  }
  return () => {
    channel.stopListening(".UploadCompleted");
    channel.stopListening(".BatchCompleted");
    channel.stopListening(".BatchPartiallyCompleted");
  };
}
function listenForUploadComplete(echo, uploadId, callback, channelPrefix = "chunky") {
  const channel = echo.private(`${channelPrefix}.uploads.${uploadId}`);
  channel.listen(".UploadCompleted", callback);
  return () => {
    channel.stopListening(".UploadCompleted");
  };
}
function listenForBatchComplete(echo, batchId, callbacks, channelPrefix = "chunky") {
  const channel = echo.private(`${channelPrefix}.batches.${batchId}`);
  if (callbacks.onComplete) {
    channel.listen(".BatchCompleted", callbacks.onComplete);
  }
  if (callbacks.onPartiallyCompleted) {
    channel.listen(".BatchPartiallyCompleted", callbacks.onPartiallyCompleted);
  }
  return () => {
    channel.stopListening(".BatchCompleted");
    channel.stopListening(".BatchPartiallyCompleted");
  };
}
export {
  BatchUploader,
  ChunkUploader,
  createDefaults,
  getDefaults,
  listenForBatchComplete,
  listenForUploadComplete,
  listenForUser,
  setDefaults
};
//# sourceMappingURL=index.js.map
