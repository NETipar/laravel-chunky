# Configuration reference

> 🇭🇺 Magyar változat: [docs/hu/configuration.md](../hu/configuration.md)

Every key in `config/chunky.php` is validated at boot into a typed
`ChunkyConfig` — an out-of-range or wrong-typed value fails fast with the
offending key. Publish the config with:

```bash
php artisan chunky:install   # or: php artisan vendor:publish --tag=chunky-config
```

## Keys

### Tracker & disks

| Key | Default | Notes |
|---|---|---|
| `tracker` | `database` | `database` (Eloquent) or `filesystem` (JSON on disk, zero migrations). |
| `disk` | `local` | Disk for the final assembled files (a profile can override per upload). |
| `chunks.disk` | `local` | Disk the temporary chunks are written to. |
| `chunks.directory` | `chunky/chunks` | Where chunks live before assembly. |
| `chunks.size` | 5 MB | Bytes per chunk. |

### Limits

| Key | Default | Notes |
|---|---|---|
| `limits.max_file_size` | 2 GB | Max accepted `file_size`; a profile's `maxFileSize()` overrides it. `0` = unlimited. |
| `limits.expiration_hours` | 24 | Unfinished uploads expire after this and are removed by `chunky:cleanup`. |
| `limits.metadata_max_keys` | 50 | Cap on client-supplied metadata keys (DoS guard). |

### Assembly

| Key | Default | Notes |
|---|---|---|
| `assembly.mode` | `auto` | `sync` (in-request), `queue` (job), or `auto` (sync below the threshold, else queue). |
| `assembly.sync_threshold` | 256 MB | In `auto`, files up to this assemble synchronously. |
| `assembly.connection` | `null` | Queue connection for the assemble job (`null` = default). Empty string is treated as `null`. |
| `assembly.queue` | `null` | Queue name for the assemble job. |
| `assembly.timeout` | 600 | Per-attempt job timeout (seconds). |
| `assembly.tries` / `assembly.backoff` | 3 / 30 | Job retry attempts and backoff (seconds). |
| `assembly.stale_claim_seconds` | 900 | After this, a crashed worker's assembly claim can be taken over. |

### Locking

| Key | Default | Notes |
|---|---|---|
| `locking.driver` | `auto` | `auto` (cache lock if available, else flock), `cache`, or `flock`. |
| `locking.timeout` | 10 | Seconds to wait for a lock before `503 lock_timeout`. |

`locking.driver = cache` requires a store with atomic locks (e.g. Redis); the
package refuses to boot on `array`/`file`/`null`.

### Integrity, resume, idempotency

| Key | Default | Notes |
|---|---|---|
| `integrity.algorithm` | `sha256` | Hash used to verify a chunk's optional `checksum`. |
| `integrity.required` | `false` | Require a checksum on every chunk. When off, a supplied checksum is still verified. |
| `integrity.require_full_file` | `false` | Require the whole-file `file_checksum` (always SHA-256) before an upload may complete; without it the completing chunk returns `422 validation_failed`. When off, a supplied `file_checksum` is still verified after assembly. |
| `resume.fingerprint` | `true` | Enable fingerprint-based resume across reloads. |
| `idempotency.ttl` | 300 | Seconds a chunk response is cached for byte-exact retry replay. |

### Transports (direct-to-S3)

| Key | Default | Notes |
|---|---|---|
| `transports.direct_s3.disk` | `null` | Name of the s3 filesystem disk that direct uploads target. Must be set to use a `direct_s3` profile; requires `aws/aws-sdk-php`. |
| `transports.direct_s3.url_ttl` | 3600 | Presigned part URL lifetime in seconds. |

A profile opts in with `UploadProfile::transport(): 'direct_s3'`. Constraints
(enforced at initiate): `chunks.size ≥ 5 MB`, at most 10000 parts per file.
S3-compatible targets (MinIO, Cloudflare R2) work through the disk's
`endpoint` + `use_path_style_endpoint` settings — MinIO is supported, R2 is
best effort. See the CORS recipe below; the wire details live in
[protocol.md](protocol.md).

### Routes & throttling

| Key | Default | Notes |
|---|---|---|
| `routes.enabled` | `true` | Register the package routes. |
| `routes.prefix` | `api/chunky` | URL prefix. |
| `routes.middleware` | `['api']` | Middleware for the route group. |
| `throttle.initiate` / `throttle.chunks` | `30,1` / `300,1` | Rate limits (requests, minutes) if you apply the `throttle` middleware. |

### Authorization, profiles, broadcasting, cleanup

| Key | Default | Notes |
|---|---|---|
| `authorization.authorizer` | `DefaultAuthorizer::class` | Ownership policy. Swap for a custom `Authorizer`. |
| `profiles` | `[]` | `name => UploadProfile::class` map. |
| `broadcasting.enabled` | `false` | Opt-in. When off, clients poll the status endpoint. |
| `broadcasting.except` | high-frequency events | Event classes that never broadcast (defaults to `ChunkUploaded`, `ChunkUploadFailed`, `BatchInitiated`). |
| `broadcasting.queue` | `null` | Queue for broadcast events. |
| `cleanup.enabled` | `true` | Whether `chunky:cleanup` is active. |

## Recipes

**Zero-infrastructure (default).** `tracker=database`, `assembly.mode=auto`,
`broadcasting.enabled=false`. Files assemble in-request; the client gets the
result back directly. Nothing else to run.

**Large files at scale.** Set `assembly.mode=queue` (or keep `auto` and rely on
the threshold), run a queue worker, and put chunks/finals on S3
(`disk`/`chunks.disk`). Use `locking.driver=cache` with Redis if you run
multiple app servers.

**Live progress across tabs/devices.** Set `broadcasting.enabled=true`, wire up
Echo/Reverb, and (optionally) remove events from `broadcasting.except` to push
more granular updates.

**Direct-to-S3 (bucket CORS — critical).** With the `direct_s3` transport the
browser PUTs parts straight to the bucket, so its CORS configuration must
allow the PUT **and** expose the `ETag` header — without `ExposeHeaders: ETag`
the client cannot collect the part ETags and complete will never succeed:

```json
[
    {
        "AllowedOrigins": ["https://app.example.com"],
        "AllowedMethods": ["PUT"],
        "AllowedHeaders": ["*"],
        "ExposeHeaders": ["ETag"],
        "MaxAgeSeconds": 3600
    }
]
```

Also configure an `AbortIncompleteMultipartUpload` lifecycle rule (e.g. 7
days) as a safety net for uploads that are never completed or aborted.

**Housekeeping.** Schedule `php artisan chunky:cleanup` (e.g. hourly) to remove
expired, unfinished uploads and their chunks (for direct uploads it also aborts
the remote multipart upload). Run `php artisan chunky:doctor` to sanity-check
disks, the queue worker, broadcasting, locking, the tracker, and — when
configured — the direct_s3 presigning.
