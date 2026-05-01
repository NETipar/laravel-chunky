# Upgrade Guide

Migration notes for breaking changes between minor versions while the
package is in `0.x`. Patch releases (`0.x.y`) never contain breaking
changes — refer to the [CHANGELOG](CHANGELOG.md) for the full log.

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
