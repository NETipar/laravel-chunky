# Configuration

## Publishing the Config

```bash
php artisan vendor:publish --tag=chunky-config
```

This creates `config/chunky.php` in your application.

## Full Configuration Reference

```php
return [
    // Tracking driver: 'database' | 'filesystem'
    // Database: uses chunked_uploads table, queryable, supports status tracking
    // Filesystem: uses JSON files on disk, zero DB dependency
    'tracker' => env('CHUNKY_TRACKER', 'database'),

    // Laravel filesystem disk for chunk and file storage
    // Supports any configured disk: local, s3, etc.
    'disk' => env('CHUNKY_DISK', 'local'),

    // Chunk size in bytes
    // Default: 1MB. Larger chunks = fewer requests, smaller chunks = better resume
    'chunk_size' => env('CHUNKY_CHUNK_SIZE', 1024 * 1024),

    // Temp directory for chunk storage (relative to disk root)
    'temp_directory' => 'chunky/temp',

    // Final directory for assembled files (relative to disk root)
    'final_directory' => 'chunky/uploads',

    // Upload expiration in minutes
    // Uploads not completed within this time are marked as expired
    'expiration' => 1440, // 24 hours

    // Maximum file size in bytes (0 = unlimited)
    'max_file_size' => 0,

    // Allowed MIME types (empty array = all types allowed)
    'allowed_mimes' => [],

    // Route configuration
    'routes' => [
        'prefix' => 'api/chunky',
        'middleware' => ['api'],
    ],

    // Verify chunk integrity using SHA-256 checksums
    'verify_integrity' => true,

    // Automatic cleanup of expired uploads
    'auto_cleanup' => true,
];
```

## Environment Variables

```env
# Tracking driver
CHUNKY_TRACKER=database

# Storage disk
CHUNKY_DISK=local

# Chunk size (bytes)
CHUNKY_CHUNK_SIZE=1048576
```

## Common Configurations

### Large Video Uploads

```php
// config/chunky.php
return [
    'chunk_size' => 10 * 1024 * 1024, // 10MB chunks
    'max_file_size' => 5 * 1024 * 1024 * 1024, // 5GB max
    'allowed_mimes' => ['video/mp4', 'video/quicktime', 'video/webm'],
    'expiration' => 4320, // 3 days
];
```

### Document Uploads with S3

```php
// config/chunky.php
return [
    'disk' => 's3',
    'max_file_size' => 100 * 1024 * 1024, // 100MB
    'allowed_mimes' => ['application/pdf', 'application/zip'],
];
```

### Authenticated Upload Routes

```php
// config/chunky.php
return [
    'routes' => [
        'prefix' => 'api/chunky',
        'middleware' => ['api', 'auth:sanctum'],
    ],
];
```

### Filesystem Tracker (No Database)

```php
// config/chunky.php
return [
    'tracker' => 'filesystem',
];
```

No migration needed. Upload state is stored as JSON files alongside the chunks.

## Context-based Validation

Register per-context validation rules in your `AppServiceProvider`:

```php
use NETipar\Chunky\Facades\Chunky;

public function boot(): void
{
    // Profile avatar: images only, max 5MB
    Chunky::context('profile_avatar', rules: fn () => [
        'file_size' => ['max:5242880'],
        'mime_type' => ['in:image/jpeg,image/png,image/webp'],
    ]);

    // Documents: PDFs and ZIPs, max 100MB
    Chunky::context('documents', rules: fn () => [
        'file_size' => ['max:104857600'],
        'mime_type' => ['in:application/pdf,application/zip'],
    ]);
}
```

Then use the context from the frontend:

```typescript
// Vue 3
const { upload } = useChunkUpload({ context: 'profile_avatar' });

// React
const { upload } = useChunkUpload({ context: 'profile_avatar' });

// Alpine.js
// <div x-data="chunkUpload({ context: 'profile_avatar' })">
```

## Database Migration

When using the `database` tracker, publish and run the migration:

```bash
php artisan vendor:publish --tag=chunky-migrations
php artisan migrate
```

This creates the `chunked_uploads` table:

| Column | Type | Description |
|--------|------|-------------|
| `id` | ULID | Primary key |
| `upload_id` | string | Unique upload identifier (UUID) |
| `file_name` | string | Original file name |
| `file_size` | bigint | Total file size in bytes |
| `mime_type` | string | MIME type |
| `chunk_size` | int | Chunk size used |
| `total_chunks` | int | Expected number of chunks |
| `uploaded_chunks` | JSON | Array of uploaded chunk indices |
| `disk` | string | Laravel filesystem disk |
| `context` | string | Upload context (nullable) |
| `final_path` | string | Path after assembly |
| `metadata` | JSON | Custom metadata from frontend |
| `status` | string | pending, assembling, completed, expired |
| `completed_at` | timestamp | When upload completed |
| `expires_at` | timestamp | When upload expires |
