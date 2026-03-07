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
  constructor(options = {}) {
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
    this.maxConcurrent = options.maxConcurrent ?? 3;
    this.autoRetry = options.autoRetry ?? true;
    this.maxRetries = options.maxRetries ?? 3;
    this.headers = options.headers ?? {};
    this.withCredentials = options.withCredentials ?? true;
    this.context = options.context;
    this.chunkSizeOverride = options.chunkSize;
    this.endpoints = { ...DEFAULT_ENDPOINTS, ...options.endpoints };
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
  getHeaders() {
    return {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
      ...this.headers
    };
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
    const checksum = await computeChecksum(chunkBlob);
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
      return;
    }
    this.isPaused = false;
    this.emitStateChange();
    this.upload(this.lastFile, this.lastMetadata);
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
    if (!this.lastFile) {
      return;
    }
    this.error = null;
    this.emitStateChange();
    this.upload(this.lastFile, this.lastMetadata);
  }
  destroy() {
    this.abortController?.abort();
    this.listeners.clear();
  }
};
export {
  ChunkUploader
};
