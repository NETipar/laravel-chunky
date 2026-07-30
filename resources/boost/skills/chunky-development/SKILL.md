---
name: chunky-development
description: >
  Integrate resumable, chunk-based file uploads into a Laravel application with
  the netipar/laravel-chunky package: install, upload profiles, assembly modes,
  events, broadcasting, authorization, console commands, and error handling.
license: MIT
metadata:
  author: NETipar
---

# Chunky — Laravel Backend Integration

Use this skill when a Laravel application needs chunk-based, resumable file
uploads (large files, flaky connections, progress tracking, batch uploads).
For the JavaScript clients (Vue 3, React, Alpine.js, Livewire) see the
`chunky-frontend` skill.

## Primary Goal

Apply the `netipar/laravel-chunky` public API in the smallest correct way: one
upload profile per upload type, default config unless a requirement says
otherwise, no queue worker or broadcasting until file size or UX demands it.

## Workflow

### 1. Install

```bash
composer require netipar/laravel-chunky
php artisan chunky:install   # publishes config/chunky.php + migrations
php artisan migrate
```

Zero-migration alternative: set `CHUNKY_TRACKER=filesystem` (state lives as
JSON on the chunk disk) and skip `migrate`.

Routes are auto-registered under `api/chunky` with the `api` middleware group
(configurable via `chunky.routes`). Verify the setup with `php artisan
chunky:doctor`.

### 2. Define an upload profile

A profile is the single place for validation, destination, authorization, and
the post-assembly hook. Quick form, in a service provider's `boot()`:

```php
use NETipar\Chunky\Facades\Chunky;

Chunky::simple('avatars', 'avatars', [
    'max_size' => 10 * 1024 * 1024,
    'mimes' => ['image/jpeg', 'image/png'],
    // 'disk' => 's3',
]);
```

Real logic belongs in a class — generate one with
`php artisan make:chunky-profile AvatarProfile` (lands in
`app/Chunky/Profiles`):

```php
use NETipar\Chunky\Profiles\CompletedUpload;
use NETipar\Chunky\Profiles\UploadContext;
use NETipar\Chunky\Profiles\UploadProfile;

class AvatarProfile extends UploadProfile
{
    public function rules(): array
    {
        // Keys: file_name / file_size / mime_type / metadata
        return ['file_size' => ['max:10485760'], 'mime_type' => ['in:image/jpeg,image/png']];
    }

    public function directory(UploadContext $context): string
    {
        return "avatars/{$context->userId()}";
    }

    public function authorize(UploadContext $context): bool
    {
        return $context->user !== null;
    }

    public function completed(CompletedUpload $upload): array
    {
        $media = Media::create(['disk' => $upload->disk, 'path' => $upload->path]);

        return ['media_id' => $media->id]; // becomes the response `payload`
    }
}
```

Register it in `config/chunky.php`:

```php
'profiles' => ['avatar' => \App\Chunky\Profiles\AvatarProfile::class],
```

Optional overrides: `disk(): ?string`, `fileName(UploadContext, string)`
(default: UUID + original extension), `maxFileSize(): ?int`.

### 3. Pick the assembly mode

`chunky.assembly.mode` — `auto` (default), `sync`, or `queue`:

- `sync`: final chunk response is `{"status":"completed","file":…,"payload":…}` — no worker needed.
- `queue`: final chunk response is `{"status":"assembling"}`; a queue worker runs `AssembleFileJob`; clients poll status.
- `auto`: sync below `assembly.sync_threshold` (256 MB default), queue above.

The default works with no infrastructure; only add a worker when large files
appear.

### 4. React to lifecycle events (optional)

Eleven standard Laravel events under `NETipar\Chunky\Events`:
`UploadInitiated`, `ChunkUploaded`, `ChunkUploadFailed`, `FileAssembled`,
`UploadCompleted`, `UploadFailed`, `UploadCancelled`, `BatchInitiated`,
`BatchCompleted`, `BatchPartiallyCompleted`, `BatchCancelled`. Prefer the
profile's `completed()` hook for per-upload-type logic; use events for
cross-cutting concerns (audit, notifications).

Broadcasting is off by default. Enable with `CHUNKY_BROADCASTING=true`; events
then broadcast on private channels `chunky.upload.{uploadId}` and
`chunky.batch.{batchId}` with sanitized, versioned payloads (`{"v":1,…}` —
`disk`, paths, and `user_id` never leave the server). Channel authorization is
registered by the package and delegates to the configured `Authorizer`.
High-frequency events are excluded by default via `chunky.broadcasting.except`.

### 5. Schedule cleanup

```php
Schedule::command('chunky:cleanup')->hourly();
```

Removes expired, unfinished uploads and their chunks
(`chunky.limits.expiration_hours`, default 24). `--dry-run` lists without
deleting.

## References

- HTTP API (prefix `api/chunky`, names `chunky.*`): `POST /upload`
  (initiate), `POST /upload/{uploadId}/chunks` (chunk),
  `GET|DELETE /upload/{uploadId}` (status/cancel), `POST /batch`,
  `POST /batch/{batchId}/upload`, `GET|DELETE /batch/{batchId}`. Wire
  protocol: `docs/en/protocol.md` (Hungarian: `docs/hu/protocol.md`),
  `docs/openapi.yaml`.
- Facade `NETipar\Chunky\Facades\Chunky`: `initiate(InitiateInput)`,
  `uploadChunk()`, `status()`, `cancel()`, `initiateBatch()`, `batchStatus()`,
  `cancelBatch()`, `registerProfile()`, `simple()`.
- Commands: `chunky:install`, `chunky:doctor {--wait=5}` (live health checks:
  disks, queue-worker probe, broadcast driver, locking, tracker; non-zero exit
  on errors, so it works as a CI/deploy gate), `chunky:cleanup {--dry-run}`,
  `make:chunky-profile`.
- Publish tags: `chunky-config`, `chunky-migrations` (both covered by
  `chunky:install`).
- Config reference and deployment recipes: `docs/en/configuration.md`
  (Hungarian: `docs/hu/configuration.md`). Key env
  vars: `CHUNKY_TRACKER`, `CHUNKY_DISK`, `CHUNKY_CHUNK_DISK`,
  `CHUNKY_CHUNK_SIZE`, `CHUNKY_MAX_FILE_SIZE`, `CHUNKY_ASSEMBLY_MODE`,
  `CHUNKY_BROADCASTING`.
- End-to-end integrity: the optional `file_checksum` chunk field carries the
  whole file's SHA-256; the assembled result is verified against it (mismatch
  → `422 checksum_mismatch` sync / upload `failed` queued, chunks kept until
  cleanup). `integrity.require_full_file` makes it mandatory on completion.
- Errors: every non-2xx response is `{"error":{"code":…,"message":…}}` with a
  stable machine code (`validation_failed`, `profile_not_found`,
  `unauthorized`, `upload_not_found`, `batch_not_found`, `upload_expired`,
  `invalid_state`, `chunk_index_out_of_range`, `checksum_mismatch`,
  `lock_timeout`, `assembly_failed`).
- Statuses: uploads `pending → uploading → assembling → completed` (or
  `failed`/`cancelled`); batches `pending → in_progress → completed |
  partially_completed | cancelled`.

## Examples

- Avatar upload: `make:chunky-profile AvatarProfile`, restrict mimes/size in
  `rules()`, create the Media record in `completed()` and return its id as the
  payload; the frontend reads `result.payload.media_id`.
- Authenticated API: set `'routes' => ['middleware' => ['api', 'auth:sanctum']]`
  in `config/chunky.php`. Ownership is enforced automatically — non-owners get
  404 on status/cancel (anti-enumeration) and 403 on chunk upload; customize by
  swapping `chunky.authorization.authorizer`.
- Feature test in the consuming app: POST `route('chunky.initiate')` with
  `file_name`, `file_size`, `profile`; assert 201 and drive chunks with
  `UploadedFile::fake()`; use `Event::fake([UploadCompleted::class])` around
  the final chunk.

## Anti-patterns

- Do not bypass profiles by putting validation or file-moving logic in
  controllers or event listeners — `rules()`, `directory()`, and `completed()`
  are the supported extension points.
- Do not register broadcast channel authorization for `chunky.*` channels in
  the app — the package already does, backed by the `Authorizer`.
- Do not branch on error `message` strings; the stable API is `error.code`.
- Do not pass a different profile for batch members — members are validated
  against the batch's profile by design.
- Do not resolve internal services (`UploadService`, ports, repositories) for
  ordinary integration; the facade and profiles cover the public surface.
