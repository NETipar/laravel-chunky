# Changelog

All notable changes to `netipar/laravel-chunky` will be documented in this file.

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
