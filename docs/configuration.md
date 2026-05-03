# Configuration reference

Every key in `config/chunky.php` is commented inline; this document
groups them for skimming and adds copy-paste recipes for the common
deployment shapes. Publish the config with:

```bash
php artisan vendor:publish --tag=chunky-config
```

The config is grouped into 10 sections (since v0.18). Older flat keys
were renamed — see [UPGRADE.md](../UPGRADE.md) for the migration table.

## `.env` essentials

```
CHUNKY_TRACKER=database          # database | filesystem
CHUNKY_DISK=local                # any Laravel filesystem disk
CHUNKY_CHUNK_SIZE=1048576        # 1MB
CHUNKY_BROADCASTING=false        # opt-in WebSocket broadcasting
CHUNKY_LOCK_DRIVER=flock         # flock | cache (cache for cloud disks)
CHUNKY_STAGING_DIRECTORY=        # null = sys_get_temp_dir()
CHUNKY_CACHE_PREFIX=chunky:v1:   # versioned cache-key prefix
CHUNKY_ASSEMBLY_CONNECTION=      # null = default | sync | <named connection>
CHUNKY_ASSEMBLY_QUEUE=           # null = default queue
```

## Storage

| Key | Default | Description |
|-----|---------|-------------|
| `tracker` | `database` | Tracking driver: `database` or `filesystem` |
| `disk` | `local` | Laravel filesystem disk for chunk + final storage |
| `storage.temp_directory` | `chunky/temp` | Path under the disk for in-flight chunk metadata |
| `storage.final_directory` | `chunky/uploads` | Path for assembled files |
| `storage.staging_directory` | `null` (= `sys_get_temp_dir()`) | Local scratch path used while assembling. Set when `/tmp` can't fit the largest expected upload (cloud disks especially). |

## Chunks

| Key | Default | Description |
|-----|---------|-------------|
| `chunks.size` | `1048576` (1MB) | Chunk size in bytes |
| `chunks.verify_integrity` | `true` | SHA-256 chunk checksum middleware |

## Lifecycle

| Key | Default | Description |
|-----|---------|-------------|
| `lifecycle.expiration_minutes` | `360` (6h) | Upload TTL after which the cleanup sweep removes it |
| `lifecycle.assembly_stale_after_minutes` | `10` | An `Assembling` upload older than this is considered stale and may be re-claimed by a retrying AssembleFileJob |
| `lifecycle.auto_cleanup` | `true` | Schedule the cleanup command to run daily |

## Limits / DOS protection

| Key | Default | Description |
|-----|---------|-------------|
| `limits.max_file_size` | `0` | Per-upload byte cap (0 = unlimited) |
| `limits.max_chunks_per_upload` | `100_000` | Hard cap on `ceil(file_size / chunks.size)` |
| `limits.max_files_per_batch` | `100` | Files allowed in a single batch |
| `limits.allowed_mimes` | `[]` | Whitelist of MIME types (empty = all) |

## Metadata

User-supplied per-upload metadata that lands in the tracker AND in
broadcast payloads:

| Key | Default | Description |
|-----|---------|-------------|
| `metadata.max_keys` | `16` | Max top-level keys |
| `metadata.max_value_length` | `1024` | Bytes per string value (0 = unlimited) |
| `metadata.max_total_size` | `16384` | Bytes for the serialized payload (0 = unlimited) |
| `metadata.allowed_value_types` | `['string','integer','boolean','double','NULL']` | Allowed `gettype()` results |

## Locking

| Key | Default | Description |
|-----|---------|-------------|
| `locking.driver` | `flock` | `flock` (local-disk only) or `cache` (Cache::lock-backed; required for S3/multi-server) |
| `locking.ttl_seconds` | `30` | TTL for held locks |
| `locking.wait_seconds` | `5` | Block timeout when contending for a lock |

## Idempotency

| Key | Default | Description |
|-----|---------|-------------|
| `idempotency.ttl_seconds` | `300` | How long a chunk POST response is cached for replay |

## Cache

| Key | Default | Description |
|-----|---------|-------------|
| `cache.prefix` | `chunky:v1:` | Versioned prefix for every lock / idempotency / counter key |

## Authorization

| Key | Default | Description |
|-----|---------|-------------|
| `authorization.allow_anonymous` | `true` | Allow access to uploads with no recorded `userId`. Set to `false` to require auth on every chunky route. |

## Routes / rate limiting

| Key | Default | Description |
|-----|---------|-------------|
| `routes.prefix` | `api/chunky` | Route group prefix |
| `routes.middleware` | `['api', 'throttle:chunky']` | Group middleware. Add `auth:sanctum` for an authenticated app. |
| `throttle.attempts` | `120` | Per-minute request cap (set to 0 to disable) |
| `throttle.decay_minutes` | `1` | Throttle window |

## Broadcasting

| Key | Default | Description |
|-----|---------|-------------|
| `broadcasting.enabled` | `false` | Enable WebSocket broadcasting |
| `broadcasting.channel_prefix` | `chunky` | Private channel prefix |
| `broadcasting.queue` | `null` | Broadcast queue name |
| `broadcasting.register_channels` | `true` | Auto-register `Broadcast::channel()` callbacks via the bound Authorizer |
| `broadcasting.expose_internal_paths` | `false` | Include `disk` / `finalPath` in `UploadCompleted` / `UploadFailed` broadcast payloads |
| `broadcasting.events.{Key}` | mixed | Per-event opt-in. `UploadCompleted`, `UploadFailed`, `BatchCompleted`, `BatchPartiallyCompleted`, `BatchCancelled` default to `true`. Per-chunk events default to `false`. |

## Assembly job

After the final chunk lands, an `AssembleFileJob` stitches the chunks
into the final file. Tune the queue routing and retry envelope to fit
your workload — the defaults assume a typical 1GB-ish upload on a
local SSD.

| Key | Default | Description |
|-----|---------|-------------|
| `assembly.connection` | `null` (= `config('queue.default')`) | Queue connection. Set to `sync` to run the assembly in-process (no worker required), or to a named connection from `config/queue.php` to route just the chunky assembly off the default queue. |
| `assembly.queue` | `null` (= default queue on the chosen connection) | Queue name |
| `assembly.tries` | `3` | Retry count after a worker crash / save-callback throw |
| `assembly.backoff` | `30` | Seconds between retry attempts |
| `assembly.timeout` | `600` | Per-attempt timeout (seconds). The queue worker default 60s is too short for a multi-GB assembly. |

## Metrics

| Key | Default | Description |
|-----|---------|-------------|
| `metrics.<event>` | `null` | Optional handler (class string or closure) for `chunk_uploaded`, `chunk_upload_failed`, `assembly_started`, `assembly_completed`, `assembly_failed`. Class strings are `config:cache`-compatible and resolved through the container. |

## Recipes

### Large videos (4MB chunks, 5GB cap, 3-day retention)

```php
'chunks' => ['size' => 4 * 1024 * 1024],
'limits' => [
    'max_file_size' => 5 * 1024 * 1024 * 1024,
    'max_chunks_per_upload' => 200_000,
    'allowed_mimes' => ['video/mp4', 'video/quicktime', 'video/webm'],
],
'lifecycle' => ['expiration_minutes' => 4320], // 3 days
```

### S3 / cloud disk

```php
'disk' => 's3',
'locking' => ['driver' => 'cache'],   // flock can't work on cloud disks
'storage' => ['staging_directory' => '/var/chunky-staging'],
```

### Authenticated routes

```php
'routes' => [
    'middleware' => ['api', 'auth:sanctum', 'throttle:chunky'],
],
'authorization' => ['allow_anonymous' => false],
```

### Per-chunk progress broadcast (e.g. dashboard)

```php
'broadcasting' => [
    'enabled' => true,
    'events' => [
        'ChunkUploaded' => true,        // expensive — only enable for low-throughput dashboards
    ],
],
```

### Synchronous assembly (no queue worker)

For dev environments, small uploads, or apps that don't run a queue
worker, route the assembly job to the `sync` connection so it runs
in-process when the final chunk lands. The rest of your app's queues
are unaffected.

```php
'assembly' => [
    'connection' => 'sync',
],
```

Or via `.env`:

```
CHUNKY_ASSEMBLY_CONNECTION=sync
```

### Dedicated upload queue

Keep large-file assemblies off the default queue without going sync:

```php
'assembly' => [
    'connection' => 'redis',
    'queue' => 'uploads',
],
```
