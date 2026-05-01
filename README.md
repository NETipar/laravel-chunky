
<picture>
  <source media="(prefers-color-scheme: dark)" srcset="art/banner.svg">
  <img alt="laravel-chunky" src="art/banner.svg">
</picture>

# Chunky for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/netipar/laravel-chunky.svg?style=flat-square)](https://packagist.org/packages/netipar/laravel-chunky)
[![Tests](https://img.shields.io/github/actions/workflow/status/NETipar/laravel-chunky/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/NETipar/laravel-chunky/actions?query=workflow%3ATests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/netipar/laravel-chunky.svg?style=flat-square)](https://packagist.org/packages/netipar/laravel-chunky)

Chunk-based file upload package for Laravel with event-driven architecture, resume support, and framework-agnostic frontend clients for **Vue 3**, **React**, **Alpine.js**, and **Livewire**. Upload large files reliably over unstable connections.

## Quick Example

```php
// Backend: Listen for completed uploads
// EventServiceProvider
protected $listen = [
    \NETipar\Chunky\Events\UploadCompleted::class => [
        \App\Listeners\ProcessUploadedFile::class,
    ],
];
```

```vue
<!-- Frontend: Vue 3 upload with progress -->
<script setup>
import { useChunkUpload } from '@netipar/chunky-vue3';

const { upload, progress, isUploading, pause, resume } = useChunkUpload();

function onFileChange(event) {
    upload(event.target.files[0]);
}
</script>

<template>
    <input type="file" @change="onFileChange" />
    <progress v-if="isUploading" :value="progress" max="100" />
    <button v-if="isUploading" @click="pause">Pause</button>
</template>
```

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13

## 5-Minute Quickstart

```bash
# 1. Install backend
composer require netipar/laravel-chunky
php artisan vendor:publish --tag=chunky-config
php artisan migrate

# 2. Install frontend (Vue 3 example)
npm install @netipar/chunky-vue3
```

```vue
<!-- 3. Drop in the composable -->
<script setup>
import { useChunkUpload } from '@netipar/chunky-vue3';

const { upload, progress, isUploading, isComplete, error } = useChunkUpload();

function onFileChange(event) {
    const file = event.target.files[0];
    if (file) upload(file);
}
</script>

<template>
    <input type="file" @change="onFileChange" :disabled="isUploading" />
    <progress v-if="isUploading" :value="progress" max="100" />
    <p v-if="isComplete">Done!</p>
    <p v-if="error">Error: {{ error }}</p>
</template>
```

```bash
# 4. Test it
php artisan serve
# Open the page, upload a 10MB file. The chunks land in
# storage/app/chunky/temp/{uploadId}/ during the transfer; once
# complete, the AssembleFileJob writes the final file to
# storage/app/chunky/uploads/{uploadId}/{fileName}.
```

## Production Deployment Checklist

- [ ] **Auth middleware** on `chunky.routes.middleware` (e.g. `['api', 'auth:sanctum']`)
- [ ] **Queue worker** running for `AssembleFileJob` (don't run on `sync`)
- [ ] **Cache driver** that supports `Cache::lock()` if using `chunky.lock_driver = 'cache'` (Redis / Memcached / DB / DynamoDB; **not** `array` or `file`)
- [ ] **`CHUNKY_BROADCASTING=true`** if real-time UI updates are needed (requires Echo + a WebSocket server)
- [ ] **`chunky.staging_directory`** set to a path with enough free space if accepting uploads larger than `/tmp` (cloud-disk targets buffer the full file locally before upload)
- [ ] **`chunky.metadata.max_keys`** and **`chunky.max_files_per_batch`** tuned for your DOS profile
- [ ] **`chunky.metrics.*`** wired to Datadog / Prometheus / your observability stack
- [ ] **`chunky:cleanup`** scheduled daily (auto-scheduled if `auto_cleanup = true`)
- [ ] **Custom `Authorizer`** bound if the default ownership check (auth user_id == upload user_id) doesn't fit your access model
- [ ] **`routes/channels.php`** auto-registered (default) or hand-written if you set `chunky.broadcasting.register_channels = false`

## Installation

### Backend

```bash
composer require netipar/laravel-chunky
```

Publish the config file:

```bash
php artisan vendor:publish --tag=chunky-config
```

Run the migrations (for database tracker):

```bash
php artisan migrate
```

### Frontend

Install the package for your framework:

```bash
# Vue 3
npm install @netipar/chunky-vue3

# React
npm install @netipar/chunky-react

# Alpine.js (standalone, without Livewire)
npm install @netipar/chunky-alpine

# Core only (framework-agnostic)
npm install @netipar/chunky-core
```

> The `@netipar/chunky-core` package is automatically installed as a dependency of all framework packages.

### Livewire

No npm package needed. The Livewire component uses Alpine.js under the hood and is included in the Composer package. Just add the component to your Blade template:

```blade
<livewire:chunky-upload />
```

## Frontend Packages

| Package | Framework | Peer Dependencies |
|---------|-----------|-------------------|
| `@netipar/chunky-core` | None (vanilla JS/TS) | - |
| `@netipar/chunky-vue3` | Vue 3.4+ | `vue` |
| `@netipar/chunky-react` | React 18+ / 19+ | `react` |
| `@netipar/chunky-alpine` | Alpine.js 3+ | - |

## Usage

### How It Works

1. **Frontend** initiates an upload with file metadata
2. **Backend** returns an `upload_id`, `chunk_size`, and `total_chunks`
3. **Frontend** slices the file and uploads chunks in parallel with SHA-256 checksums
4. **Backend** stores each chunk, verifies integrity, tracks progress
5. When all chunks arrive, an `AssembleFileJob` merges them on the queue
6. **Events** fire at each step -- hook in your own listeners

### CSRF Protection

The frontend client automatically reads the `XSRF-TOKEN` cookie (set by Laravel) and sends it as the `X-XSRF-TOKEN` header. No manual CSRF setup is needed in most Laravel applications.

If you need a custom token header, use `setDefaults()`:

```typescript
import { setDefaults } from '@netipar/chunky-core';

setDefaults({ headers: { 'X-CSRF-TOKEN': 'your-token' } });
```

### Config Isolation

For multiple upload scopes on the same page:

```typescript
import { ChunkUploader, createDefaults } from '@netipar/chunky-core';

const scope = createDefaults({ headers: { 'X-Custom': 'value' } });
const uploader = new ChunkUploader({ context: 'docs' }, scope);
```

### API Endpoints

The package registers seven routes (configurable prefix/middleware):

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `POST` | `/api/chunky/upload` | Initiate upload |
| `POST` | `/api/chunky/upload/{uploadId}/chunks` | Upload a chunk |
| `GET` | `/api/chunky/upload/{uploadId}` | Get upload status |
| `DELETE` | `/api/chunky/upload/{uploadId}` | Cancel upload |
| `POST` | `/api/chunky/batch` | Initiate batch |
| `POST` | `/api/chunky/batch/{batchId}/upload` | Add file to batch |
| `GET` | `/api/chunky/batch/{batchId}` | Get batch status |

#### HTTP status codes

| Code | When |
|------|------|
| `201 Created` | Upload or batch initiated |
| `200 OK` | Chunk accepted, status fetched |
| `204 No Content` | Cancel succeeded |
| `404 Not Found` | Upload/batch doesn't exist — or the caller isn't its owner (intentional, prevents probe attacks) |
| `409 Conflict` | Late chunk against a cancelled / completed / failed / assembling upload |
| `410 Gone` | Upload has expired |
| `422 Unprocessable Entity` | Validation error (missing field, invalid file_name, batch in terminal state, etc.) |
| `503 Service Unavailable` | Upload temporarily contended on the lock; client may safely retry (idempotent) |

### Vue 3

```vue
<script setup lang="ts">
import { useChunkUpload } from '@netipar/chunky-vue3';

const {
    progress, isUploading, isPaused, isComplete, error,
    uploadId, uploadedChunks, totalChunks, currentFile,
    upload, pause, resume, cancel, retry,
    onProgress, onChunkUploaded, onComplete, onError,
} = useChunkUpload({
    maxConcurrent: 3,
    autoRetry: true,
    maxRetries: 3,
    withCredentials: true,
});

function onFileChange(event: Event) {
    const input = event.target as HTMLInputElement;
    if (input.files?.[0]) {
        upload(input.files[0]);
    }
}
</script>

<template>
    <input type="file" @change="onFileChange" :disabled="isUploading" />
    <progress v-if="isUploading" :value="progress" max="100" />
</template>
```

### React

```tsx
import { useChunkUpload } from '@netipar/chunky-react';

function FileUpload() {
    const {
        progress, isUploading, isPaused, isComplete, error,
        upload, pause, resume, cancel, retry,
    } = useChunkUpload({ maxConcurrent: 3 });

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) upload(file);
    };

    return (
        <div>
            <input type="file" onChange={handleChange} disabled={isUploading} />
            {isUploading && <progress value={progress} max={100} />}
            {isUploading && (
                <button onClick={isPaused ? resume : pause}>
                    {isPaused ? 'Resume' : 'Pause'}
                </button>
            )}
            {error && <p style={{ color: 'red' }}>{error}</p>}
        </div>
    );
}
```

### Alpine.js

```html
<script>
import { registerChunkUpload } from '@netipar/chunky-alpine';
import Alpine from 'alpinejs';

registerChunkUpload(Alpine);
Alpine.start();
</script>

<div x-data="chunkUpload({ maxConcurrent: 3 })">
    <input type="file" x-on:change="handleFileInput($event)" :disabled="isUploading" />

    <template x-if="isUploading">
        <div>
            <progress :value="progress" max="100"></progress>
            <span x-text="Math.round(progress) + '%'"></span>
            <button x-on:click="isPaused ? resume() : pause()" x-text="isPaused ? 'Resume' : 'Pause'"></button>
            <button x-on:click="cancel()">Cancel</button>
        </div>
    </template>

    <template x-if="error">
        <div>
            <span x-text="error"></span>
            <button x-on:click="retry()">Retry</button>
        </div>
    </template>
</div>
```

### Livewire

```blade
{{-- Basic usage --}}
<livewire:chunky-upload />

{{-- With context for validation --}}
<livewire:chunky-upload context="profile_avatar" />

{{-- With custom slot content --}}
<livewire:chunky-upload>
    <div class="my-custom-upload-ui">
        <input type="file" x-on:change="handleFileInput($event)" />
        <div x-show="isUploading">
            <progress :value="progress" max="100"></progress>
        </div>
    </div>
</livewire:chunky-upload>
```

Listen for the upload completion in your Livewire parent component:

```php
#[On('chunky-upload-completed')]
public function handleUpload(array $data): void
{
    // Default payload: $data['uploadId'], $data['fileName'], $data['fileSize']
    //
    // Set `chunky.broadcasting.expose_internal_paths = true` in
    // config/chunky.php to additionally receive $data['finalPath'] and
    // $data['disk']. By default they're stripped to avoid leaking
    // server-internal paths to the browser.
}
```

### Core (Framework-agnostic)

```typescript
import { ChunkUploader } from '@netipar/chunky-core';

const uploader = new ChunkUploader({
    maxConcurrent: 3,
    autoRetry: true,
    maxRetries: 3,
    context: 'documents',
});

uploader.on('progress', (event) => {
    console.log(`${event.percentage}%`);
});

uploader.on('complete', (result) => {
    console.log('Done:', result.uploadId);
});

uploader.on('error', (error) => {
    console.error('Failed:', error.message);
});

await uploader.upload(file, { folder: 'reports' });

// Controls
uploader.pause();
uploader.resume();
uploader.cancel();
uploader.retry();

// Cleanup when done
uploader.destroy();
```

## Batch Upload (Multiple Files)

Upload multiple files as a batch and get a single event when all files are done.

### Vue 3

```vue
<script setup lang="ts">
import { useBatchUpload } from '@netipar/chunky-vue3';

const {
    progress, isUploading, isComplete, completedFiles, totalFiles,
    failedFiles, currentFileName, error,
    upload, cancel, pause, resume,
    onFileComplete, onComplete, onFileError,
} = useBatchUpload({ maxConcurrentFiles: 2, context: 'documents' });

function onFilesChange(event: Event) {
    const input = event.target as HTMLInputElement;
    if (input.files?.length) {
        upload(Array.from(input.files));
    }
}
</script>

<template>
    <input type="file" multiple @change="onFilesChange" :disabled="isUploading" />
    <div v-if="isUploading">
        <progress :value="progress" max="100" />
        <span>{{ completedFiles }}/{{ totalFiles }} files</span>
        <span v-if="currentFileName">Uploading: {{ currentFileName }}</span>
    </div>
    <p v-if="isComplete">All files uploaded!</p>
</template>
```

### React

```tsx
import { useBatchUpload } from '@netipar/chunky-react';

function MultiFileUpload() {
    const {
        progress, isUploading, isComplete, completedFiles, totalFiles,
        upload, cancel,
    } = useBatchUpload({ maxConcurrentFiles: 2 });

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const files = e.target.files;
        if (files?.length) upload(Array.from(files));
    };

    return (
        <div>
            <input type="file" multiple onChange={handleChange} disabled={isUploading} />
            {isUploading && <progress value={progress} max={100} />}
            {isUploading && <span>{completedFiles}/{totalFiles} files</span>}
            {isComplete && <p>All files uploaded!</p>}
        </div>
    );
}
```

### Alpine.js

```html
<div x-data="batchUpload({ maxConcurrentFiles: 2 })">
    <input type="file" multiple x-on:change="handleFileInput($event)" :disabled="isUploading" />

    <template x-if="isUploading">
        <div>
            <progress :value="progress" max="100"></progress>
            <span x-text="completedFiles + '/' + totalFiles + ' files'"></span>
        </div>
    </template>
</div>
```

### Core (Framework-agnostic)

```typescript
import { BatchUploader } from '@netipar/chunky-core';

const batch = new BatchUploader({ maxConcurrentFiles: 2, context: 'documents' });

batch.on('fileComplete', (result) => console.log('File done:', result.fileName));
batch.on('complete', (result) => console.log(`Batch done: ${result.completedFiles}/${result.totalFiles}`));

await batch.upload(files);
batch.destroy();
```

### How Batch Works

1. Frontend calls `POST /api/chunky/batch` with `total_files` count
2. Backend creates a batch record and returns `batch_id`
3. For each file, frontend calls `POST /api/chunky/batch/{batchId}/upload` to initiate
4. Chunks are uploaded normally via `POST /api/chunky/upload/{uploadId}/chunks`
5. When each file's assembly completes, the batch counter increments atomically
6. When all files are done, `BatchCompleted` (or `BatchPartiallyCompleted`) event fires

Every upload creates a batch — even a single file becomes a batch of 1. This ensures consistent behavior: every upload gets a `batchId` and fires `BatchCompleted`. `useBatchUpload` is the single entry point for all uploads.

**Failure policy**: Lenient -- if a file fails, other files continue. The batch ends with `PartiallyCompleted` status.

### Sequential Batches with `enqueue()`

`upload()` throws if a batch is already in progress on the same `BatchUploader` instance. For UIs that accept files faster than they can upload (multi-paste, drag-while-uploading), use `enqueue()` instead:

```typescript
import { BatchUploader } from '@netipar/chunky-core';

const uploader = new BatchUploader({ maxConcurrentFiles: 2 });

// First call: behaves like upload()
await uploader.enqueue([file1]);

// While the first batch is still running, queue more:
const second = uploader.enqueue([file2, file3]);
const third = uploader.enqueue([file4]);

// Each enqueue() returns its own promise. The queued batches run
// strictly serially (one at a time), so you get a consistent
// progress signal across all of them.
await Promise.all([second, third]);
```

If you `cancel()` or `destroy()` the uploader before a queued batch starts, its promise rejects with a clear error message. The Vue 3 / React / Alpine wrappers all expose `enqueue` as a sibling of `upload`.

## Authentication & Authorization

### Authentication

By default, upload endpoints use only the `api` middleware. To protect them with authentication, update `routes.middleware` in `config/chunky.php`:

```php
'routes' => [
    'prefix' => 'api/chunky',
    'middleware' => ['api', 'auth:sanctum'],
],
```

This applies to all routes (initiate, upload chunk, cancel, status, batch). No custom request or controller override is needed.

### Authorization (per-upload, per-batch)

When auth is active, the package automatically enforces ownership: an authenticated caller can only access uploads / batches they created (the `user_id` captured at initiation time). Non-owners see a `404` (not `403`) so upload IDs can't be probed.

The check is delegated to a swappable `Authorizer` interface:

```php
namespace NETipar\Chunky\Authorization;

interface Authorizer
{
    public function canAccessUpload(?Authenticatable $user, UploadMetadata $upload): bool;
    public function canAccessBatch(?Authenticatable $user, BatchMetadata $batch): bool;
}
```

The default `DefaultAuthorizer` does plain ownership: `auth()->id() === upload->userId`, with anonymous uploads (no `user_id`) accessible to everyone (backward compat).

#### Custom Authorizer (admin overrides, team access, …)

Bind your own implementation in `AppServiceProvider::register()`:

```php
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\Authorization\DefaultAuthorizer;

$this->app->singleton(Authorizer::class, function ($app) {
    return new class extends DefaultAuthorizer
    {
        public function canAccessUpload(?Authenticatable $user, UploadMetadata $upload): bool
        {
            // Admins access everything
            if ($user?->is_admin) {
                return true;
            }

            // Teammates share access
            if ($upload->userId !== null) {
                $owner = User::find($upload->userId);
                if ($owner && $user?->team_id === $owner->team_id) {
                    return true;
                }
            }

            return parent::canAccessUpload($user, $upload);
        }
    };
});
```

The same `Authorizer` is used by the broadcast channel auth callbacks (`routes/channels.php`, auto-registered when `broadcasting.enabled = true`) — HTTP and WebSocket access stay in sync.

### `user_id` is portable

The `chunked_uploads.user_id` and `chunky_batches.user_id` columns are `string` type since v0.14, so any user-id shape works out of the box: auto-increment integers, UUIDs, ULIDs, or arbitrary strings. The package never does arithmetic on `user_id`, only string equality comparisons.

## Quick Context Setup

For the most common case -- validate and move the file to a directory:

```php
use NETipar\Chunky\Facades\Chunky;

Chunky::simple('documents', 'uploads/documents', [
    'max_size' => 50 * 1024 * 1024, // 50MB
    'mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
]);
```

This registers a context that validates the file and moves it from the temp directory to `uploads/documents/{fileName}` after assembly. No event listener needed.

## Context-based Validation & Save Callbacks

Contexts define per-upload validation rules and save handlers. You can use class-based contexts (recommended) or inline closures.

### Class-based Contexts (Recommended)

Create a context class:

```php
namespace App\Chunky;

use NETipar\Chunky\ChunkyContext;
use NETipar\Chunky\Data\UploadMetadata;

class ProfileAvatarContext extends ChunkyContext
{
    public function name(): string
    {
        return 'profile_avatar';
    }

    public function rules(): array
    {
        return [
            'file_size' => ['max:5242880'], // 5MB
            'mime_type' => ['in:image/jpeg,image/png,image/webp'],
        ];
    }

    public function save(UploadMetadata $metadata): void
    {
        auth()->user()
            ->addMediaFromDisk($metadata->finalPath, $metadata->disk)
            ->toMediaCollection('avatar');
    }
}
```

Register via config (`config/chunky.php`):

```php
'contexts' => [
    App\Chunky\ProfileAvatarContext::class,
    App\Chunky\DocumentContext::class,
],
```

Or register manually in your `AppServiceProvider`:

```php
use NETipar\Chunky\Facades\Chunky;

public function boot(): void
{
    Chunky::register(ProfileAvatarContext::class);
}
```

### Inline Closures

For simple cases, you can register contexts inline:

```php
use NETipar\Chunky\Facades\Chunky;

public function boot(): void
{
    Chunky::context(
        'documents',
        rules: fn () => [
            'file_size' => ['max:104857600'], // 100MB
            'mime_type' => ['in:application/pdf,application/zip'],
        ],
    );
}
```

### Using Contexts from Frontend

```typescript
// Vue 3
const { upload } = useChunkUpload({ context: 'profile_avatar' });

// React
const { upload } = useChunkUpload({ context: 'profile_avatar' });

// Alpine.js
// <div x-data="chunkUpload({ context: 'profile_avatar' })">
```

## Listening to Events

Register listeners in your `EventServiceProvider`:

```php
use NETipar\Chunky\Events\UploadCompleted;
use NETipar\Chunky\Events\ChunkUploaded;
use NETipar\Chunky\Events\FileAssembled;

protected $listen = [
    UploadCompleted::class => [
        \App\Listeners\ProcessUploadedFile::class,
        \App\Listeners\NotifyUserAboutUpload::class,
    ],
    ChunkUploaded::class => [
        \App\Listeners\TrackUploadProgress::class,
    ],
];
```

Example listener:

```php
namespace App\Listeners;

use NETipar\Chunky\Events\UploadCompleted;
use Illuminate\Support\Facades\Storage;

class ProcessUploadedFile
{
    public function handle(UploadCompleted $event): void
    {
        // Full UploadMetadata DTO available via $event->upload
        $upload = $event->upload;

        Storage::disk($upload->disk)->move(
            $upload->finalPath,
            "documents/{$upload->uploadId}.zip",
        );

        // Shorthand properties also available for convenience:
        // $event->uploadId, $event->finalPath, $event->disk, $event->metadata
    }
}
```

### Available Events

| Event | Payload | When | Broadcasts? |
|-------|---------|------|-------------|
| `UploadInitiated` | uploadId, fileName, fileSize, totalChunks | Upload initialized | — |
| `ChunkUploaded` | uploadId, chunkIndex, totalChunks, progress% | After each successful chunk | — |
| `ChunkUploadFailed` | uploadId, chunkIndex, exception | On chunk error | — |
| `FileAssembled` | uploadId, finalPath, disk, fileName, fileSize | After file assembly | — |
| `UploadCompleted` | upload (UploadMetadata) | Full upload complete | ✅ |
| `UploadFailed` | upload (UploadMetadata), reason | Save callback failed or assembly job exhausted retries | ✅ |
| `BatchInitiated` | batchId, totalFiles | Batch created | — |
| `BatchCompleted` | batchId, totalFiles | All batch files completed | ✅ |
| `BatchPartiallyCompleted` | batchId, completedFiles, failedFiles, totalFiles | Batch done with failures | ✅ |

## Broadcasting (Laravel Echo)

Get real-time notifications when uploads or batches complete. Broadcasting is **disabled by default** -- enable it in your `.env`:

```
CHUNKY_BROADCASTING=true
```

Four events are broadcastable: `UploadCompleted`, `UploadFailed`, `BatchCompleted`, and `BatchPartiallyCompleted`. They use private channels — when `chunky.broadcasting.register_channels = true` (default), the package auto-registers `Broadcast::channel()` callbacks that delegate to the bound `Authorizer`, so the same ownership rules apply on HTTP and WebSocket.

If you set `register_channels = false`, register them manually in your `routes/channels.php`:

```php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('chunky.uploads.{uploadId}', function ($user, $uploadId) {
    // Verify the user owns this upload
    return true;
});

Broadcast::channel('chunky.batches.{batchId}', function ($user, $batchId) {
    return true;
});
```

### Vue 3

```vue
<script setup>
import { useChunkUpload, useUploadEcho } from '@netipar/chunky-vue3';

const echo = inject('echo');
const { upload, uploadId } = useChunkUpload();

useUploadEcho(echo, uploadId, (data) => {
    console.log('Upload ready:', data.fileName);
});
</script>
```

### React

```tsx
import { useChunkUpload, useUploadEcho } from '@netipar/chunky-react';

function FileUpload({ echo }) {
    const { upload, uploadId } = useChunkUpload();

    useUploadEcho(echo, uploadId, (data) => {
        console.log('Upload ready:', data.fileName);
    });

    // ...
}
```

### Batch Echo

```typescript
// Vue 3
import { useBatchUpload, useBatchEcho } from '@netipar/chunky-vue3';

const { upload, batchId } = useBatchUpload();

useBatchEcho(echo, batchId, {
    onComplete: (data) => console.log(`All ${data.totalFiles} files ready`),
    onPartiallyCompleted: (data) => console.log(`${data.failedFiles} files failed`),
});
```

### Core (Framework-agnostic)

```typescript
import { listenForUploadComplete, listenForBatchComplete } from '@netipar/chunky-core';

const unsubscribe = listenForUploadComplete(echo, uploadId, (data) => {
    console.log('Ready:', data.fileName);
});

// Cleanup when done
unsubscribe();
```

### User Channel

Instead of subscribing per-upload or per-batch, listen on the **user channel** to receive all upload events — even after page reload:

```php
// routes/channels.php
Broadcast::channel('chunky.user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
```

```vue
<!-- Vue 3 -->
<script setup>
import { useUserEcho } from '@netipar/chunky-vue3';

const echo = inject('echo');
const userId = ref(auth.user.id);

useUserEcho(echo, userId, {
    onUploadComplete: (data) => console.log('File ready:', data.fileName),
    onBatchComplete: (data) => console.log(`All ${data.totalFiles} files done`),
    onBatchPartiallyCompleted: (data) => console.log(`${data.failedFiles} failed`),
});
</script>
```

```tsx
// React
import { useUserEcho } from '@netipar/chunky-react';

useUserEcho(echo, auth.user.id, {
    onUploadComplete: (data) => console.log('File ready:', data.fileName),
});
```

The user channel requires authenticated routes (`auth:sanctum` middleware) and `user_id` is automatically captured from `auth()->id()` during upload initiation.

## Using the Facade

```php
use NETipar\Chunky\Facades\Chunky;

// Register contexts
Chunky::register(ProfileAvatarContext::class);
Chunky::context('documents', rules: fn () => [...], save: fn ($metadata) => ...);

// Programmatic initiation (returns InitiateResult DTO)
$result = Chunky::initiate('large-file.zip', 524288000, 'application/zip');
// $result->uploadId, $result->chunkSize, $result->totalChunks

// Query upload status (returns UploadMetadata DTO)
$status = Chunky::status($uploadId);
// $status->progress(), $status->fileName, $status->status, etc.

// Batch upload (returns BatchMetadata DTO)
$batch = Chunky::initiateBatch(totalFiles: 5, context: 'documents');
// $batch->batchId, $batch->totalFiles, $batch->status

// Add file to batch (returns InitiateResult DTO with batchId)
$file = Chunky::initiateInBatch($batch->batchId, 'photo.jpg', 5242880);
// $file->uploadId, $file->batchId

// Query batch status (returns BatchMetadata DTO)
$batch = Chunky::getBatchStatus($batchId);
// $batch->completedFiles, $batch->failedFiles, $batch->isFinished()
```

## Configuration

Key `.env` variables:

```
CHUNKY_TRACKER=database
CHUNKY_DISK=local
CHUNKY_CHUNK_SIZE=1048576
CHUNKY_BROADCASTING=false
CHUNKY_LOCK_DRIVER=flock
CHUNKY_STAGING_DIRECTORY=
```

Full `config/chunky.php`:

### Storage and lifecycle

| Key | Default | Description |
|-----|---------|-------------|
| `tracker` | `database` | Tracking driver: `database` or `filesystem` |
| `disk` | `local` | Laravel filesystem disk for storage |
| `chunk_size` | `1048576` (1MB) | Chunk size in bytes |
| `temp_directory` | `chunky/temp` | Temp directory for chunks |
| `final_directory` | `chunky/uploads` | Directory for assembled files |
| `staging_directory` | `null` (= `sys_get_temp_dir()`) | Local filesystem directory used while assembling chunks. Set when /tmp can't fit the largest expected upload. |
| `expiration` | `1440` | Upload expiration in minutes (24h) |
| `assembly_stale_after_minutes` | `10` | An `Assembling` upload older than this is considered stale and may be re-claimed by a retrying AssembleFileJob. |

### Validation

| Key | Default | Description |
|-----|---------|-------------|
| `max_file_size` | `0` | Max file size in bytes (0 = unlimited) |
| `max_files_per_batch` | `1000` | Max files allowed in a single batch (DOS protection) |
| `allowed_mimes` | `[]` | Allowed MIME types (empty = all) |
| `metadata.max_keys` | `50` | Max keys in user-supplied `metadata` array |
| `contexts` | `[]` | Class-based context classes (auto-registered) |

### Routes

| Key | Default | Description |
|-----|---------|-------------|
| `routes.prefix` | `api/chunky` | Route prefix |
| `routes.middleware` | `['api']` | Route middleware |

### Integrity and locking

| Key | Default | Description |
|-----|---------|-------------|
| `verify_integrity` | `true` | SHA-256 checksum verification on each chunk |
| `auto_cleanup` | `true` | Auto-cleanup expired uploads via daily schedule |
| `lock_driver` | `flock` | Locking primitive: `flock` (local-disk only) or `cache` (S3/multi-server, requires Cache::lock-capable cache driver) |
| `lock_ttl_seconds` | `30` | TTL for held locks (force-release deadline) |
| `lock_wait_seconds` | `5` | Block timeout when waiting for an existing lock |
| `skip_local_disk_guard` | `false` | Bypass FilesystemTracker's boot-time check for local-path-capable disk (advanced) |

### Idempotency

| Key | Default | Description |
|-----|---------|-------------|
| `idempotency.enabled` | `true` | Cache chunk POST responses by `(uploadId, chunkIndex, key)` for retry replay |
| `idempotency_ttl_seconds` | `300` | Cached idempotency entry TTL |

### Broadcasting

| Key | Default | Description |
|-----|---------|-------------|
| `broadcasting.enabled` | `false` | Enable WebSocket broadcasting (Echo) |
| `broadcasting.channel_prefix` | `chunky` | Private channel prefix |
| `broadcasting.queue` | `null` | Broadcast queue name (null = default) |
| `broadcasting.user_channel` | `true` | Broadcast on user channel too |
| `broadcasting.register_channels` | `true` | Auto-register `Broadcast::channel()` callbacks via the bound Authorizer |
| `broadcasting.expose_internal_paths` | `false` | Include `disk` and `finalPath` in broadcast payloads (off by default; opt in if a consumer needs them) |

### Observability

| Key | Default | Description |
|-----|---------|-------------|
| `metrics.<event>` | `null` | Optional handler (class string or closure) for `chunk_uploaded`, `chunk_upload_failed`, `assembly_started`, `assembly_completed`, `assembly_failed`. Class strings are `config:cache`-compatible and resolved through the container. |

## Tracking Drivers

### Database (default)

Uses the `chunked_uploads` table. Best for production -- queryable, reliable, supports status tracking.

```
CHUNKY_TRACKER=database
```

### Filesystem

Uses JSON metadata files on disk. Zero database dependency -- useful for simple setups.

```
CHUNKY_TRACKER=filesystem
```

## Error Handling

```php
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Exceptions\ChunkIntegrityException;
use NETipar\Chunky\Exceptions\UploadExpiredException;

try {
    $manager->uploadChunk($uploadId, $chunkIndex, $file);
} catch (ChunkIntegrityException $e) {
    // SHA-256 checksum mismatch
} catch (UploadExpiredException $e) {
    // Upload has expired (past 24h default)
} catch (ChunkyException $e) {
    // Base exception (catches all above)
}
```

## Examples

- [English examples](examples/en/)
- [Magyar peldak](examples/hu/)

## Testing

```bash
composer test
```

## Credits

- [NETipar](https://netipar.hu)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
