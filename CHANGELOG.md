# Changelog

All notable changes to `netipar/laravel-chunky` will be documented in this file.

## v0.13.1 - 2026-05-01

Test-coverage and operational hardening pass on top of v0.13.0. No new public API; one new boot-time guard that fails fast on a misconfigured Cache lock driver, and 19 new tests covering the v0.13.0 features.

### Added
- **Boot-time guard for `chunky.lock_driver = 'cache'`.** Throws `RuntimeException` at service-provider boot if `cache.default` is `array` or `file` — both drivers silently no-op on `Cache::lock()` and would let upload races slip through unnoticed. Switch to Redis / Memcached / DB / DynamoDB, or set `chunky.lock_driver` back to `flock`.
- **Stale-claim recovery integration tests** (`AssembleClaimTest`): simulate a worker crash mid-assembly, verify a retry can take over the claim, run to `Completed`, and emit `UploadCompleted`. Also covers `expiredUploadIds()` skipping fresh `Assembling` rows but including stale ones — the cleanup-doesn't-leak invariant.
- **`FilesystemTracker` boot-guard tests**: local disk boots fine; non-local disk + `flock` mode throws with a helpful message; non-local disk + `cache` mode boots fine; `skip_local_disk_guard = true` escape hatch works.
- **Broadcast payload sanitisation tests**: `UploadCompleted` and `UploadFailed` strip `disk` / `finalPath` by default; opt-in via `chunky.broadcasting.expose_internal_paths = true` puts them back. Locks the v0.13.0 wire-format change.
- **Path-traversal tests** (`PathTraversalTest`): the assembler's `basename()` defence-in-depth strips leading directories from hostile file names, and rejects dot-only names that collapse to empty. The `simple()` context save callback applies the same guard end-to-end (verified by inspecting where the moved file actually lands on a fake disk).
- **`LockDriverCompatTest`** covers the four cache.default × lock_driver combinations relevant to the new boot guard.

### npm packages
- All packages bumped to `0.13.1` (no source changes in the JS packages — re-publish for version-sync consistency with the PHP release).

## v0.13.0 - 2026-05-01

This release applies the remaining items from the v0.12.0 deep review (cross-cutting concerns + nits) plus a few new findings from the implementation pass. The headline change is **opt-in support for cloud disks** in the FilesystemTracker via Cache-backed locks, alongside built-in idempotency for chunk POSTs and a metrics-callback surface for observability integrations.

### Added
- **`chunky.lock_driver = 'cache'`** — Cache::lock-backed locking for `FilesystemTracker` mutations and the batch counter. Required when running against S3, GCS, or any non-local Flysystem disk; works with any Laravel cache driver that supports atomic locks (Redis, Memcached, DB, DynamoDB). Defaults to `flock` for backward compatibility. Tunable via `chunky.lock_ttl_seconds` (30) and `chunky.lock_wait_seconds` (5).
- **Idempotent chunk POSTs.** The `POST /upload/{uploadId}/chunks` endpoint now caches its response by `(uploadId, chunkIndex, Idempotency-Key OR checksum)` for `chunky.idempotency_ttl_seconds` (default 300). A network retry of a chunk the server already accepted replays the cached payload byte-for-byte instead of double-firing `ChunkUploaded` events and double-dispatching `AssembleFileJob`. The frontend `ChunkUploader` automatically attaches an `Idempotency-Key` header (`{uploadId}:{chunkIndex}`).
- **Observability hooks (`chunky.metrics`).** Five lifecycle events fire through `NETipar\Chunky\Support\Metrics::emit()`: `chunk_uploaded`, `chunk_upload_failed`, `assembly_started`, `assembly_completed`, `assembly_failed`. Wire any of them to Datadog / Prometheus / StatsD via a callable in config. Callback exceptions are swallowed so an observability bug cannot break the upload pipeline.
- **`UploadStatus::isTerminal()` and `BatchStatus::isTerminal()`** helpers, replacing 5+ inline `in_array($status, [Completed, Failed, …])` lists across the codebase. Single source of truth for "is this state final?" semantics.
- **`chunky.staging_directory`** config — local filesystem directory used while assembling chunks. Defaults to `sys_get_temp_dir()`. Set to a path on a volume with enough free space when accepting uploads larger than your `/tmp` partition (cloud-disk targets buffer the full file locally before upload).
- **`chunky.metadata.max_keys`** config (default 50) — bounded user-supplied metadata, so a misbehaving client can't balloon DB rows or broadcast payloads with megabyte-sized metadata blobs.
- **`chunky.broadcasting.expose_internal_paths`** — opt-in flag to include the storage `disk` and absolute `finalPath` in the `UploadCompleted`/`UploadFailed` broadcast payloads. Defaults to `false` so server-internal paths don't leak over WebSocket. The Livewire component honours the same flag.
- **`CompletionWatcher` poll backoff and progress-aware timeout.** New options: `pollMaxIntervalMs` (default 30000), `pollBackoffFactor` (default 1.5), and `extendTimeoutOnProgressMs` (default 0 = static deadline). The poll cadence now grows over time so a long-running batch doesn't hammer the status endpoint at the initial cadence; with `extendTimeoutOnProgressMs > 0`, observed progress extends the wall-clock deadline.
- **`useBatchCompletion` (Vue 3) `debounceMs`** option (default 50) — protects against `batchId` flapping on rapid route param changes, which would otherwise teardown/setup an Echo subscription on every tick.
- **React `useChunkUpload` / `useBatchUpload` and Alpine wrappers expose `destroy()`** for explicit teardown. Brings parity with the Vue 3 wrappers shipped in v0.12.0.

### Fixed
- **`ChunkUploader.upload()` no longer falls back to a 1MB chunk size silently.** A missing `chunk_size` from the server (and no client override) now throws — guessing the slice size produces silently corrupted output if the guess disagrees with the backend.
- **`ChunkUploader.uploadChunks()` is O(N) instead of O(N²) on the pending list.** A `Set` replaces the previous `Array.filter()` rebuild on every chunk completion. For a 10000-chunk file that's ~50M ops saved.
- **`BatchUploader.aggregateProgress()`** uses a `for` loop instead of `Array.reduce` — same time complexity, less closure overhead per progress event.
- **`CompletionWatcher` no longer hammers a 401/403/404 endpoint.** All three status codes are now treated as fatal alongside 404 (added in v0.12.0); previously 401/403 looped at 2-second intervals until the wall-clock timeout.
- **`ChunkyContext::name()` empty string is rejected** at registration time rather than producing a silently broken `$contexts['']` entry.
- **`simple()` save-callback** applies a defence-in-depth `basename()` to the destination, mirroring the assembler's path-traversal guard.
- **`Livewire/ChunkUpload::completeUpload()`** now performs an Authorizer ownership check on the upload before broadcasting the completion event — closes the same IDOR surface that v0.12.0 closed for the HTTP layer.

### Changed
- **`UploadCompleted` and `UploadFailed` broadcast payloads** no longer include `disk` or `finalPath` by default. Set `chunky.broadcasting.expose_internal_paths = true` to opt back in if a consumer depends on these.
- **`Models\ChunkedUpload::markChunkUploaded()`** dropped the unused `?string $checksum` parameter (already nullable; never persisted).
- **Vue 3 wrapper `onBeforeUnmount`** lifecycle hooks are now `onScopeDispose` everywhere (already partially done in v0.12.0; the rest converted in this release).

### npm packages
- All packages bumped to `0.13.0` (core, vue3, react, alpine).

### Migration notes
- The new `chunky.broadcasting.expose_internal_paths` default of `false` is technically a wire-format change for `UploadCompleted` / `UploadFailed`. Frontends that read `event.finalPath` or `event.disk` from broadcast payloads need to either set the flag back to `true` in `config/chunky.php` or fetch those fields from `GET /api/chunky/upload/{uploadId}` server-side instead.
- Switching to `chunky.lock_driver = 'cache'` requires a Laravel cache driver that supports `Cache::lock()`. `array` and `file` drivers do not — use Redis, Memcached, DB, or DynamoDB.

## v0.12.0 - 2026-05-01

This release is a substantial hardening pass driven by an end-to-end review of the package: race conditions, security-sensitive paths, fault tolerance, and frontend ergonomics. Highlights below; more detail in the per-section entries.

### Security (Breaking-ish)
- **Path traversal hardened.** `file_name` validation now rejects `/`, `\`, NUL, Windows reserved characters, and `.` / `..` outright (regex on `InitiateUploadRequest` and `InitiateBatchUploadRequest`). The `DefaultChunkHandler::assemble()` additionally applies a defence-in-depth `basename()` and refuses empty / dot names. Existing valid file names are unaffected; previously-accepted malicious names like `../../public/.htaccess` now fail validation with 422.
- **Per-upload / per-batch authorisation.** Introduces an `Authorizer` interface (`NETipar\Chunky\Authorization\Authorizer`) bound by default to `DefaultAuthorizer`, which enforces `auth()->id() === upload->userId` whenever the upload/batch was created with an owner. Anonymous uploads (no `user_id`) keep the old "anyone with the id can use it" semantics for backward compatibility, but as soon as auth middleware is in place, IDOR is blocked. `UploadChunkRequest::authorize()`, `InitiateBatchUploadRequest::authorize()`, and `UploadStatusController` / `BatchStatusController` / `CancelUploadController` all enforce this; non-owners see a 404 (not 403) so upload IDs aren't leaked through error response timing.
- **Broadcast channel auth callbacks shipped.** New `routes/channels.php` registered automatically when broadcasting is enabled; the upload, batch, and user channels delegate to the same `Authorizer` so HTTP and WebSocket access stay in sync. Disable with `chunky.broadcasting.register_channels = false` if you prefer to register your own.
- **Status response sanitisation.** The public `GET /upload/{uploadId}` response no longer includes the storage `disk`, the absolute `final_path`, or the owner `user_id`. Use `UploadMetadata::toArray()` server-side if you need them. New `UploadMetadata::toPublicArray()` helper exposes the trimmed payload.
- **Mass-assignment hardening.** Both `ChunkedUpload` and `ChunkyBatch` now define explicit `$fillable` arrays instead of `$guarded = []`.

### Added
- **`BatchUploader.enqueue()`** in `@netipar/chunky-core`. Same signature as `upload()` but doesn't throw `Batch upload already in progress.` if a batch is already running — the files are held in an internal queue and run as their own batch when the current one finishes (success or failure). When no batch is active, `enqueue()` immediately delegates to `upload()`, so callers can use it as a drop-in replacement that "just keeps working" across overlapping calls. The returned promise resolves with that batch's `BatchResult`, or rejects with `Batch upload cancelled before queued upload could start.` / `BatchUploader destroyed before queued upload could start.` if `cancel()` / `destroy()` runs before the queued batch starts — so callers don't leak hanging promises. `useBatchUpload` in both the Vue 3 and React wrappers exposes the matching `enqueue` method. Strict `upload()` keeps its existing behaviour for callers that want to detect overlap.
- **`onFileProgress` in the React `useBatchUpload` hook** and a matching `chunky:batch-file-progress` DOM event on the Alpine `batchUpload` data component. Now exposed in all three wrappers — Vue 3, React, Alpine.
- **`Authorizer` and `DefaultAuthorizer` services** (`NETipar\Chunky\Authorization\*`). Bind a custom implementation to override ownership rules (admin overrides, shared batches, etc.).
- **`AssembleFileJob` retry support.** `tries = 3` with `backoff = 30s`, plus stale-claim recovery: if a worker crashed mid-assembly, a subsequent retry can re-claim the upload after `chunky.assembly_stale_after_minutes` (default 10).
- **`BatchMetadata::successProgress()`** alongside `progress()`, for callers that want the success-only view.
- **Vue 3 composables now expose an explicit `destroy()`** method, so they remain safe to use outside a component scope (Pinia stores, plain modules).
- **`chunky.max_files_per_batch` config** (default 1000) — DOS protection on the batch initiate endpoint.

### Fixed
- **`AssembleFileJob` "stuck in Assembling" recovery.** Previously, if a worker crashed between `assemble()` and `updateStatus(Completed)`, the upload was wedged in `Assembling` forever: `claimForAssembly()` only allowed `Pending → Assembling`, and `chunky:cleanup` skipped `Assembling` rows. Now both trackers (DB and FS) treat an `Assembling` claim older than `assembly_stale_after_minutes` as recoverable — a queue retry will take it over and re-run the job. The cleanup command applies the same window so abandoned assemblies don't leak storage forever.
- **`AssembleFileJob` cleanup ordering.** The chunk `cleanup()` call moved from before the save callback to *after* `updateStatus(Completed)`, so a save-callback failure or worker crash mid-flight no longer leaves us with no chunks AND no completed file — a retry can recover.
- **`AssembleFileJob::failed()` no longer flips `Completed → Failed`.** Previously, if `handle()` succeeded but the queue retried it for an unrelated reason (post-Completed `dispatch` failure, broker hiccup), `failed()` would overwrite the upload status with `Failed`, double-increment the batch's failure counter, and broadcast a contradictory `UploadFailed` event. The early-return now matches *any* terminal state, not just `Failed`.
- **Tracker `markChunkUploaded()` rejects late chunks.** Both the DB and FS trackers now refuse chunk POSTs against uploads that aren't in `Pending` (cancelled, completed, assembling, failed, expired). The HTTP layer surfaces this as 409 Conflict. A pre-flight check in `ChunkyManager::uploadChunk()` short-circuits before the chunk hits disk, so cancel-races no longer leave orphan chunk files behind.
- **`BatchUploader` cancel-during-finalise race.** If `cancel()` ran in the very last tick of an `upload()` (after all worker promises resolved but before the success path emitted `complete`), both `cancel` and `complete` events fired for the same batch, contradicting each other. The success path now checks the cancel/abort flag and returns the partial result silently.
- **`BatchUploader` cancel suppresses redundant `error` event.** A user-driven `cancel()` would surface the resulting `AbortError` as an `error` event in addition to `cancel`, so UI components showed both a "cancelled" indicator and an "error" toast for the same action. After `cancel()`, the `catch` branch tears down in-flight per-file uploaders and rethrows without re-emitting `error`.
- **`BatchUploader` exception path tears down per-file uploaders.** A failure in `initiateBatch` (or any pre-loop step) used to leave the per-file `ChunkUploader` workers running in the background, continuing to POST chunks against an uploader the caller had already given up on. The `catch` branch now calls `cancel()` on each before re-emitting `error`.
- **`ChunkUploader.upload()` no longer corrupts a new upload by reusing a stale `uploadId`.** Previously, calling `upload(B)` after a failed `upload(A)` (without `cancel()`) would resume the A upload on the server with B's chunk bytes, producing a hybrid file under A's id. The reuse branch now requires `lastFile === file` referential equality; otherwise it falls through to a fresh initiate.
- **`CompletionWatcher` treats 401/403 as fatal.** Previously only 404 stopped polling; auth failures looped at 2-second intervals until the wall-clock timeout. They now invoke `onError(err, true)` and stop.
- **`CompletionWatcher` skips polling once Echo subscribed.** When the Echo subscription confirms, the deferred poll start is cancelled — saves one HTTP request per active wait, which adds up on dashboards. If the subscribe fails, polling kicks off immediately instead of waiting out the start delay.
- **`BatchUploader.enqueue()` race when cancelling the active batch.** `drainQueue()` used to `shift()` the next queued batch before the deferring microtask ran. If `cancel()` (or `destroy()`) ran in the gap — for example from a `catch` handler attached to the previous batch's rejection — the queued promise was rejected via `rejectPendingQueue()`, but the microtask had already captured the shifted entry and started a fresh upload anyway. The shift now happens inside the microtask and re-checks `isUploading` / queue length.
- **`BatchUploader.destroy()` rejection reason.** `destroy()` called `cancel()` first and then `rejectPendingQueue('… destroyed …')`. Since `cancel()` already drains the queue under the generic "cancelled" reason, the destroy-specific reject became a silent no-op. Reordered so the destroy reason is applied first.
- **`BatchMetadata::progress()` now includes failed files.** Previously a 4-file batch with 3 successes and 1 failure reported 75% progress while the batch was actually done; now it reports 100%, and the success-only view is available via `successProgress()`.
- **`InitiateBatchUploadRequest` validation uses the *batch's* context, not the request's.** Closes a validation-bypass where a caller could declare `context: documents` (max 50MB) in the request while the batch was created with `context: photos` (max 5MB) — the request validated against documents, but the save callback ran under photos. The request also rejects new uploads against terminal batches (Completed / PartiallyCompleted / Expired) at the validation layer.
- **`validateBatchExists` rejects terminal batches.** A `Completed` / `PartiallyCompleted` / `Expired` batch can no longer accept further `initiateInBatch` calls — previously it silently grew past `total_files`, leaving the counters inconsistent.
- **Retry jitter on chunk failures.** Exponential backoff now includes up to 250ms of random jitter so N parallel workers don't retry in lockstep and overwhelm a struggling server.

### Changed (Breaking)
- **`FilesystemTracker` refuses to boot on non-local disks.** The tracker's `flock()`-based mutation paths only work when the configured `chunky.disk` exposes a real local path. Booting against an S3/GCS-style disk previously fell back silently to lock-free writes — every chunk-write/claim was a lost-update race. Boot-time check now throws `ChunkyException` with a clear message; switch `chunky.tracker` to `database`, or set `chunky.skip_local_disk_guard = true` (for advanced users with external locking) to bypass.
- **`UploadStatusController` JSON response no longer includes `disk`, `final_path`, `user_id`.** See Security above.
- **`Models\ChunkedUpload::markChunkUploaded()` signature** dropped its unused `?string $checksum` parameter. The tracker contract `UploadTracker::markChunkUploaded()` still accepts it for forward compatibility.
- **`UploadChunkController`** maps `ChunkyException` to 409 Conflict and `UploadExpiredException` to 410 Gone.
- **`AssembleFileJob` is now retryable.** Custom queue configurations that assumed a single attempt may need to be aware of `tries=3, backoff=30`. Override at dispatch time if needed.

### npm packages
- All packages bumped to `0.12.0` (core, vue3, react, alpine).
- Vue 3 wrappers switched from `getCurrentInstance() + onBeforeUnmount` to `getCurrentScope() + onScopeDispose`. This preserves component-scoped behaviour but additionally cleans up uploader instances when used inside Pinia stores or `effectScope` blocks. Composables also expose an explicit `destroy()` for manual teardown.

## v0.11.0 - 2026-05-01

This release smooths out batch progress reporting and ships a new `CompletionWatcher` API that lets the frontend wait for batch completion via Echo broadcasts with a polling fallback — the same primitive `useBatchCompletion` is built on. No PHP changes; this is a frontend-only release.

### Fixed
- **Batch progress no longer jitters between files.** `BatchUploader` used to assign `this.progress` a per-step value (`(filesCompleted / total) * 100`) on every `fileComplete`, which clobbered the smooth, chunk-driven progress that the worker loop had been interpolating into it. The result was a UI bar that crept up smoothly during a file, then snapped to a discrete step value at the boundary, then crept again. Removes the step assignment so `progress` advances continuously across the whole batch as chunks finish, regardless of file count.
- **`CompletionWatcher` keeps polling on transient errors.** The watcher's `onError` callback used to fire with a single `(error)` argument and the consumer had no way to distinguish a one-off network blip from a fatal subscription failure. Signature is now `(error, isFatal)` — the watcher itself only flags `isFatal=true` when the Echo subscription gives up; transient HTTP failures during polling no longer cancel the watch. `useBatchCompletion` mirrors this and only flips `isWaiting` to `false` on the fatal branch, so the composable stays in the "waiting" state through retryable errors.

### Added
- **`CompletionWatcher` and `watchBatchCompletion()`** in `@netipar/chunky-core`. A broadcast-or-poll primitive: subscribes to the batch's private channel via Echo and resolves on `BatchCompleted` / `BatchPartiallyCompleted`, with a status-endpoint poll as a fallback when Echo is unavailable or slow to subscribe. Surfaces lifecycle through `onComplete`, `onPartiallyCompleted`, `onError(error, isFatal)`, plus the `cancel()` returned from `watchBatchCompletion`. Useful when the upload finishes on one tab/process and the UI that needs to react to it lives somewhere else.
- **`useBatchCompletion` Vue 3 composable** in `@netipar/chunky-vue3`. Wraps `CompletionWatcher` with reactive `isWaiting`, `isComplete`, `result`, and `error` refs and an auto-cancel on unmount. Mirrors the new fatal/transient error semantics — the composable stays in the waiting state through transient failures.
- **`fileProgress` event on `BatchUploader`** (`{ batchId, uploadId, file, progress, fileIndex }`) emitted on every chunk progress tick, so consumers can drive a per-file progress bar without subscribing to the active uploader. `useBatchUpload` exposes the matching `onFileProgress` callback.
- **Continuous batch progress.** `BatchUploader.progress` now interpolates across the whole batch (file boundary smoothing baked into the chunk-driven progress emission) — see the matching Fixed entry above.
- **Echo subscription lifecycle hooks.** `listenForUser` and `listenForBatchComplete` accept optional `onSubscribed` / `onSubscribeError` callbacks that route to the underlying channel's `subscribed()` / `error()` hooks when available. `EchoChannel`'s `subscribed?` and `error?` hooks are now part of the type, with `error` callback typed as `(err: unknown)` instead of `any`.

### Changed
- **`FileProgressEvent.uploadId` is now `string`** (was `string | null`). The id is always set by the time a `fileProgress` event fires.
- **`EchoChannel.error?` callback parameter is `unknown`** (was `any`). Preserves the runtime contract while removing the implicit-any escape hatch for consumers.

### Refactored
- **Shared `http.ts` util** in `@netipar/chunky-core` consolidates CSRF token discovery and request-header construction. Three call sites (`BatchUploader`, `ChunkUploader`, `CompletionWatcher`) now share one implementation instead of each rolling their own.

### npm packages
- All packages bumped to `0.11.0` (core, vue3, react, alpine). React and Alpine carry no source changes; they re-publish for version-sync consistency. Sister packages continue to require `@netipar/chunky-core` via `workspace:^`, which resolves to `^0.11.0` on publish.

### Migration notes (none required)
- The `CompletionWatcher.onError` signature change `(error)` → `(error, isFatal)` is on a brand-new, previously-unpublished API; no existing consumer is affected. The `FileProgressEvent.uploadId` and `EchoChannel.error?` type tightenings are non-breaking on the JS runtime — they only fail TypeScript builds that were relying on the looser types, and in both cases the looser types were unsound in practice.

## v0.10.0 - 2026-05-01

This release fixes the long-standing "the file uploaded but the UI shows it failed" symptom on large uploads, plus a related cluster of race conditions and a couple of long-overdue ergonomics gaps. There are intentional contract changes — see **Breaking changes** at the bottom.

### Fixed
- **Concurrent `AssembleFileJob` runs no longer corrupt each other.** `UploadChunkController` dispatches the assembly job whenever the tracker reports `is_complete=true`. The v0.9.3 `lockForUpdate` only protected the chunk-list write, not the subsequent `isComplete` read, so two parallel chunk requests near the end of a large upload could each observe completion and each enqueue a job. The first job assembled the file and ran `cleanup()`; the second job crashed mid-`assemble()` because the chunks were gone, dispatched `UploadFailed`, and the user saw a failure even though the file was on disk. Adds `UploadTracker::claimForAssembly()` (CAS update from `Pending` to `Assembling` on the database tracker, status-guarded write under `flock` on the filesystem tracker), and `AssembleFileJob` returns silently when another worker already won the claim.
- **Concurrent client-side workers stop on the first `is_complete` response.** When the backend reported `is_complete=true`, only the worker that received the response returned — the other workers continued POSTing their already-in-flight chunks, which fed the server-side race above. A shared `completed` flag now bails out the remaining workers before the next request.
- **`DefaultChunkHandler::assemble()` now works on every Flysystem driver.** The previous implementation called `$disk->path()` and used `fopen()`/`mkdir()` directly, which only works on the local driver — S3, GCS, and friends raised a `RuntimeException` for every assembly and the chunks were never cleaned up. Streams chunk-by-chunk through `readStream()` into a `sys_get_temp_dir()` temp file, then uploads with `writeStream()`. Memory stays at the 8 KB read buffer regardless of file size, and the temp file is unlinked even if an error is thrown. Missing chunks now raise a descriptive `RuntimeException` with the chunk index instead of a warning-level `fopen()` failure.
- **`ChunkUploader` resets its internal state after a successful upload.** When the same instance was reused for a second file (the default for `ChunkDropzone`, `useChunkUpload`, and any UI that holds a single uploader reference), the leftover `uploadId` from the previous run made `upload()` enter the resume branch, hit `/status` with the stale id, and either upload nothing or throw. Clears `uploadId`, `pendingChunks`, `lastFile`, and `lastMetadata` when emitting the `complete` event. `isComplete`, `progress`, and `currentFile` are intentionally preserved so the UI can still display the finished file.
- **Late listeners receive a sticky `complete` / `error` replay.** Both `ChunkUploader` and `BatchUploader` fired `complete` and `error` synchronously, so any listener that registered after the upload finished — for example because the parent component mounted while the upload was in flight — never received the event. `on('complete', cb)` and `on('error', cb)` now schedule the callback in a microtask if the event has already happened. The cache is cleared on the next `upload()` and on `cancel()`.
- **`pause()`/`resume()`/`retry()` no longer leak unhandled promise rejections.** The fire-and-forget `this.upload(...)` call inside `resume()` and `retry()` had no `.catch()`, so a network failure surfaced as an `UnhandledPromiseRejection` in browser devtools. The error itself was already delivered through the `error` event, so we just swallow the rejection.
- **Per-chunk N+1 query is gone.** `ChunkyManager::uploadChunk()` previously called `markChunkUploaded` + `getMetadata` + `isComplete` — three reads per chunk, on top of the chunk write. For a 1000-chunk upload that was 3000+ DB queries, and the unlocked `isComplete` read was part of the assembly race above. `markChunkUploaded()` now returns the freshly updated `UploadMetadata` from inside the `lockForUpdate` transaction; `uploadChunk()` consumes that one snapshot.
- **`VerifyChunkIntegrity` middleware and `DefaultChunkHandler::store()` no longer buffer the chunk twice into memory.** Each chunk request used to allocate `2 × chunk_size` of PHP heap (one read for the SHA-256, one for the disk write). The middleware now `hash_file()`s the upload's temp path; the handler streams it via `writeStream()`. Both fall back to `getContent()` when no temp file is available.
- **`BatchUploader.pause()` actually pauses the batch worker loop.** It used to pause only the active per-file uploaders; the outer worker loop kept pulling files from the queue and starting fresh uploaders. Adds a Promise-based barrier the loop awaits between files when `isPausedBatch` is true.
- **`BatchUploader.cancel()` resets `isComplete` and emits a dedicated `cancel` event** (`{ batchId }`). Previously a cancel after the last `fileComplete` left `isComplete=true`, and consumers had no way to tell apart "user cancelled" from "upload finished". The sticky event cache is also cleared so a late listener cannot replay a stale event after the user cancelled.
- **`BatchUploader.fetchJson` captures the `AbortSignal` locally.** It used to read `this.abortController?.signal` at await-time, which could attach a request to a freshly-replaced controller in destroy/cancel flows.
- **HTTP errors preserve the response body.** Both `fetchJson` paths used to collapse non-2xx responses into `new Error('HTTP {status}: {body}')`, hiding Laravel validation arrays behind an opaque string. They now throw `UploadHttpError` with `status` and parsed `body` fields. Existing `error.message` consumers keep working.
- **`FilesystemTracker` mutations are guarded by `flock()`.** v0.9.3 fixed the `DatabaseTracker` race; the filesystem tracker still did a bare read-modify-write on `metadata.json`, which dropped chunk indices under concurrent writes. `markChunkUploaded`, `updateStatus`, and `claimForAssembly` now run under an exclusive lock on a sibling `.lock` file. The guard is best-effort: when the disk does not expose a local path (S3, etc.) the callback runs unguarded — that combination was already unsupported.
- **Batch completion broadcasts deduplicate.** When several `AssembleFileJob` workers finished within the same tick, each one persisted the terminal status and dispatched a `BatchCompleted` event, so the frontend received N notifications for one logical transition. The DB path now uses a CAS UPDATE that only matches non-terminal statuses; the filesystem path runs its check under the new batch flock.

### Added
- **`DELETE /api/chunky/upload/{uploadId}` cancel endpoint** (`CancelUploadController`). The frontend `ChunkUploader.cancel()` now fires a background `DELETE` against it so the chunks are released immediately instead of waiting for the expiration sweep.
- **`UploadStatus::Cancelled` enum case.**
- **`chunky:cleanup` Artisan command.** Removes expired uploads (chunk files + tracker metadata) for both database and filesystem trackers. Supports `--dry-run`. The previously-orphaned `auto_cleanup` config option is now respected — when `true`, the service provider schedules the command daily with `withoutOverlapping()`.
- **`UploadHttpError` exported from `@netipar/chunky-core`** with `status` + parsed `body` for granular client error handling.
- **`BatchCancelEvent` event** on `BatchUploader` and corresponding type export.
- `chunk_index` validation now rejects values above the upload's `total_chunks` (resolved through the tracker).

### Changed (Breaking)
- **`UploadTracker` contract.** Custom tracker implementations must update:
  - `markChunkUploaded()` returns `UploadMetadata` instead of `void` (the freshly updated snapshot from inside the lock).
  - New required methods: `claimForAssembly(string $uploadId): bool`, `expiredUploadIds(): array<int, string>`, `forget(string $uploadId): void`.
- **New `UploadStatus::Cancelled` case.** Any consumer doing an exhaustive `match` on `UploadStatus` needs to add a branch.
- **New `DELETE /api/chunky/upload/{uploadId}` route.** If you publish and customise `routes/api.php`, re-run `php artisan vendor:publish --tag=chunky-routes` (if you publish them) or merge the new route manually.

### npm packages
- All packages bumped to `0.10.0` (core, vue3, react, alpine). Sister packages now require `@netipar/chunky-core: ^0.10.0`.

## v0.9.4 - 2026-04-24

### Fixed
- **`BatchProgressEvent.currentFile.progress` was stuck at 0% until a file finished uploading.** `BatchUploader.emitProgress()` populated `currentFile.progress` from `this.progress`, which is the batch-level percentage — that value only advances when a whole file completes. So every chunk-progress tick emitted an event claiming the in-flight file was at 0% until the final chunk flipped it to 100%. Frontends that bind a per-file progress bar to `currentFile.progress` (including the Alpine and Vue composables shipped with this package) showed a frozen 0% bar for the entire duration of each upload. Fix plumbs the active `ChunkUploader` through to `emitProgress(uploader)` so `currentFile` carries the uploader's real per-file `progress` and `currentFile.name`. The post-file-completion `emitProgress()` call inside the worker loop now emits `currentFile: null` (instead of the stale previous filename with a bogus `progress: 0`), which also prevents a 100% → 0% UI flash between files in concurrent batches.

### npm packages
- All packages bumped to v0.9.4 (core carries the fix; vue3 / react / alpine re-published for version-sync consistency, no source changes there).

## v0.9.3 - 2026-04-24

### Fixed
- **Race condition in `DatabaseTracker::markChunkUploaded()` — concurrent chunk uploads lost writes.** The previous implementation read `uploaded_chunks` from the model, mutated the PHP array in memory, and wrote it back with `update()` — a classic read-modify-write without locking. With the default client-side concurrency of 3 parallel chunk requests, two workers would read the same pre-state, both append their own index, and the second `update()` would clobber the first. Symptom: a file with N chunks would end up stuck at `is_complete: false` with fewer than N indices in `uploaded_chunks`, and the `BatchCompleted` broadcast never fired. Fix wraps the read-mutate-write in `DB::transaction()` + `lockForUpdate()`, so the row is locked for the duration of the update and writes serialize correctly. Works on MySQL, Postgres, and SQLite (SQLite serializes the transaction at the BEGIN level; set `busy_timeout` and `journal_mode=WAL` on the connection if you see `database is locked` errors under load).

### Tests
- `DatabaseTrackerTest` covers the transactional path and asserts that `markChunkUploaded` opens a DB transaction.

## v0.9.2 - 2026-04-14

### Fixed
- `AssembleFileJob` no longer marks an upload as `Completed` before the context save callback runs — if the callback throws, the upload is now correctly marked `Failed` and its batch failure counter is incremented
- Save callback failures previously left uploads in an inconsistent `Completed` state with no failure event

### Added
- New `UploadFailed` broadcast event dispatched on save callback errors and queue job failures (carries `uploadId`, `disk`, `fileName`, `fileSize`, `context`, `reason`)
- `failed()` job callback short-circuits when status is already `Failed` to avoid double-dispatching `UploadFailed` and double-incrementing batch failure counters

### Tests
- Added `AssembleFileJobTest` covering save callback failure paths, batch failure propagation, and `failed()` idempotency

## v0.9.0 - 2026-04-02

### Changed
- **`BatchUploader` always creates a batch**, even for single files — no more special-case single-file path
- Removed `uploadSingle()` internal method — 1 file = batch of 1
- Consistent behavior: every upload gets a `batchId`, every upload fires `BatchCompleted`

### Why
The frontend shouldn't need to decide upfront whether it's a single or multi-file upload. With this change, `useBatchUpload` is the single entry point for all uploads. The only overhead for a single file is one extra HTTP request (batch initiation).

### npm packages
- All packages bumped to v0.9.0 (core, vue3, react, alpine synchronized)

## v0.8.0 - 2026-04-01

### Added — Backend
- `user_id` nullable indexed column on `chunked_uploads` and `chunky_batches` tables
- `ChunkyManager` auto-captures `auth()->id()` on upload and batch initiation
- `UploadMetadata` and `BatchMetadata` DTOs now include `?int $userId` property
- User-scoped broadcast channel: `{prefix}.user.{userId}` — all upload/batch events for the authenticated user on a single channel (no need to know uploadId/batchId)
- `broadcasting.user_channel` config option (default: `true`) to enable user channel broadcasting

### Added — Frontend
- `listenForUser()` core helper — subscribe to all chunky events for a user on one channel
- `useUserEcho()` Vue 3 composable with reactive userId watch and auto-cleanup
- `useUserEcho()` React hook with useEffect cleanup

### Changed
- `BatchCompleted` and `BatchPartiallyCompleted` events now carry `?int $userId`
- `DatabaseTracker` persists `user_id` from `UploadMetadata`

### npm packages
- All packages bumped to v0.8.0 (core, vue3, react, alpine synchronized)

## v0.7.0 - 2026-04-01

### Added — Broadcasting
- **Laravel Echo / Broadcasting support**: optional real-time notifications via private WebSocket channels
- `UploadCompleted`, `BatchCompleted`, `BatchPartiallyCompleted` implement `ShouldBroadcast`
- `broadcastWhen()` guard: zero overhead when broadcasting is disabled (default)
- Configurable channel prefix (`chunky.broadcasting.channel_prefix`) and broadcast queue
- Private channels: `{prefix}.uploads.{uploadId}`, `{prefix}.batches.{batchId}`
- `config/chunky.php` new `broadcasting` section (`enabled`, `channel_prefix`, `queue`)

### Added — Frontend
- Echo helpers: `listenForUploadComplete()`, `listenForBatchComplete()` with typed interfaces
- Vue 3 composables: `useUploadEcho()`, `useBatchEcho()` with auto-cleanup
- React hooks: `useUploadEcho()`, `useBatchEcho()` with useEffect cleanup
- Typed `EchoInstance`, `EchoChannel`, `UploadCompletedData`, `BatchCompletedData`, `BatchPartiallyCompletedData` interfaces

### npm packages
- All packages bumped to v0.7.0 (core, vue3, react, alpine synchronized)

## v0.6.0 - 2026-04-01

### Added — Backend
- **Batch upload support**: group multiple file uploads into a single batch with atomic completion tracking
- `ChunkyBatch` Eloquent model with `chunky_batches` migration (auto-loaded for database tracker)
- `BatchStatus` enum: `Pending`, `Processing`, `Completed`, `PartiallyCompleted`, `Expired`
- `UploadStatus::Failed` case for handling assembly job failures
- `ChunkyManager::initiateBatch()` to create a batch and return `BatchMetadata` DTO
- `ChunkyManager::initiateInBatch()` to add file uploads to an existing batch
- `ChunkyManager::getBatchStatus()` returns typed `BatchMetadata` DTO
- `ChunkyManager::markBatchUploadCompleted()` / `markBatchUploadFailed()` with atomic counters
- `AssembleFileJob::failed()` method — marks upload as `Failed` and updates batch counters on assembly error
- Batch completion fires `BatchCompleted` or `BatchPartiallyCompleted` event (lenient failure policy)
- `BatchInitiated` event dispatched on batch creation
- Three new API routes: `POST /batch`, `POST /batch/{batchId}/upload`, `GET /batch/{batchId}`
- Filesystem tracker: batch metadata stored as `chunky/temp/batches/{batchId}/batch.json`

### Added — Frontend
- `BatchUploader` class in `@netipar/chunky-core` for multi-file batch uploads
- `maxConcurrentFiles` option (default: 2) for parallel file uploads within a batch
- Single-file optimization: `BatchUploader` skips batch creation for 1 file
- `useBatchUpload()` composable for Vue 3 with reactive batch state
- `useBatchUpload()` hook for React with full state management
- `registerBatchUpload()` Alpine.js data component with DOM events (`chunky:batch-progress`, `chunky:batch-complete`, etc.)
- Batch event types: `BatchProgressEvent`, `BatchResult`, `BatchUploaderState`, `BatchUploaderEventMap`

### Changed
- `ChunkyManager::initiate()` now returns `InitiateResult` DTO (was array)
- `ChunkyManager::uploadChunk()` now returns `ChunkUploadResult` DTO (was array)
- `ChunkyManager::initiateBatch()` returns `BatchMetadata` DTO
- `ChunkyManager::initiateInBatch()` returns `InitiateResult` DTO (with `batchId`)
- `ChunkyManager::getBatchStatus()` returns `?BatchMetadata` DTO
- `UploadMetadata` DTO now includes `?string $batchId` property
- `chunked_uploads` migration adds `batch_id` nullable indexed column
- Migrations are always publishable (previously only when `tracker === 'database'`)

### New DTOs (`NETipar\Chunky\Data\`)
- `InitiateResult` — `uploadId`, `chunkSize`, `totalChunks`, `?batchId`
- `ChunkUploadResult` — `isComplete`, `metadata` (UploadMetadata)
- `BatchMetadata` — `batchId`, `totalFiles`, `completedFiles`, `failedFiles`, `status`, `?context`

### npm packages
- All packages bumped to v0.6.0 (core, vue3, react, alpine synchronized)

## v0.5.0 - 2026-03-31

### Added — Backend
- `ChunkyContext` abstract class for class-based upload context registration
- `Chunky::register()` method for class-based contexts
- `Chunky::simple()` one-line context registration with validate-and-move
- `UploadMetadata::withStatus()` immutable method for status transitions
- `updateStatus()` added to `UploadTracker` contract (both drivers implement it)
- `UploadCompleted` event now carries full `UploadMetadata` DTO via `$event->upload` (backward-compatible shorthand properties preserved)
- Config `contexts` array for auto-registering class-based contexts on boot
- Validation error for unregistered upload contexts (was silently ignored)
- Auth middleware documentation in config and README

### Added — Frontend
- Auto-detect `XSRF-TOKEN` cookie for zero-config CSRF in Laravel apps
- `createDefaults()` factory for isolated config scopes (no global state pollution)
- `checksum` option to disable per-chunk SHA-256 computation (`{ checksum: false }`)
- Concurrent upload guard — `upload()` throws if already in progress
- `resume()` and `retry()` return `boolean` indicating success
- AbortController cleanup on new upload (prevents leaked requests)
- Endpoint URL validation at construction time (`{uploadId}` placeholder required)
- `chunky:progress` and `chunky:chunk-uploaded` DOM events in Alpine component
- Linked source maps for all frontend package builds
- Consistent type exports and `setDefaults`/`getDefaults`/`createDefaults` re-exports across all wrapper packages

### Fixed
- React hook now recreates uploader when options change (was ignoring updates)
- `instanceof DatabaseTracker` coupling removed from `AssembleFileJob`
- `FilesystemTracker` now supports `updateStatus()` with `completed_at` timestamp

### npm packages
- All packages bumped to v0.5.0 (core, vue3, react, alpine synchronized)

## v0.4.0 - 2026-03-30

### Added
- Global defaults via `setDefaults()` / `getDefaults()` in `@netipar/chunky-core`
- `ChunkUploader` constructor merges global defaults with per-instance options (headers are deep-merged)
- `setDefaults` and `getDefaults` re-exported from `@netipar/chunky-vue3`, `@netipar/chunky-react`, and `@netipar/chunky-alpine`
- Enables one-time CSRF token setup: `setDefaults({ headers: { 'X-CSRF-TOKEN': token } })`

## v0.3.1 - 2026-03-30

### Fixed
- Removed `->default('[]')` from `uploaded_chunks` JSON column in migration (MariaDB/MySQL does not allow default values on JSON columns)

## v0.3.0 - 2026-03-30

### Added
- Laravel 13 support (`illuminate/*` `^13.0`)
- Orchestra Testbench `^11.0` support
- Pest `^4.0` support

## v0.2.1 - 2026-03-11

### Fixed
- Migration fails when `chunked_uploads` table already exists (added `Schema::hasTable` check)

## v0.2.0 - 2026-03-08

### Added
- npm monorepo with 4 frontend packages: `@netipar/chunky-core`, `@netipar/chunky-vue3`, `@netipar/chunky-react`, `@netipar/chunky-alpine`
- React hook (`useChunkUpload`) with full pause/resume/cancel/retry support
- Alpine.js data component (`chunkUpload`) with DOM event dispatching
- Context-based validation and save callbacks via `Chunky::context()`
- `UploadMetadata` DTO with `UploadStatus` enum for structured upload state
- Livewire `<livewire:chunky-upload />` component with Alpine.js integration
- Laravel Boost development skill for AI-assisted Chunky integration
- Pest test suite: 60 tests, 175 assertions (Unit + Feature)
- GitHub Actions CI workflow with Pint and Pest across PHP 8.2/8.3/8.4 + Laravel 11/12 matrix
- Laravel Pint code formatting with Laravel preset
- English and Hungarian example documentation

### Fixed
- `FilesystemTracker` infinite recursion on expired uploads
- `UploadMetadata::fromArray()` handling of `UploadStatus` enum from Eloquent model casts

## v0.1.0 - 2026-03-07

Initial release.

### Features
- Chunk-based file upload with configurable chunk size
- SHA-256 checksum integrity verification per chunk
- Parallel chunk upload support with configurable concurrency
- Pause, resume, and cancel upload controls
- Automatic retry with exponential backoff
- Resume support: query server for already-uploaded chunks
- Two tracking drivers: Database (Eloquent) and Filesystem (JSON)
- Queued file assembly via `AssembleFileJob`
- Event-driven architecture: `UploadInitiated`, `ChunkUploaded`, `ChunkUploadFailed`, `FileAssembled`, `UploadCompleted`
- Vue 3 composable (`useChunkUpload`) with reactive state
- `ChunkDropzone` component (Dropzone.js wrapper)
- `HeadlessChunkUpload` renderless component

### Architecture
- PHP 8.2+ with typed DTOs, enums, constructor promotion
- Laravel 11/12 support with auto-discovered service provider
- Framework-agnostic `ChunkUploader` TypeScript core with typed event emitter
- Three invokable controllers: initiate, upload chunk, query status
- `ChunkyManager` facade with programmatic API
- `ChunkHandler` and `UploadTracker` contracts for custom implementations
- Configurable routes, middleware, storage disk, expiration, MIME filtering
- MIT License
