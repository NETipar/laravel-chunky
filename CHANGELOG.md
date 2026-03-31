# Changelog

All notable changes to `netipar/laravel-chunky` will be documented in this file.

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
