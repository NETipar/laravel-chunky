// src/internal/EventEmitter.ts
var EventEmitter = class {
  constructor() {
    this.listeners = /* @__PURE__ */ new Map();
    this.sticky = /* @__PURE__ */ new Map();
  }
  on(event, callback) {
    if (!this.listeners.has(event)) {
      this.listeners.set(event, /* @__PURE__ */ new Set());
    }
    const set = this.listeners.get(event);
    const stored = callback;
    set.add(stored);
    if (this.sticky.has(event)) {
      const payload = this.sticky.get(event);
      queueMicrotask(() => {
        if (set.has(stored)) {
          callback(payload);
        }
      });
    }
    return () => {
      set.delete(stored);
    };
  }
  emit(event, payload) {
    this.listeners.get(event)?.forEach((cb) => cb(payload));
  }
  setSticky(event, payload) {
    this.sticky.set(event, payload);
  }
  clearSticky(event) {
    if (event === void 0) {
      this.sticky.clear();
      return;
    }
    this.sticky.delete(event);
  }
  clear() {
    this.listeners.clear();
    this.sticky.clear();
  }
};

// src/types.ts
var UploadHttpError = class extends Error {
  constructor(status, body, message) {
    super(message);
    this.name = "UploadHttpError";
    this.status = status;
    this.body = body;
  }
};

// src/internal/RetryPolicy.ts
var DEFAULT_FATAL_STATUSES = /* @__PURE__ */ new Set([400, 401, 403, 404, 410, 413, 415, 422]);
var RetryPolicy = class {
  constructor(autoRetry, maxRetries, fatalStatuses = DEFAULT_FATAL_STATUSES) {
    this.autoRetry = autoRetry;
    this.maxRetries = maxRetries;
    this.fatalStatuses = fatalStatuses;
  }
  decide(error, context) {
    if (context.retriesLeft <= 0) {
      return { retry: false };
    }
    if (typeof this.autoRetry === "function") {
      const err = error instanceof Error ? error : new Error(String(error));
      if (!this.autoRetry(err, { chunkIndex: context.chunkIndex, retriesLeft: context.retriesLeft })) {
        return { retry: false };
      }
    } else if (this.autoRetry === false) {
      return { retry: false };
    } else if (error instanceof UploadHttpError && this.fatalStatuses.has(error.status)) {
      return { retry: false };
    }
    return { retry: true, delayMs: this.computeDelay(context.retriesLeft) };
  }
  /**
   * AWS-recommended "full jitter": uniformly-distributed wait in
   * [0, cap], where cap doubles every attempt. Different from the
   * naive "base + small jitter" that doesn't actually de-synchronise
   * many parallel workers.
   */
  computeDelay(retriesLeft) {
    const cap = Math.pow(2, this.maxRetries - retriesLeft) * 1e3;
    return Math.random() * cap;
  }
};
export {
  DEFAULT_FATAL_STATUSES,
  EventEmitter,
  RetryPolicy
};
//# sourceMappingURL=tools.js.map
