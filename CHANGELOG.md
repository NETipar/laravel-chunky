# Changelog

All notable changes to `netipar/laravel-chunky` will be documented in this file.

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
