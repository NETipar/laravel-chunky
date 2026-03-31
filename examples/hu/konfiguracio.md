# Konfiguráció

## Konfigurációs fájl publikálása

```bash
php artisan vendor:publish --tag=chunky-config
```

Ez létrehozza a `config/chunky.php` fájlt az alkalmazásodban.

## Teljes konfigurációs referencia

```php
return [
    // Követő driver: 'database' | 'filesystem'
    // Database: chunked_uploads tábla, lekérdezhető, státusz követés
    // Filesystem: JSON fájlok lemezen, nincs adatbázis függőség
    'tracker' => env('CHUNKY_TRACKER', 'database'),

    // Laravel filesystem lemez a chunk-ok és fájlok tárolásához
    'disk' => env('CHUNKY_DISK', 'local'),

    // Chunk méret byte-ban
    // Alapértelmezett: 1MB. Nagyobb chunk = kevesebb kérés, kisebb chunk = jobb folytatás
    'chunk_size' => env('CHUNKY_CHUNK_SIZE', 1024 * 1024),

    // Ideiglenes könyvtár a chunk-oknak (lemez gyökérhez képest relatívan)
    'temp_directory' => 'chunky/temp',

    // Végleges könyvtár az összefűzött fájloknak
    'final_directory' => 'chunky/uploads',

    // Feltöltés lejárata percben
    'expiration' => 1440, // 24 óra

    // Maximális fájlméret byte-ban (0 = korlátlan)
    'max_file_size' => 0,

    // Engedélyezett MIME típusok (üres = minden engedélyezett)
    'allowed_mimes' => [],

    // Osztály-alapú feltöltési kontextusok (boot-kor automatikusan regisztrálva)
    'contexts' => [],

    // Route beállítások
    'routes' => [
        'prefix' => 'api/chunky',
        'middleware' => ['api'],
    ],

    // Chunk integritás ellenőrzés SHA-256 checksum-mal
    'verify_integrity' => true,

    // Lejárt feltöltések automatikus törlése
    'auto_cleanup' => true,
];
```

## Környezeti változók

```env
# Követő driver
CHUNKY_TRACKER=database

# Tároló lemez
CHUNKY_DISK=local

# Chunk méret (byte)
CHUNKY_CHUNK_SIZE=1048576
```

## Gyakori konfigurációk

### Nagy videó feltöltések

```php
return [
    'chunk_size' => 10 * 1024 * 1024, // 10MB chunk-ok
    'max_file_size' => 5 * 1024 * 1024 * 1024, // 5GB max
    'allowed_mimes' => ['video/mp4', 'video/quicktime', 'video/webm'],
    'expiration' => 4320, // 3 nap
];
```

### Dokumentum feltöltés S3-ra

```php
return [
    'disk' => 's3',
    'max_file_size' => 100 * 1024 * 1024, // 100MB
    'allowed_mimes' => ['application/pdf', 'application/zip'],
];
```

### Hitelesített feltöltési route-ok

```php
return [
    'routes' => [
        'prefix' => 'api/chunky',
        'middleware' => ['api', 'auth:sanctum'],
    ],
];
```

## Kontextus alapú validáció

### Osztály-alapú kontextusok (ajánlott)

Hozz létre egy kontextus osztályt minden feltöltési típushoz:

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
            'file_size' => ['max:5242880'],
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

Regisztráld a `config/chunky.php`-ban:

```php
'contexts' => [
    App\Chunky\ProfileAvatarContext::class,
],
```

### Inline closure-ök

Egyszerűbb esetekre:

```php
use NETipar\Chunky\Facades\Chunky;

public function boot(): void
{
    Chunky::context('documents', rules: fn () => [
        'file_size' => ['max:104857600'],
        'mime_type' => ['in:application/pdf,application/zip'],
    ]);
}
```

Használat a frontenden:

```typescript
// Vue 3
const { upload } = useChunkUpload({ context: 'profile_avatar' });

// React
const { upload } = useChunkUpload({ context: 'profile_avatar' });

// Alpine.js
// <div x-data="chunkUpload({ context: 'profile_avatar' })">
```

## Adatbázis migráció

A `database` tracker használatakor publikáld és futtasd a migrációt:

```bash
php artisan vendor:publish --tag=chunky-migrations
php artisan migrate
```

Ez létrehozza a `chunked_uploads` táblát:

| Oszlop | Típus | Leírás |
|--------|-------|--------|
| `id` | ULID | Elsődleges kulcs |
| `upload_id` | string | Egyedi feltöltési azonosító (UUID) |
| `file_name` | string | Eredeti fájlnév |
| `file_size` | bigint | Teljes fájlméret byte-ban |
| `mime_type` | string | MIME típus |
| `chunk_size` | int | Használt chunk méret |
| `total_chunks` | int | Várt chunk szám |
| `uploaded_chunks` | JSON | Feltöltött chunk indexek tömbje |
| `disk` | string | Laravel filesystem lemez |
| `context` | string | Feltöltés kontextus (nullable) |
| `final_path` | string | Útvonal összefűzés után |
| `metadata` | JSON | Egyedi metaadatok a frontendről |
| `status` | string | pending, assembling, completed, expired |
| `completed_at` | timestamp | Mikor fejeződött be |
| `expires_at` | timestamp | Mikor jár le |
