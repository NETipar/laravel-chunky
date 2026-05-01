<?php

return [
    // Tracking driver: 'database' | 'filesystem'
    'tracker' => env('CHUNKY_TRACKER', 'database'),

    // Laravel filesystem disk for chunk storage
    'disk' => env('CHUNKY_DISK', 'local'),

    // Chunk size in bytes - default 1MB (must be smaller than PHP's post_max_size)
    'chunk_size' => env('CHUNKY_CHUNK_SIZE', 1024 * 1024),

    // Temp directory for chunks
    'temp_directory' => 'chunky/temp',

    // Final directory for assembled files
    'final_directory' => 'chunky/uploads',

    // Local filesystem directory used while assembling chunks into the
    // final file. The DefaultChunkHandler streams chunks through a temp
    // file here BEFORE writing the result to the final disk; for cloud
    // disks (S3, GCS) this means the assembly transiently consumes
    // local-disk space equal to the full upload size.
    //
    // Default null = use sys_get_temp_dir(). Set to a path on a volume
    // with enough free space when accepting uploads larger than your
    // /tmp partition (e.g. mount a dedicated EBS volume and point this
    // at /var/chunky-staging).
    'staging_directory' => env('CHUNKY_STAGING_DIRECTORY'),

    // Chunk expiration in minutes
    'expiration' => 1440,

    // After this many minutes, an upload that has been stuck in the
    // `assembling` status is considered stale and may be re-claimed by a
    // retrying AssembleFileJob. Default: 10 minutes — long enough for any
    // realistic single-file assembly, short enough to recover quickly when
    // a worker crashed mid-job.
    'assembly_stale_after_minutes' => 10,

    // Bypass FilesystemTracker's boot-time check that the configured disk
    // exposes a local path. Only set this to true if you have provided your
    // own external locking mechanism for the tracker mutation paths.
    'skip_local_disk_guard' => false,

    // Locking driver for tracker mutations and batch counter updates:
    //  - 'flock'  (default): file locks against the local disk; works
    //             out of the box on local-disk setups, free of charge.
    //  - 'cache': Laravel cache-backed locks (Cache::lock()); works
    //             with cloud disks (S3, GCS, multi-server). Requires a
    //             cache driver that supports atomic locks (Redis,
    //             Memcached, DB, DynamoDB).
    'lock_driver' => env('CHUNKY_LOCK_DRIVER', 'flock'),

    // How long a held lock survives before the runtime forcibly releases
    // it. Should be longer than the slowest critical section (chunk write +
    // metadata update on slow storage).
    'lock_ttl_seconds' => 30,

    // How long to wait for an existing lock holder to release before
    // giving up. The runtime throws Illuminate\Contracts\Cache\LockTimeoutException
    // if exceeded — surfaces as 500 to the caller, who can retry.
    'lock_wait_seconds' => 5,

    // Idempotency for chunk POSTs. When enabled, a chunk POST is cached
    // by (uploadId, chunkIndex, Idempotency-Key OR checksum) and
    // subsequent retries within `idempotency_ttl_seconds` replay the
    // cached response. Prevents duplicate ChunkUploaded events and
    // duplicate AssembleFileJob dispatches when the network retries a
    // request the server actually accepted.
    'idempotency' => [
        'enabled' => true,
    ],
    'idempotency_ttl_seconds' => 300,

    // Observability hooks. Each entry is an optional callable that
    // receives an associative payload at the named lifecycle event. Use
    // these to bridge to Datadog / Prometheus / StatsD / your own
    // logging without forking the package. Exceptions thrown by the
    // callbacks are swallowed so a metrics bug cannot break uploads.
    //
    // Example:
    //   'metrics' => [
    //       'chunk_uploaded' => fn (array $p) =>
    //           Datadog::histogram('chunky.chunk_upload_ms', $p['duration_ms']),
    //       'assembly_completed' => fn (array $p) =>
    //           Datadog::histogram('chunky.assembly_ms', $p['duration_ms']),
    //   ],
    'metrics' => [
        'chunk_uploaded' => null,
        'chunk_upload_failed' => null,
        'assembly_started' => null,
        'assembly_completed' => null,
        'assembly_failed' => null,
    ],

    // Max file size in bytes - 0 = unlimited
    'max_file_size' => 0,

    // Max number of files allowed per batch (DOS protection — without it
    // a malicious caller could request a billion-file batch and exhaust
    // memory/storage during validation).
    'max_files_per_batch' => 1000,

    // Allowed MIME types - empty = all
    'allowed_mimes' => [],

    // Caps on the user-supplied `metadata` array. Each upload's metadata
    // is persisted in the tracker AND echoed in the broadcast event
    // payload, so an unbounded array can balloon DB rows and broadcast
    // messages.
    'metadata' => [
        'max_keys' => 50,
    ],

    // Class-based upload contexts (auto-registered on boot)
    // 'contexts' => [
    //     App\Chunky\ProfileAvatarContext::class,
    //     App\Chunky\DocumentContext::class,
    // ],
    'contexts' => [],

    // Route config
    // Add 'auth:sanctum' or your auth middleware to protect upload endpoints:
    // 'middleware' => ['api', 'auth:sanctum'],
    'routes' => [
        'prefix' => 'api/chunky',
        'middleware' => ['api'],
    ],

    // Chunk integrity verification (checksum)
    'verify_integrity' => true,

    // Automatic cleanup
    'auto_cleanup' => true,

    // Broadcasting (Laravel Echo / WebSocket)
    // Enable to broadcast UploadCompleted, BatchCompleted, BatchPartiallyCompleted
    // via private channels. Requires Laravel broadcasting to be configured.
    'broadcasting' => [
        'enabled' => env('CHUNKY_BROADCASTING', false),
        'channel_prefix' => 'chunky',
        'queue' => null,
        'user_channel' => true,
        // When true (default), Chunky auto-registers `Broadcast::channel()`
        // callbacks for upload/batch/user channels. The callbacks delegate
        // to the bound Authorizer service. Set to false if you want to
        // register the channel auth callbacks yourself (e.g. in your app's
        // routes/channels.php).
        'register_channels' => true,

        // Set to true to include server-internal fields (the storage
        // `disk` name and the absolute `finalPath` on disk) in the
        // UploadCompleted/UploadFailed broadcast payloads. Most apps
        // don't need this on the wire — keep it false unless a consumer
        // genuinely depends on it.
        'expose_internal_paths' => false,
    ],
];
