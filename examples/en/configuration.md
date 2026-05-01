# Configuration

> The canonical configuration reference is in
> [`config/chunky.php`](../../config/chunky.php) (every key is
> commented inline) and the table in [README.md](../../README.md).
> This document highlights common recipes only.

## Publishing the Config

```bash
php artisan vendor:publish --tag=chunky-config
```

This creates `config/chunky.php` in your application.

## Common Recipes

### Large Video Uploads (4MB chunks, 5GB cap)

```php
return [
    'chunk_size' => 4 * 1024 * 1024,
    'max_file_size' => 5 * 1024 * 1024 * 1024,
    'max_chunks_per_upload' => 200_000,
    'allowed_mimes' => ['video/mp4', 'video/quicktime', 'video/webm'],
    'expiration' => 4320, // 3 days
];
```

### S3 / cloud disk

```php
return [
    'disk' => 's3',
    // S3 doesn't expose a local path, so flock() can't be used. Switch
    // to cache-backed locking (requires Redis / Memcached / DB cache).
    'lock_driver' => 'cache',
    // Cloud-disk assemblies still need local scratch space — point this
    // at a volume with enough room for your largest file.
    'staging_directory' => '/var/chunky-staging',
];
```

### Authenticated routes + custom rate limit

```php
return [
    'routes' => [
        'prefix' => 'api/chunky',
        'middleware' => ['api', 'auth:sanctum', 'throttle:chunky'],
    ],
    'throttle' => [
        'attempts' => 240,   // chunks/minute per user
        'decay_minutes' => 1,
    ],
];
```

### Broadcasting (Echo / Reverb / Pusher)

```php
return [
    'broadcasting' => [
        'enabled' => true,
        'channel_prefix' => 'chunky',
        'queue' => 'broadcasts',
        // Strip server-internal `disk` / `finalPath` from the wire by
        // default; opt back in only if a consumer truly needs them.
        'expose_internal_paths' => false,
    ],
];
```

### Class-based upload contexts

```php
// app/Chunky/AvatarContext.php
final class AvatarContext extends \NETipar\Chunky\ChunkyContext
{
    public function name(): string
    {
        return 'avatar';
    }

    public function rules(): array
    {
        return [
            'file_size' => ['max:5242880'], // 5MB
            'mime_type' => ['in:image/jpeg,image/png,image/webp'],
        ];
    }

    public function save(\NETipar\Chunky\Data\UploadMetadata $metadata): void
    {
        // move from temp -> avatar storage, write DB record, etc.
    }
}

// config/chunky.php
return [
    'contexts' => [
        \App\Chunky\AvatarContext::class,
    ],
];
```

## Environment Variables

```env
CHUNKY_TRACKER=database
CHUNKY_DISK=local
CHUNKY_CHUNK_SIZE=1048576
CHUNKY_LOCK_DRIVER=flock
CHUNKY_BROADCASTING=false
CHUNKY_STAGING_DIRECTORY=
CHUNKY_CACHE_PREFIX=chunky:v1:
```

## Schema

The `database` tracker uses two tables. See
`database/migrations/` for the canonical schema; key columns:

**`chunked_uploads`** — `upload_id`, `batch_id`, `user_id`, `file_name`,
`file_size`, `mime_type`, `chunk_size`, `total_chunks`,
`uploaded_chunks` (JSON), `status` (enum:
`pending|assembling|completed|failed|cancelled|expired`), `disk`,
`final_path`, `metadata` (JSON), `expires_at`, `claimed_at`,
`completed_at`.

**`chunky_batches`** — `batch_id`, `user_id`, `total_files`,
`completed_files`, `failed_files`, `status` (enum:
`pending|processing|completed|partially_completed|expired`),
`context`, `metadata` (JSON), `expires_at`.
