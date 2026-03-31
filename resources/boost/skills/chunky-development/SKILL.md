---
name: chunky-development
description: Build and integrate chunk-based file upload features using the netipar/laravel-chunky package, including upload initiation, chunk handling, pause/resume, context-based validation, save callbacks, event listeners, and frontend integration with Vue 3, React, Alpine.js, or Livewire.
---

# Chunky File Upload Integration Development

## When to use this skill

Use this skill when:
- Implementing chunk-based file uploads in a Laravel application
- Handling large file uploads with progress tracking and pause/resume
- Registering upload contexts with validation rules and save callbacks
- Listening for upload events (initiated, chunk uploaded, assembled, completed)
- Integrating the frontend upload UI with Vue 3, React, Alpine.js, or Livewire
- Configuring chunk size, storage disk, tracker driver, or route middleware
- Querying upload status or processing completed uploads

## Package overview

The `netipar/laravel-chunky` package provides chunk-based file uploads for Laravel with SHA-256 integrity verification, event-driven architecture, and resume support.

- Namespace: `NETipar\Chunky\`
- Facade: `NETipar\Chunky\Facades\Chunky`
- Config: `config/chunky.php`
- Routes: `POST /api/chunky/upload`, `POST /api/chunky/upload/{uploadId}/chunks`, `GET /api/chunky/upload/{uploadId}` (auto-registered)

### Frontend packages (npm)

| Package | Framework | Install |
|---------|-----------|---------|
| `@netipar/chunky-core` | Vanilla JS/TS | `npm install @netipar/chunky-core` |
| `@netipar/chunky-vue3` | Vue 3.4+ | `npm install @netipar/chunky-vue3` |
| `@netipar/chunky-react` | React 18+/19+ | `npm install @netipar/chunky-react` |
| `@netipar/chunky-alpine` | Alpine.js 3+ | `npm install @netipar/chunky-alpine` |

The `@netipar/chunky-core` is automatically installed as a dependency of all framework packages.

## Installation

```bash
# Backend
composer require netipar/laravel-chunky
php artisan vendor:publish --tag=chunky-config
php artisan vendor:publish --tag=chunky-migrations
php artisan migrate

# Frontend (pick one)
npm install @netipar/chunky-vue3    # Vue 3
npm install @netipar/chunky-react   # React
npm install @netipar/chunky-alpine  # Alpine.js
```

Livewire needs no npm package -- the component is included in the Composer package.

## Facade access

```php
use NETipar\Chunky\Facades\Chunky;

Chunky::register(...)      // Register a class-based context
Chunky::context(...)       // Register inline context with rules/save closures
Chunky::initiate(...)      // Programmatic upload start
Chunky::uploadChunk(...)   // Programmatic chunk upload
Chunky::status(...)        // Query upload status (returns UploadMetadata)
Chunky::hasContext(...)    // Check if context exists
```

## Register upload contexts

### Class-based contexts (recommended)

Create a context class extending `NETipar\Chunky\ChunkyContext`:

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

Register via config (auto-registered on boot):

```php
// config/chunky.php
'contexts' => [
    App\Chunky\ProfileAvatarContext::class,
    App\Chunky\DocumentContext::class,
],
```

Or register manually:

```php
use NETipar\Chunky\Facades\Chunky;

Chunky::register(ProfileAvatarContext::class);
```

### Inline closures

For simple cases, register contexts with closures in `AppServiceProvider`:

```php
use NETipar\Chunky\Facades\Chunky;

public function boot(): void
{
    Chunky::context('documents', rules: fn () => [
        'file_size' => ['max:104857600'], // 100MB
        'mime_type' => ['in:application/pdf,application/zip'],
    ]);
}
```

The save callback receives an `UploadMetadata` DTO after assembly and runs inside `AssembleFileJob`.

## UploadMetadata DTO

`NETipar\Chunky\Data\UploadMetadata` -- readonly DTO for upload state:

```php
$metadata->uploadId;       // string (UUID)
$metadata->fileName;       // string
$metadata->fileSize;       // int (bytes)
$metadata->mimeType;       // ?string
$metadata->chunkSize;      // int
$metadata->totalChunks;    // int
$metadata->disk;           // string
$metadata->context;        // ?string
$metadata->metadata;       // array<string, mixed>
$metadata->uploadedChunks; // array<int, int>
$metadata->status;         // UploadStatus enum
$metadata->finalPath;      // ?string

$metadata->progress();     // float (0-100)
$metadata->toArray();      // array
UploadMetadata::fromArray($data); // static constructor
```

## UploadStatus enum

```php
use NETipar\Chunky\Enums\UploadStatus;

UploadStatus::Pending;    // 'pending'
UploadStatus::Assembling; // 'assembling'
UploadStatus::Completed;  // 'completed'
UploadStatus::Expired;    // 'expired'
```

## Listening to events

Register listeners in `EventServiceProvider`:

```php
use NETipar\Chunky\Events\UploadCompleted;
use NETipar\Chunky\Events\ChunkUploaded;
use NETipar\Chunky\Events\FileAssembled;
use NETipar\Chunky\Events\UploadInitiated;
use NETipar\Chunky\Events\ChunkUploadFailed;

protected $listen = [
    UploadCompleted::class => [
        \App\Listeners\ProcessUploadedFile::class,
    ],
    ChunkUploaded::class => [
        \App\Listeners\TrackUploadProgress::class,
    ],
];
```

### Available events

| Event | Properties | When |
|-------|------------|------|
| `UploadInitiated` | `uploadId`, `fileName`, `fileSize`, `totalChunks` | Upload initialized |
| `ChunkUploaded` | `uploadId`, `chunkIndex`, `totalChunks`, `progress` | After each successful chunk |
| `ChunkUploadFailed` | `uploadId`, `chunkIndex`, `exception` | On chunk error |
| `FileAssembled` | `uploadId`, `finalPath`, `disk`, `fileName`, `fileSize` | After file assembly |
| `UploadCompleted` | `uploadId`, `finalPath`, `disk`, `metadata` | Full upload complete |

### Event listener example

```php
namespace App\Listeners;

use NETipar\Chunky\Events\UploadCompleted;
use Illuminate\Support\Facades\Storage;

class ProcessUploadedFile
{
    public function handle(UploadCompleted $event): void
    {
        $path = $event->finalPath;
        $disk = $event->disk;
        $meta = $event->metadata;

        Storage::disk($disk)->move($path, "documents/{$event->uploadId}.pdf");
    }
}
```

### Events vs context save callbacks

- **Events**: Best for decoupled, reusable logic (notifications, logging, analytics)
- **Save callbacks**: Best for context-specific file processing (e.g., attach avatar to user via Spatie Media Library)

Both can coexist. Save callbacks run first (in the job), then `UploadCompleted` event fires.

## API endpoints

Routes are auto-registered with configurable prefix and middleware:

| Method | Endpoint | Name | Purpose |
|--------|----------|------|---------|
| `POST` | `/api/chunky/upload` | `chunky.initiate` | Initiate upload |
| `POST` | `/api/chunky/upload/{uploadId}/chunks` | `chunky.chunk` | Upload a chunk |
| `GET` | `/api/chunky/upload/{uploadId}` | `chunky.status` | Get upload status |

### Initiate upload request

Validated fields:
- `file_name`: required, string, max:255
- `file_size`: required, integer, min:1 (+ max if configured)
- `mime_type`: nullable, string (+ in:... if allowed_mimes configured)
- `metadata`: nullable, array
- `context`: nullable, string, max:100

Context-specific rules are merged automatically from `Chunky::context()`.

### Upload chunk request

Validated fields:
- `chunk`: required, file
- `chunk_index`: required, integer, min:0
- `checksum`: nullable, string (SHA-256)

The `VerifyChunkIntegrity` middleware verifies the checksum when `verify_integrity` config is true.

## Frontend: Vue 3

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
    context: 'documents',
    withCredentials: true,
});

function onFileChange(event: Event) {
    const input = event.target as HTMLInputElement;
    if (input.files?.[0]) {
        upload(input.files[0], { folder: 'reports' });
    }
}
</script>

<template>
    <input type="file" @change="onFileChange" :disabled="isUploading" />

    <div v-if="isUploading">
        <progress :value="progress" max="100" />
        <span>{{ progress }}%</span>
        <button @click="isPaused ? resume() : pause()">
            {{ isPaused ? 'Resume' : 'Pause' }}
        </button>
        <button @click="cancel()">Cancel</button>
    </div>

    <p v-if="isComplete">Upload complete!</p>
    <p v-if="error">{{ error }}</p>
</template>
```

### ChunkDropzone component (requires dropzone)

```vue
<script setup lang="ts">
import { ChunkDropzone } from '@netipar/chunky-vue3';
</script>

<template>
    <ChunkDropzone
        :chunk-options="{ context: 'documents', maxConcurrent: 3 }"
        @upload-complete="handleComplete"
        @progress="handleProgress"
    />
</template>
```

### HeadlessChunkUpload component

```vue
<HeadlessChunkUpload
    :options="{ context: 'documents' }"
    v-slot="{ upload, progress, isUploading, pause, resume, cancel }"
>
    <input type="file" @change="upload($event.target.files[0])" />
    <progress :value="progress" max="100" />
</HeadlessChunkUpload>
```

## Frontend: React

```tsx
import { useChunkUpload } from '@netipar/chunky-react';

function FileUpload() {
    const {
        progress, isUploading, isPaused, isComplete, error,
        upload, pause, resume, cancel, retry,
    } = useChunkUpload({ maxConcurrent: 3, context: 'documents' });

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

## Frontend: Alpine.js

```html
<script>
import { registerChunkUpload } from '@netipar/chunky-alpine';
import Alpine from 'alpinejs';

registerChunkUpload(Alpine);
Alpine.start();
</script>

<div x-data="chunkUpload({ context: 'documents', maxConcurrent: 3 })">
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
        <p x-text="error" style="color: red;"></p>
    </template>
</div>
```

Alpine dispatches `chunky:complete` and `chunky:error` DOM events.

## Frontend: Livewire

No npm install needed. Use the built-in Blade component:

```blade
{{-- Basic --}}
<livewire:chunky-upload />

{{-- With context --}}
<livewire:chunky-upload context="profile_avatar" />

{{-- With custom slot --}}
<livewire:chunky-upload context="documents">
    <div>
        <input type="file" x-on:change="handleFileInput($event)" />
        <div x-show="isUploading">
            <progress :value="progress" max="100"></progress>
        </div>
    </div>
</livewire:chunky-upload>
```

Handle completion in the parent Livewire component:

```php
#[On('chunky-upload-completed')]
public function handleUpload(array $data): void
{
    // $data['uploadId'], $data['fileName'], $data['finalPath'], $data['disk']
}
```

## CSRF protection

The frontend client automatically reads the `XSRF-TOKEN` cookie (set by Laravel) and sends it as the `X-XSRF-TOKEN` header. No manual CSRF setup is needed in most Laravel applications.

For custom token headers, use `setDefaults()`:

```typescript
import { setDefaults } from '@netipar/chunky-core';

setDefaults({ headers: { 'X-CSRF-TOKEN': 'your-token' } });
```

## Frontend: Core (framework-agnostic)

```typescript
import { ChunkUploader } from '@netipar/chunky-core';

const uploader = new ChunkUploader({
    maxConcurrent: 3,
    autoRetry: true,
    maxRetries: 3,
    context: 'documents',
});

uploader.on('progress', (event) => console.log(`${event.percentage}%`));
uploader.on('complete', (result) => console.log('Done:', result.uploadId));
uploader.on('error', (error) => console.error(error.message));

await uploader.upload(file, { folder: 'reports' });

// Controls
uploader.pause();
uploader.resume();
uploader.cancel();
uploader.retry();
uploader.destroy(); // Cleanup
```

## Programmatic backend usage

```php
use NETipar\Chunky\Facades\Chunky;

// Initiate
$result = Chunky::initiate('large-file.zip', 524288000, 'application/zip');
// Returns: ['upload_id' => '...', 'chunk_size' => 1048576, 'total_chunks' => 500]

// Query status
$metadata = Chunky::status($uploadId);
// Returns: UploadMetadata DTO or null
```

## Error handling

```php
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Exceptions\ChunkIntegrityException;
use NETipar\Chunky\Exceptions\UploadExpiredException;

try {
    $manager->uploadChunk($uploadId, $chunkIndex, $file);
} catch (ChunkIntegrityException $e) {
    // SHA-256 checksum mismatch
} catch (UploadExpiredException $e) {
    // Upload expired (past 24h default)
} catch (ChunkyException $e) {
    // Base exception (catches all above)
}
```

## Configuration

Key `.env` variables:

```
CHUNKY_TRACKER=database
CHUNKY_DISK=local
CHUNKY_CHUNK_SIZE=1048576
```

Full `config/chunky.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `tracker` | `database` | Tracking driver: `database` or `filesystem` |
| `disk` | `local` | Laravel filesystem disk |
| `chunk_size` | `1048576` (1MB) | Chunk size in bytes |
| `temp_directory` | `chunky/temp` | Temp directory for chunks |
| `final_directory` | `chunky/uploads` | Final directory for assembled files |
| `expiration` | `1440` | Upload expiration in minutes (24h) |
| `max_file_size` | `0` | Max file size in bytes (0 = unlimited) |
| `allowed_mimes` | `[]` | Allowed MIME types (empty = all) |
| `contexts` | `[]` | Class-based context classes (auto-registered) |
| `routes.prefix` | `api/chunky` | Route prefix |
| `routes.middleware` | `['api']` | Route middleware |
| `verify_integrity` | `true` | SHA-256 checksum verification |
| `auto_cleanup` | `true` | Auto-cleanup expired uploads |

### Common configurations

```php
// Large video uploads
return [
    'chunk_size' => 10 * 1024 * 1024, // 10MB chunks
    'max_file_size' => 5 * 1024 * 1024 * 1024, // 5GB max
    'allowed_mimes' => ['video/mp4', 'video/quicktime', 'video/webm'],
    'expiration' => 4320, // 3 days
];

// Authenticated routes
return [
    'routes' => [
        'prefix' => 'api/chunky',
        'middleware' => ['api', 'auth:sanctum'],
    ],
];

// Filesystem tracker (no database)
return [
    'tracker' => 'filesystem',
];
```

## Database table (chunked_uploads)

| Column | Type | Description |
|--------|------|-------------|
| `id` | ULID | Primary key |
| `upload_id` | string | Unique upload identifier (UUID) |
| `file_name` | string | Original file name |
| `file_size` | bigint | Total file size in bytes |
| `mime_type` | string | MIME type (nullable) |
| `chunk_size` | int | Chunk size used |
| `total_chunks` | int | Expected chunk count |
| `uploaded_chunks` | JSON | Array of uploaded chunk indices |
| `disk` | string | Laravel filesystem disk |
| `context` | string | Upload context (nullable) |
| `final_path` | string | Path after assembly (nullable) |
| `metadata` | JSON | Custom metadata from frontend |
| `status` | string | pending, assembling, completed, expired |
| `completed_at` | timestamp | When upload completed |
| `expires_at` | timestamp | When upload expires |

## Upload flow

1. Frontend calls `POST /api/chunky/upload` with file metadata
2. Backend returns `upload_id`, `chunk_size`, `total_chunks`
3. Frontend slices file and uploads chunks in parallel with SHA-256 checksums
4. Backend stores each chunk, verifies integrity, tracks progress
5. When all chunks arrive, `AssembleFileJob` is dispatched to the queue
6. Job assembles chunks into final file, calls context save callback, fires events
7. Frontend can poll `GET /api/chunky/upload/{uploadId}` for status

## Contracts

Implement custom handlers by binding to these interfaces:

### ChunkHandler (`NETipar\Chunky\Contracts\ChunkHandler`)

```php
public function store(string $uploadId, int $chunkIndex, UploadedFile $chunk): void;
public function assemble(string $uploadId, string $fileName, int $totalChunks): string;
public function cleanup(string $uploadId): void;
```

### UploadTracker (`NETipar\Chunky\Contracts\UploadTracker`)

```php
public function initiate(string $uploadId, UploadMetadata $metadata): void;
public function markChunkUploaded(string $uploadId, int $chunkIndex, ?string $checksum = null): void;
public function getUploadedChunks(string $uploadId): array;
public function isComplete(string $uploadId): bool;
public function getMetadata(string $uploadId): ?UploadMetadata;
public function expire(string $uploadId): void;
public function updateStatus(string $uploadId, UploadStatus $status, ?string $finalPath = null): void;
```

## Vendor publish tags

```bash
php artisan vendor:publish --tag=chunky-config       # config/chunky.php
php artisan vendor:publish --tag=chunky-migrations    # database migration
php artisan vendor:publish --tag=chunky-views         # Livewire Blade views
```
