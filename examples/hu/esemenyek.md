# Esemény Listenerek

A Chunky eseményeket (event) küld a feltöltés minden lépésénél. Saját listener-eket regisztrálhatsz ezekre az eseményekre.

## Elérhető események

| Esemény | Mikor | Fő tulajdonságok |
|---------|-------|-------------------|
| `UploadInitiated` | Feltöltés inicializálva | `uploadId`, `fileName`, `fileSize`, `totalChunks` |
| `ChunkUploaded` | Minden chunk után | `uploadId`, `chunkIndex`, `totalChunks`, `progress` |
| `ChunkUploadFailed` | Chunk hiba | `uploadId`, `chunkIndex`, `exception` |
| `FileAssembled` | Fájl összefűzve | `uploadId`, `finalPath`, `disk`, `fileName`, `fileSize` |
| `UploadCompleted` | Feltöltés kész | `uploadId`, `finalPath`, `disk`, `metadata` |

## Listener-ek regisztrálása

### EventServiceProvider-ben

```php
use NETipar\Chunky\Events\UploadCompleted;
use NETipar\Chunky\Events\ChunkUploaded;
use NETipar\Chunky\Events\ChunkUploadFailed;

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

## Példa Listener-ek

### Feltöltött fájl áthelyezése

```php
namespace App\Listeners;

use NETipar\Chunky\Events\UploadCompleted;
use Illuminate\Support\Facades\Storage;

class MoveUploadedFile
{
    public function handle(UploadCompleted $event): void
    {
        $metadata = $event->metadata ?? [];
        $folder = $metadata['folder'] ?? 'feltöltések';

        Storage::disk($event->disk)->move(
            $event->finalPath,
            "{$folder}/{$event->uploadId}/" . basename($event->finalPath),
        );
    }
}
```

### Feltöltési folyamat broadcast-olása

```php
namespace App\Listeners;

use NETipar\Chunky\Events\ChunkUploaded;

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

### Chunk hibák naplózása

```php
namespace App\Listeners;

use NETipar\Chunky\Events\ChunkUploadFailed;

class LogChunkFailure
{
    public function handle(ChunkUploadFailed $event): void
    {
        logger()->warning("Chunk feltöltés sikertelen", [
            'upload_id' => $event->uploadId,
            'chunk_index' => $event->chunkIndex,
            'hiba' => $event->exception->getMessage(),
        ]);
    }
}
```

### Felhasználó értesítése

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

## Események vs Kontextus Save Callback-ek

A feltöltés utáni feldolgozás kétféleképpen történhet:

### 1. Események (globális listener-ek)

Minden feltöltésre vonatkozó feladatokra:

```php
// Minden feltöltésnél lefut, kontextustól függetlenül
UploadCompleted::class => [NotifyUser::class]
```

### 2. Kontextus Save Callback-ek

Kontextus-specifikus feldolgozásra:

```php
// Csak 'profile_avatar' feltöltéseknél fut le
Chunky::context('profile_avatar', save: function ($metadata) {
    auth()->user()->addMediaFromDisk($metadata->finalPath, $metadata->disk)
        ->toMediaCollection('avatar');
});
```

Mindkettő használható együtt -- a save callback fut először (az `AssembleFileJob`-ban), majd az események kerülnek kiküldésre.
