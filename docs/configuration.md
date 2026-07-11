# Configuration reference

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
| `resume.fingerprint` | `true` | Enable fingerprint-based resume across reloads. |
| `idempotency.ttl` | 300 | Seconds a chunk response is cached for byte-exact retry replay. |

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

**Housekeeping.** Schedule `php artisan chunky:cleanup` (e.g. hourly) to remove
expired, unfinished uploads and their chunks. Run `php artisan chunky:doctor`
to sanity-check disks, assembly mode, and broadcasting.
