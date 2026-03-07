# Event Listeners

Chunky fires events at every step of the upload lifecycle. Register your own listeners to react to these events.

## Available Events

| Event | When | Key Properties |
|-------|------|---------------|
| `UploadInitiated` | Upload initialized | `uploadId`, `fileName`, `fileSize`, `totalChunks` |
| `ChunkUploaded` | After each chunk | `uploadId`, `chunkIndex`, `totalChunks`, `progress` |
| `ChunkUploadFailed` | Chunk error | `uploadId`, `chunkIndex`, `exception` |
| `FileAssembled` | File assembled from chunks | `uploadId`, `finalPath`, `disk`, `fileName`, `fileSize` |
| `UploadCompleted` | Upload fully complete | `uploadId`, `finalPath`, `disk`, `metadata` |

## Registering Listeners

### In EventServiceProvider

```php
use NETipar\Chunky\Events\UploadCompleted;
use NETipar\Chunky\Events\ChunkUploaded;
use NETipar\Chunky\Events\ChunkUploadFailed;
use NETipar\Chunky\Events\FileAssembled;
use NETipar\Chunky\Events\UploadInitiated;

protected $listen = [
    UploadCompleted::class => [
        \App\Listeners\MoveUploadedFile::class,
        \App\Listeners\CreateDocumentRecord::class,
        \App\Listeners\NotifyUser::class,
    ],
    ChunkUploaded::class => [
        \App\Listeners\BroadcastUploadProgress::class,
    ],
    ChunkUploadFailed::class => [
        \App\Listeners\LogChunkFailure::class,
    ],
];
```

### Using Event::listen

```php
use Illuminate\Support\Facades\Event;
use NETipar\Chunky\Events\UploadCompleted;

// In AppServiceProvider::boot()
Event::listen(UploadCompleted::class, function (UploadCompleted $event) {
    logger()->info("Upload completed: {$event->uploadId} -> {$event->finalPath}");
});
```

## Example Listeners

### Move Uploaded File

```php
namespace App\Listeners;

use NETipar\Chunky\Events\UploadCompleted;
use Illuminate\Support\Facades\Storage;

class MoveUploadedFile
{
    public function handle(UploadCompleted $event): void
    {
        $metadata = $event->metadata ?? [];
        $folder = $metadata['folder'] ?? 'uploads';

        Storage::disk($event->disk)->move(
            $event->finalPath,
            "{$folder}/{$event->uploadId}/" . basename($event->finalPath),
        );
    }
}
```

### Broadcast Upload Progress

```php
namespace App\Listeners;

use NETipar\Chunky\Events\ChunkUploaded;
use Illuminate\Support\Facades\Broadcast;

class BroadcastUploadProgress
{
    public function handle(ChunkUploaded $event): void
    {
        broadcast(new \App\Events\UploadProgressUpdated(
            uploadId: $event->uploadId,
            progress: $event->progress,
            chunkIndex: $event->chunkIndex,
            totalChunks: $event->totalChunks,
        ));
    }
}
```

### Log Chunk Failures

```php
namespace App\Listeners;

use NETipar\Chunky\Events\ChunkUploadFailed;

class LogChunkFailure
{
    public function handle(ChunkUploadFailed $event): void
    {
        logger()->warning("Chunk upload failed", [
            'upload_id' => $event->uploadId,
            'chunk_index' => $event->chunkIndex,
            'error' => $event->exception->getMessage(),
        ]);
    }
}
```

### Notify User

```php
namespace App\Listeners;

use NETipar\Chunky\Events\UploadCompleted;
use App\Models\User;
use App\Notifications\FileUploadedNotification;

class NotifyUser
{
    public function handle(UploadCompleted $event): void
    {
        $userId = $event->metadata['user_id'] ?? null;

        if ($userId) {
            User::find($userId)?->notify(new FileUploadedNotification($event->uploadId));
        }
    }
}
```

## Events vs Context Save Callbacks

You can handle post-upload processing in two ways:

### 1. Events (global listeners)

Best for cross-cutting concerns that apply to all uploads:

```php
// Runs for every upload, regardless of context
UploadCompleted::class => [NotifyUser::class]
```

### 2. Context Save Callbacks

Best for context-specific processing:

```php
// Only runs for 'profile_avatar' uploads
Chunky::context('profile_avatar', save: function ($metadata) {
    auth()->user()->addMediaFromDisk($metadata->finalPath, $metadata->disk)
        ->toMediaCollection('avatar');
});
```

Both can be used together -- the save callback runs first (in the `AssembleFileJob`), then events are dispatched.
