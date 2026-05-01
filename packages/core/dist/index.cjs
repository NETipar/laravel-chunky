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
var index_exports = {};
__export(index_exports, {
  BatchUploader: () => BatchUploader,
  ChunkUploader: () => ChunkUploader,
  UploadHttpError: () => UploadHttpError,
  createDefaults: () => createDefaults,
  getDefaults: () => getDefaults,
  listenForBatchComplete: () => listenForBatchComplete,
  listenForUploadComplete: () => listenForUploadComplete,
  listenForUploadEvents: () => listenForUploadEvents,
  listenForUser: () => listenForUser,
  setDefaults: () => setDefaults,
  watchBatchCompletion: () => watchBatchCompletion
});
module.exports = __toCommonJS(index_exports);

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

// src/http.ts
function getCsrfFromCookie() {
  if (typeof document === "undefined") {
    return null;
  }
  const match = document.cookie.split("; ").find((row) => row.startsWith("XSRF-TOKEN="));
  if (!match) {
    return null;
  }
  return decodeURIComponent(match.split("=")[1]);
}
function buildHeaders(extra) {
  const headers = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
    ...extra ?? {}
  };
  if (!headers["X-XSRF-TOKEN"]) {
    const token = getCsrfFromCookie();
    if (token) {
      headers["X-XSRF-TOKEN"] = token;
    }
  }
  return headers;
}

// src/types.ts
var UploadHttpError = class extends Error {
  constructor(status, body, message) {
    super(message);
    this.name = "UploadHttpError";
    this.status = status;
    this.body = body;
  }
};

// src/ChunkUploader.ts
var DEFAULT_ENDPOINTS = {
  initiate: "/api/chunky/upload",
  upload: "/api/chunky/upload/{uploadId}/chunks",
  status: "/api/chunky/upload/{uploadId}",
  cancel: "/api/chunky/upload/{uploadId}"
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
    this.lastComplete = null;
    this.lastError = null;
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
    if (!this.endpoints.cancel.includes("{uploadId}")) {
      throw new Error('Cancel endpoint must contain "{uploadId}" placeholder.');
    }
  }
  on(event, callback) {
    if (!this.listeners.has(event)) {
      this.listeners.set(event, /* @__PURE__ */ new Set());
    }
    const set = this.listeners.get(event);
    const stored = callback;
    set.add(stored);
    if (event === "complete" && this.lastComplete) {
      const sticky = this.lastComplete;
      queueMicrotask(() => {
        if (set.has(stored)) {
          callback(sticky);
        }
      });
    } else if (event === "error" && this.lastError) {
      const sticky = this.lastError;
      queueMicrotask(() => {
        if (set.has(stored)) {
          callback(sticky);
        }
      });
    }
    return () => {
      set.delete(stored);
    };
  }
  emit(event, data) {
    if (event === "complete") {
      this.lastComplete = data;
      this.lastError = null;
    } else if (event === "error") {
      this.lastError = data;
    }
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
  getHeaders() {
    return buildHeaders(this.headers);
  }
  async fetchJson(url, init) {
    const response = await fetch(url, {
      ...init,
      credentials: this.withCredentials ? "include" : "same-origin",
      signal: this.abortController?.signal
    });
    if (!response.ok) {
      const text = await response.text();
      let body = text;
      try {
        body = JSON.parse(text);
      } catch {
      }
      throw new UploadHttpError(
        response.status,
        body,
        `HTTP ${response.status}: ${text || response.statusText}`
      );
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
    const idempotencyKey = `${id}:${chunkIndex}`;
    try {
      const result = await this.fetchJson(url, {
        method: "POST",
        headers: { ...this.getHeaders(), "Idempotency-Key": idempotencyKey },
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
        const baseDelay = Math.pow(2, this.maxRetries - retriesLeft) * 1e3;
        const jitter = Math.random() * 250;
        const delay = baseDelay + jitter;
        await new Promise((resolve) => setTimeout(resolve, delay));
        return this.uploadSingleChunk(id, chunkIndex, chunkBlob, total, retriesLeft - 1);
      }
      throw err;
    }
  }
  async uploadChunks(file, id, chunkSize, total) {
    const chunks = this.pendingChunks.length > 0 ? [...this.pendingChunks] : Array.from({ length: total }, (_, i) => i);
    const pending = new Set(this.pendingChunks);
    let index = 0;
    let completed = false;
    const next = async () => {
      while (index < chunks.length) {
        if (completed || this.isPaused || this.abortController?.signal.aborted) {
          return;
        }
        const chunkIndex = chunks[index++];
        const start = chunkIndex * chunkSize;
        const end = Math.min(start + chunkSize, file.size);
        const chunkBlob = file.slice(start, end);
        const result = await this.uploadSingleChunk(id, chunkIndex, chunkBlob, total, this.maxRetries);
        pending.delete(chunkIndex);
        if (result.is_complete) {
          completed = true;
          return;
        }
      }
    };
    const workers = Array.from({ length: Math.min(this.maxConcurrent, chunks.length) }, () => next());
    try {
      await Promise.all(workers);
    } finally {
      this.pendingChunks = Array.from(pending).sort((a, b) => a - b);
    }
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
    if (this.uploadId && this.lastFile !== file) {
      this.uploadId = null;
      this.pendingChunks = [];
      this.lastMetadata = void 0;
      this.serverChunkSize = null;
      this.totalChunks = 0;
    }
    this.lastFile = file;
    this.lastMetadata = metadata;
    this.currentFile = file;
    this.isUploading = true;
    this.isPaused = false;
    this.isComplete = false;
    this.error = null;
    this.progress = 0;
    this.uploadedChunks = 0;
    this.lastComplete = null;
    this.lastError = null;
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
      const chunkSize = this.chunkSizeOverride ?? this.serverChunkSize;
      if (!chunkSize || chunkSize <= 0) {
        throw new Error(
          "Server did not return a chunk_size and no chunkSize override was provided. Cannot proceed without an authoritative chunk size."
        );
      }
      await this.uploadChunks(file, this.uploadId, chunkSize, this.totalChunks);
      if (!this.isPaused && !this.abortController.signal.aborted) {
        const result = {
          uploadId: this.uploadId,
          fileName: file.name,
          fileSize: file.size,
          totalChunks: this.totalChunks
        };
        this.isComplete = true;
        this.progress = 100;
        this.uploadId = null;
        this.pendingChunks = [];
        this.lastFile = null;
        this.lastMetadata = void 0;
        this.emitStateChange();
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
    this.upload(this.lastFile, this.lastMetadata).catch(() => {
    });
    return true;
  }
  cancel() {
    const abandonedId = this.uploadId;
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
    this.lastFile = null;
    this.lastMetadata = void 0;
    this.lastComplete = null;
    this.lastError = null;
    this.emitStateChange();
    if (abandonedId) {
      this.cancelOnServer(abandonedId).catch(() => {
      });
    }
  }
  async cancelOnServer(id) {
    const url = this.endpoints.cancel.replace("{uploadId}", id);
    await fetch(url, {
      method: "DELETE",
      credentials: this.withCredentials ? "include" : "same-origin",
      headers: this.getHeaders()
    });
  }
  retry() {
    if (!this.lastFile || this.isUploading && !this.isPaused) {
      return false;
    }
    this.error = null;
    this.emitStateChange();
    this.upload(this.lastFile, this.lastMetadata).catch(() => {
    });
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
    this.lastComplete = null;
    this.lastError = null;
    this.isPausedBatch = false;
    this.resumeBarrier = null;
    this.resumeBarrierResolve = null;
    this.pendingQueue = [];
    // Set by `cancel()` for the duration of the current upload() call so the
    // catch/finally branches can tell "the user cancelled" apart from "an
    // unrelated error happened" and avoid emitting a redundant `error` event
    // or a late `complete` after a `cancel`.
    this.cancelledThisRun = false;
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
    const set = this.listeners.get(event);
    const stored = callback;
    set.add(stored);
    if (event === "complete" && this.lastComplete) {
      const sticky = this.lastComplete;
      queueMicrotask(() => {
        if (set.has(stored)) {
          callback(sticky);
        }
      });
    } else if (event === "error" && this.lastError) {
      const sticky = this.lastError;
      queueMicrotask(() => {
        if (set.has(stored)) {
          callback(sticky);
        }
      });
    }
    return () => {
      set.delete(stored);
    };
  }
  emit(event, data) {
    if (event === "complete") {
      this.lastComplete = data;
      this.lastError = null;
    } else if (event === "error") {
      this.lastError = data;
    }
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
  async fetchJson(url, init, signal) {
    const response = await fetch(url, {
      ...init,
      credentials: this.options.withCredentials !== false ? "include" : "same-origin",
      signal: signal ?? this.abortController?.signal
    });
    if (!response.ok) {
      const text = await response.text();
      let body = text;
      try {
        body = JSON.parse(text);
      } catch {
      }
      throw new UploadHttpError(
        response.status,
        body,
        `HTTP ${response.status}: ${text || response.statusText}`
      );
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
    this.lastComplete = null;
    this.lastError = null;
    this.cancelledThisRun = false;
    this.emitStateChange();
    const signal = this.abortController.signal;
    try {
      const batchResponse = await this.fetchJson(
        this.batchEndpoints.batchInitiate,
        {
          method: "POST",
          headers: { ...buildHeaders(this.options.headers), "Content-Type": "application/json" },
          body: JSON.stringify({
            total_files: files.length,
            context: this.options.context ?? null,
            metadata: metadata ?? null
          })
        },
        signal
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
          if (this.isPausedBatch && this.resumeBarrier) {
            await this.resumeBarrier;
            if (this.abortController?.signal.aborted) {
              return;
            }
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
          this.emitProgress();
        }
      };
      const workers = Array.from(
        { length: Math.min(this.maxConcurrentFiles, files.length) },
        () => next()
      );
      await Promise.all(workers);
      if (this.cancelledThisRun || this.abortController?.signal.aborted) {
        return {
          batchId: this.batchId ?? "",
          totalFiles: this.totalFiles,
          completedFiles: this.completedFiles,
          failedFiles: this.failedFiles,
          files: this.results
        };
      }
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
      for (const uploader of this.uploaders) {
        uploader.cancel();
      }
      if (this.cancelledThisRun) {
        throw err;
      }
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
      this.drainQueue();
    }
  }
  /**
   * Queue files for upload. If no batch is currently running, this behaves
   * exactly like `upload()`. If a batch is in progress, the files are held
   * until the current batch completes (or fails) and then run in their own
   * batch. The returned promise resolves with the eventual `BatchResult`.
   *
   * If `cancel()` or `destroy()` is invoked before the queued batch starts,
   * the returned promise rejects with the corresponding error.
   */
  enqueue(files, metadata) {
    if (!this.isUploading) {
      return this.upload(files, metadata);
    }
    return new Promise((resolve, reject) => {
      this.pendingQueue.push({ files, metadata, resolve, reject });
    });
  }
  drainQueue() {
    if (this.isUploading || this.pendingQueue.length === 0) {
      return;
    }
    queueMicrotask(() => {
      if (this.isUploading || this.pendingQueue.length === 0) {
        return;
      }
      const next = this.pendingQueue.shift();
      this.upload(next.files, next.metadata).then(next.resolve, next.reject);
    });
  }
  rejectPendingQueue(reason) {
    if (this.pendingQueue.length === 0) {
      return;
    }
    const pending = this.pendingQueue.splice(0);
    const error = new Error(reason);
    for (const item of pending) {
      item.reject(error);
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
    uploader.on("progress", (event) => {
      this.emit("fileProgress", {
        batchId: this.batchId ?? "",
        uploadId: event.uploadId,
        fileName: file.name,
        loaded: event.loaded,
        total: event.total,
        percentage: event.percentage,
        chunkIndex: event.chunkIndex,
        totalChunks: event.totalChunks
      });
      this.emitProgress(uploader);
    });
    try {
      return await uploader.upload(file, metadata);
    } finally {
      const idx = this.uploaders.indexOf(uploader);
      if (idx >= 0) {
        this.uploaders.splice(idx, 1);
      }
      uploader.destroy();
    }
  }
  aggregateProgress() {
    if (this.totalFiles === 0) {
      return 0;
    }
    let inProgressContribution = 0;
    for (const uploader of this.uploaders) {
      if (uploader.isUploading && !uploader.isComplete) {
        inProgressContribution += uploader.progress / 100;
      }
    }
    const finishedFiles = this.completedFiles + this.failedFiles;
    const total = finishedFiles + inProgressContribution;
    return Math.min(100, total / this.totalFiles * 100);
  }
  emitProgress(uploader) {
    this.progress = this.aggregateProgress();
    this.emit("progress", {
      batchId: this.batchId ?? "",
      completedFiles: this.completedFiles,
      totalFiles: this.totalFiles,
      failedFiles: this.failedFiles,
      percentage: this.progress,
      currentFile: uploader?.currentFile ? { name: uploader.currentFile.name, progress: uploader.progress } : null
    });
    this.emitStateChange();
  }
  cancel() {
    const cancelledBatchId = this.batchId;
    this.cancelledThisRun = true;
    this.abortController?.abort();
    this.isPausedBatch = false;
    const resolve = this.resumeBarrierResolve;
    this.resumeBarrier = null;
    this.resumeBarrierResolve = null;
    resolve?.();
    for (const uploader of this.uploaders) {
      uploader.cancel();
    }
    this.rejectPendingQueue("Batch upload cancelled before queued upload could start.");
    this.isUploading = false;
    this.isComplete = false;
    this.currentFileName = null;
    this.lastComplete = null;
    this.lastError = null;
    this.emit("cancel", { batchId: cancelledBatchId });
    this.emitStateChange();
  }
  pause() {
    if (!this.isUploading) {
      return;
    }
    this.isPausedBatch = true;
    if (!this.resumeBarrier) {
      this.resumeBarrier = new Promise((resolve) => {
        this.resumeBarrierResolve = resolve;
      });
    }
    for (const uploader of this.uploaders) {
      uploader.pause();
    }
    this.emitStateChange();
  }
  resume() {
    if (!this.isPausedBatch) {
      return;
    }
    this.isPausedBatch = false;
    const resolve = this.resumeBarrierResolve;
    this.resumeBarrier = null;
    this.resumeBarrierResolve = null;
    resolve?.();
    for (const uploader of this.uploaders) {
      uploader.resume();
    }
    this.emitStateChange();
  }
  destroy() {
    this.rejectPendingQueue("BatchUploader destroyed before queued upload could start.");
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
  if (callbacks.onUploadFailed) {
    channel.listen(".UploadFailed", callbacks.onUploadFailed);
  }
  if (callbacks.onBatchComplete) {
    channel.listen(".BatchCompleted", callbacks.onBatchComplete);
  }
  if (callbacks.onBatchPartiallyCompleted) {
    channel.listen(".BatchPartiallyCompleted", callbacks.onBatchPartiallyCompleted);
  }
  if (callbacks.onSubscribed && typeof channel.subscribed === "function") {
    channel.subscribed(callbacks.onSubscribed);
  }
  if (callbacks.onSubscribeError && typeof channel.error === "function") {
    channel.error(callbacks.onSubscribeError);
  }
  return () => {
    channel.stopListening(".UploadCompleted");
    channel.stopListening(".UploadFailed");
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
function listenForUploadEvents(echo, uploadId, callbacks, channelPrefix = "chunky") {
  const channel = echo.private(`${channelPrefix}.uploads.${uploadId}`);
  if (callbacks.onComplete) {
    channel.listen(".UploadCompleted", callbacks.onComplete);
  }
  if (callbacks.onFailed) {
    channel.listen(".UploadFailed", callbacks.onFailed);
  }
  if (callbacks.onSubscribed && typeof channel.subscribed === "function") {
    channel.subscribed(callbacks.onSubscribed);
  }
  if (callbacks.onSubscribeError && typeof channel.error === "function") {
    channel.error(callbacks.onSubscribeError);
  }
  return () => {
    channel.stopListening(".UploadCompleted");
    channel.stopListening(".UploadFailed");
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
  if (callbacks.onSubscribed && typeof channel.subscribed === "function") {
    channel.subscribed(callbacks.onSubscribed);
  }
  if (callbacks.onSubscribeError && typeof channel.error === "function") {
    channel.error(callbacks.onSubscribeError);
  }
  return () => {
    channel.stopListening(".BatchCompleted");
    channel.stopListening(".BatchPartiallyCompleted");
  };
}

// src/CompletionWatcher.ts
var DEFAULT_STATUS_ENDPOINT = "/api/chunky/batch/{batchId}";
var DEFAULT_POLL_START_DELAY_MS = 1500;
var DEFAULT_POLL_INTERVAL_MS = 2e3;
var DEFAULT_POLL_MAX_INTERVAL_MS = 3e4;
var DEFAULT_POLL_BACKOFF_FACTOR = 1.5;
var DEFAULT_TIMEOUT_MS = 5 * 60 * 1e3;
function isTerminalStatus(status) {
  return status === "completed" || status === "partially_completed" || status === "expired";
}
function toResult(source, response) {
  return {
    source,
    batchId: response.batch_id,
    totalFiles: response.total_files,
    completedFiles: response.completed_files,
    failedFiles: response.failed_files,
    status: response.status
  };
}
function watchBatchCompletion(options) {
  const {
    batchId,
    statusEndpoint = DEFAULT_STATUS_ENDPOINT,
    echo,
    channelPrefix,
    pollStartDelayMs = DEFAULT_POLL_START_DELAY_MS,
    pollIntervalMs = DEFAULT_POLL_INTERVAL_MS,
    pollMaxIntervalMs = DEFAULT_POLL_MAX_INTERVAL_MS,
    pollBackoffFactor = DEFAULT_POLL_BACKOFF_FACTOR,
    timeoutMs = DEFAULT_TIMEOUT_MS,
    extendTimeoutOnProgressMs = 0,
    headers,
    withCredentials = true,
    onComplete,
    onPartiallyCompleted,
    onTimeout,
    onError,
    onSubscribed,
    onSubscribeError
  } = options;
  const url = statusEndpoint.replace("{batchId}", batchId);
  let resolved = false;
  let echoCleanup = null;
  let echoSubscribed = false;
  let pollStartTimer = null;
  let pollTimer = null;
  let timeoutTimer = null;
  let abortController = null;
  let currentPollIntervalMs = pollIntervalMs;
  let lastProcessedCount = -1;
  const cleanup = () => {
    echoCleanup?.();
    echoCleanup = null;
    if (pollStartTimer) {
      clearTimeout(pollStartTimer);
      pollStartTimer = null;
    }
    if (pollTimer) {
      clearTimeout(pollTimer);
      pollTimer = null;
    }
    if (timeoutTimer) {
      clearTimeout(timeoutTimer);
      timeoutTimer = null;
    }
    abortController?.abort();
    abortController = null;
  };
  const resolveBroadcast = (kind, data) => {
    if (resolved) {
      return;
    }
    resolved = true;
    const completedFiles = kind === "partial" ? data.completedFiles : data.totalFiles;
    const failedFiles = kind === "partial" ? data.failedFiles : 0;
    const result = {
      source: "broadcast",
      batchId: data.batchId,
      totalFiles: data.totalFiles,
      completedFiles,
      failedFiles,
      status: kind === "partial" ? "partially_completed" : "completed"
    };
    cleanup();
    if (kind === "partial") {
      onPartiallyCompleted?.(result);
    } else {
      onComplete?.(result);
    }
  };
  const resolvePolling = (response) => {
    if (resolved) {
      return;
    }
    resolved = true;
    const result = toResult("polling", response);
    cleanup();
    if (response.status === "partially_completed") {
      onPartiallyCompleted?.(result);
    } else {
      onComplete?.(result);
    }
  };
  const failFatal = (error) => {
    if (resolved) {
      return;
    }
    resolved = true;
    cleanup();
    onError?.(error, true);
  };
  const poll = async () => {
    if (resolved) {
      return;
    }
    abortController = new AbortController();
    try {
      const response = await fetch(url, {
        method: "GET",
        credentials: withCredentials ? "include" : "same-origin",
        headers: buildHeaders(headers),
        signal: abortController.signal
      });
      if (!response.ok) {
        if (response.status === 401 || response.status === 403 || response.status === 404) {
          failFatal(new Error(`Batch ${batchId} request failed: HTTP ${response.status}`));
          return;
        }
        throw new Error(`Batch status request failed: HTTP ${response.status}`);
      }
      const body = await response.json();
      if (isTerminalStatus(body.status)) {
        resolvePolling(body);
        return;
      }
      if (extendTimeoutOnProgressMs > 0) {
        const processed = body.completed_files + body.failed_files;
        if (processed > lastProcessedCount) {
          lastProcessedCount = processed;
          if (timeoutTimer) {
            clearTimeout(timeoutTimer);
          }
          timeoutTimer = setTimeout(() => {
            if (resolved) {
              return;
            }
            resolved = true;
            cleanup();
            onTimeout?.();
          }, extendTimeoutOnProgressMs);
        }
      }
    } catch (err) {
      if (err.name === "AbortError") {
        return;
      }
      onError?.(err instanceof Error ? err : new Error("Polling failed"), false);
    }
    if (resolved) {
      return;
    }
    pollTimer = setTimeout(poll, currentPollIntervalMs);
    currentPollIntervalMs = Math.min(
      pollMaxIntervalMs,
      Math.round(currentPollIntervalMs * pollBackoffFactor)
    );
  };
  const startPollNow = () => {
    if (pollStartTimer) {
      clearTimeout(pollStartTimer);
      pollStartTimer = null;
    }
    if (resolved) {
      return;
    }
    void poll();
  };
  if (echo) {
    echoCleanup = listenForBatchComplete(
      echo,
      batchId,
      {
        onComplete: (data) => resolveBroadcast("complete", data),
        onPartiallyCompleted: (data) => resolveBroadcast("partial", data),
        onSubscribed: () => {
          echoSubscribed = true;
          if (pollStartTimer) {
            clearTimeout(pollStartTimer);
            pollStartTimer = null;
          }
          onSubscribed?.();
        },
        onSubscribeError: (err) => {
          startPollNow();
          onSubscribeError?.(err);
        }
      },
      channelPrefix
    );
  }
  pollStartTimer = setTimeout(() => {
    pollStartTimer = null;
    if (echoSubscribed) {
      return;
    }
    void poll();
  }, pollStartDelayMs);
  if (timeoutMs > 0) {
    timeoutTimer = setTimeout(() => {
      if (resolved) {
        return;
      }
      resolved = true;
      cleanup();
      onTimeout?.();
    }, timeoutMs);
  }
  return cleanup;
}
//# sourceMappingURL=index.cjs.map
