# Upgrade Guide

Migration notes for breaking changes between minor versions while the
package is in `0.x`. Patch releases (`0.x.y`) never contain breaking
changes — refer to the [CHANGELOG](CHANGELOG.md) for the full log.

## Upgrading to 0.18 from 0.17

v0.18 is the structural-cleanup minor: thinner ChunkyManager, namespaced
config, deduplicated request validation. The runtime behaviour is the
same, but a published `config/chunky.php` and any code that relied on
the internal interfaces needs adjustment.

### Config keys are namespaced

The flat config grew to 30+ keys over v0.10–0.17. v0.18 reshapes them
into eight namespaces. The compatibility map:

| Old key | New key |
|---|---|
| `chunky.chunk_size` | `chunky.chunks.size` |
| `chunky.verify_integrity` | `chunky.chunks.verify_integrity` |
| `chunky.temp_directory` | `chunky.storage.temp_directory` |
| `chunky.final_directory` | `chunky.storage.final_directory` |
| `chunky.staging_directory` | `chunky.storage.staging_directory` |
| `chunky.expiration` | `chunky.lifecycle.expiration_minutes` |
| `chunky.assembly_stale_after_minutes` | `chunky.lifecycle.assembly_stale_after_minutes` |
| `chunky.auto_cleanup` | `chunky.lifecycle.auto_cleanup` |
| `chunky.max_file_size` | `chunky.limits.max_file_size` |
| `chunky.max_chunks_per_upload` | `chunky.limits.max_chunks_per_upload` |
| `chunky.max_files_per_batch` | `chunky.limits.max_files_per_batch` |
| `chunky.allowed_mimes` | `chunky.limits.allowed_mimes` |
| `chunky.lock_driver` | `chunky.locking.driver` |
| `chunky.lock_ttl_seconds` | `chunky.locking.ttl_seconds` |
| `chunky.lock_wait_seconds` | `chunky.locking.wait_seconds` |
| `chunky.idempotency_ttl_seconds` | `chunky.idempotency.ttl_seconds` |

**Removed flags** (always-on or rendered redundant by the rewrite):

- `chunky.idempotency.enabled` — idempotency is always on; the
  Idempotency-Key cache key is per-(uploadId, chunkIndex) so collisions
  are impossible and there's no realistic reason to opt out.
- `chunky.skip_local_disk_guard` — the boot-time guard now keys off
  `chunky.locking.driver` directly. Set the driver to `cache` for cloud
  disks (the only legitimate use of the old escape hatch).
- `chunky.broadcasting.user_channel` — the user channel is registered
  whenever a broadcast event has a non-null `userId`. No flag needed.

**Migration:** republish the config (`php artisan vendor:publish --tag=chunky-config --force`)
or hand-edit your existing `config/chunky.php` using the table above.
The package no longer reads the old keys.

### `ChunkyManager` constructor signature

`ChunkyManager::__construct` now takes four arguments:

```php
public function __construct(
    ChunkHandler $handler,
    UploadTracker $tracker,
    BatchTracker $batchTracker,           // new
    ContextRegistry $contexts,            // new
)
```

Callers that resolve the manager from the container are unaffected.
Custom service-provider bindings need updating.

### `ChunkHandler::assemble()` accepts `UploadMetadata`

The handler interface now takes the full metadata DTO:

```php
// before
public function assemble(string $uploadId, string $fileName, int $totalChunks): string;

// after
public function assemble(UploadMetadata $metadata): string;
```

Custom `ChunkHandler` implementations need to update the signature.
The new shape gives the handler access to `fileSize` for disk-space
pre-flight and post-write integrity checks (now active by default in
`DefaultChunkHandler`).

### New `BatchTracker` contract

Batch state lives behind `NETipar\Chunky\Contracts\BatchTracker`, with
two implementations (`DatabaseBatchTracker`, `FilesystemBatchTracker`).
The package handles binding via the service provider; you don't need
to change anything unless you've written a custom batch backend, in
which case implement the new contract.

### `metadata` validation tightened

`InitiateUploadRequest` now applies the new `ValidMetadata` rule, which
caps per-value length (default 1KB), total payload size (default 16KB),
and rejects non-scalar values (arrays, objects, resources). Configurable
under `chunky.metadata.*`. Most apps won't notice; apps that send large
metadata blobs will get 422 — adjust the config to relax the limits.

### `BatchStatus::Cancelled` enum case

The enum gained a `Cancelled` terminal case. Custom code that
exhaustively matches `BatchStatus` will hit a TypeError until updated.

## Upgrading to 0.14 from 0.13

### `user_id` columns are now `string`

`chunked_uploads.user_id` and `chunky_batches.user_id` switched from
`unsignedBigInteger` to `string`, so any user-id shape (auto-increment
int, UUID, ULID) works out of the box.

**Existing installations need an `ALTER TABLE`:**

```php
Schema::table('chunked_uploads', function (Blueprint $table) {
    $table->string('user_id')->nullable()->change();
});
Schema::table('chunky_batches', function (Blueprint $table) {
    $table->string('user_id')->nullable()->change();
});
```

Existing integer values are preserved (stored as their string
representation). The `?int $userId` properties on `UploadMetadata`,
`BatchMetadata`, `BatchCompleted`, `BatchPartiallyCompleted`, and
`ChunkyManager::resolveUserId()` are now `?string`. If you compared
user IDs in custom code, switch from `(int) $a === (int) $b` to a
plain `(string) $a === (string) $b`.

### `declare(strict_types=1);` everywhere

Every PHP file in `src/` now declares strict types. Callers passing
the wrong type at the package boundary get a `TypeError` instead of a
silently-wrong cast. **Common case**: passing `file_size` as a string
(`'1024'`) to `Chunky::initiate()` no longer auto-coerces — cast at
the call site (`(int) $fileSize`).

### Frontend listener type generalised

The internal `Set<Function>` listener storage in `ChunkUploader` and
`BatchUploader` is now `Map<keyof EventMap, Set<EventCallback>>`. The
public `on()` overloads are unchanged, so consumer code still works.
If you held a `Function` reference manually (uncommon), retype to
`(data: T) => void`.

## Upgrading to 0.13 from 0.12

### Broadcast payload sanitisation

`UploadCompleted` / `UploadFailed` no longer broadcast `disk` or
`finalPath` by default. Frontends that read those fields from the
broadcast payload need to either:

- Set `chunky.broadcasting.expose_internal_paths = true` in
  `config/chunky.php` to opt back in; **or**
- Fetch them from `GET /api/chunky/upload/{uploadId}` server-side
  instead.

The Livewire component honours the same flag — its
`chunky-upload-completed` Livewire event payload no longer includes
`finalPath` / `disk` by default.

### `chunky.lock_driver = 'cache'` requires a lock-capable cache

If you switch to the cache-backed locking mode for cloud disks (S3,
GCS), the configured `cache.default` must be a driver that supports
atomic locks (`redis`, `memcached`, `database`, `dynamodb`). The
`array` and `file` drivers silently no-op on `Cache::lock()` and
would let upload races slip through — the service provider now
boot-fails with a clear error in that combination.

### `Models\ChunkedUpload::markChunkUploaded()` signature

The unused `?string $checksum` parameter was removed. If you were
calling this directly (most consumers don't — they go through the
tracker), drop the second argument.

## Upgrading to 0.12 from 0.11

### Authorization required for non-anonymous uploads

The HTTP routes, Livewire component, and broadcast channels now all
delegate to the bound `Authorizer` interface. The `DefaultAuthorizer`
enforces `auth()->id() === upload->userId` on owned uploads.
Anonymous uploads (no `user_id`) keep the v0.11 behaviour for
backward compatibility.

If you don't want auth checks (everyone can access everyone's
uploads), bind a custom `Authorizer` whose `canAccess*()` methods
always return `true`. See README → "Authorization" for the full
interface contract.

### `FilesystemTracker` refuses to boot on non-local disks

If `chunky.tracker = 'filesystem'` and the configured `chunky.disk`
doesn't expose a real local path (S3, GCS), the tracker now throws on
boot. Switch `chunky.tracker = 'database'`, or stay on filesystem and
set `chunky.lock_driver = 'cache'` (added in v0.13.0).

### `UploadStatus` API response trimmed

`GET /api/chunky/upload/{uploadId}` no longer includes `disk`,
`final_path`, or `user_id` in the response. Use server-side
`Chunky::status($uploadId)` to access them.
