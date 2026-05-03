<?php

declare(strict_types=1);

return [
    // Tracking driver: 'database' | 'filesystem'
    'tracker' => env('CHUNKY_TRACKER', 'database'),

    // Laravel filesystem disk for chunk storage
    'disk' => env('CHUNKY_DISK', 'local'),

    // ------------------------------------------------------------------
    // Storage
    // ------------------------------------------------------------------
    'storage' => [
        // Temp directory for chunks.
        'temp_directory' => 'chunky/temp',

        // Final directory for assembled files.
        'final_directory' => 'chunky/uploads',

        // Local filesystem directory used while assembling chunks into
        // the final file. The DefaultChunkHandler streams chunks through
        // a temp file here BEFORE writing the result to the final disk;
        // for cloud disks (S3, GCS) this means the assembly transiently
        // consumes local-disk space equal to the full upload size.
        //
        // Default null = use sys_get_temp_dir(). Set to a path on a
        // volume with enough free space when accepting uploads larger
        // than your /tmp partition.
        'staging_directory' => env('CHUNKY_STAGING_DIRECTORY'),
    ],

    // ------------------------------------------------------------------
    // Chunk handling
    // ------------------------------------------------------------------
    'chunks' => [
        // Chunk size in bytes. 1MB default keeps the upload-validation
        // burden small even for the largest files. For low-latency
        // networks consider bumping to 4-8MB to reduce round-trips
        // (modern S3 multipart uploads default to 8MB). Must be
        // smaller than PHP's post_max_size.
        'size' => env('CHUNKY_CHUNK_SIZE', 1024 * 1024),

        // SHA-256 chunk integrity verification by the
        // VerifyChunkIntegrity middleware. Set to false to trade
        // post-write integrity for ~5MB+ chunk speedups.
        'verify_integrity' => true,
    ],

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------
    'lifecycle' => [
        // Upload TTL in minutes. Default 6h: long enough for a
        // realistic resumable upload, short enough that abandoned
        // uploads don't accumulate S3 cost.
        'expiration_minutes' => 360,

        // After this many minutes, an upload that has been stuck in the
        // `assembling` status is considered stale and may be re-claimed
        // by a retrying AssembleFileJob.
        'assembly_stale_after_minutes' => 10,

        // Schedule the cleanup command to run daily.
        'auto_cleanup' => true,
    ],

    // ------------------------------------------------------------------
    // Limits / DOS protection
    // ------------------------------------------------------------------
    'limits' => [
        // Max file size in bytes - 0 = unlimited.
        'max_file_size' => 0,

        // Hard cap on the number of chunks any single upload may
        // require. ceil(file_size / chunks.size); rejects pathological
        // initiate calls (file_size = 1TB, chunks.size = 1KB → 1
        // billion chunks).
        'max_chunks_per_upload' => 100_000,

        // Max number of files allowed per batch.
        'max_files_per_batch' => 100,

        // Allowed MIME types - empty = all.
        'allowed_mimes' => [],
    ],

    // ------------------------------------------------------------------
    // Metadata caps
    // ------------------------------------------------------------------
    'metadata' => [
        // Max number of keys in the user-supplied `metadata` array.
        'max_keys' => 16,

        // Max length of any single string value (bytes). 0 = unlimited.
        'max_value_length' => 1024,

        // Max size of the whole serialized metadata payload (bytes).
        // 0 = unlimited.
        'max_total_size' => 16 * 1024,

        // Allowed gettype() results. Defaults to scalar+null. Add
        // 'array' here only if you genuinely need nested structures
        // and have audited the impact on the broadcast payload.
        'allowed_value_types' => ['string', 'integer', 'boolean', 'double', 'NULL'],
    ],

    // ------------------------------------------------------------------
    // Locking
    // ------------------------------------------------------------------
    'locking' => [
        // Locking driver for tracker mutations and batch counter
        // updates:
        //  - 'flock'  (default): file locks against the local disk
        //  - 'cache': Laravel cache-backed locks (Cache::lock()) —
        //             required for cloud disks (S3, GCS, multi-server).
        //             Requires a cache driver that supports atomic
        //             locks (Redis, Memcached, DB, DynamoDB).
        'driver' => env('CHUNKY_LOCK_DRIVER', 'flock'),

        // How long a held lock survives before the runtime forcibly
        // releases it. Should be longer than the slowest critical
        // section.
        'ttl_seconds' => 30,

        // How long to wait for an existing lock holder to release
        // before giving up. Throws Cache\LockTimeoutException, which
        // surfaces as 503 Service Unavailable.
        'wait_seconds' => 5,
    ],

    // ------------------------------------------------------------------
    // Idempotency
    // ------------------------------------------------------------------
    'idempotency' => [
        // A chunk POST is cached by (uploadId, chunkIndex,
        // Idempotency-Key OR checksum) and replayed within this TTL on
        // retry. Prevents duplicate ChunkUploaded events and duplicate
        // AssembleFileJob dispatches when the network retries a
        // request the server actually accepted.
        'ttl_seconds' => 300,
    ],

    // ------------------------------------------------------------------
    // Assembly job
    // ------------------------------------------------------------------
    // AssembleFileJob runs after the final chunk lands and stitches
    // the chunks into the final file. Tuneable for slow disks / large
    // uploads — the defaults fit a typical 1GB-ish upload on local SSD.
    'assembly' => [
        // Queue connection for the assemble job. null = default
        // connection from config('queue.default'). Set to 'sync' to
        // run assembly in-process (no queue worker required) — useful
        // for small uploads, dev environments, or apps that already
        // run a synchronous request lifecycle. Set to a named
        // connection from config/queue.php (e.g. 'redis-uploads',
        // 'sqs-large-files') to route just the chunky assembly off
        // the default queue without affecting the rest of the app.
        'connection' => env('CHUNKY_ASSEMBLY_CONNECTION'),

        // Queue name for the assemble job. null = default queue.
        'queue' => env('CHUNKY_ASSEMBLY_QUEUE'),

        // Retry count after a worker crash / save-callback throw.
        'tries' => 3,

        // Seconds between retry attempts.
        'backoff' => 30,

        // Per-attempt timeout. The queue worker default (60s) is far
        // too short for a multi-GB assembly — bump this to fit your
        // largest expected file.
        'timeout' => 600,
    ],

    // ------------------------------------------------------------------
    // Cache
    // ------------------------------------------------------------------
    'cache' => [
        // Versioned prefix for every lock / idempotency / counter key.
        // Bump the version segment in code when payload shape evolves
        // — operators don't need to know.
        'prefix' => env('CHUNKY_CACHE_PREFIX', 'chunky:v1:'),
    ],

    // ------------------------------------------------------------------
    // Metrics / observability hooks
    // ------------------------------------------------------------------
    // Each entry is an optional handler that receives an associative
    // payload at the named lifecycle event. Use these to bridge to
    // Datadog / Prometheus / StatsD without forking the package.
    // Exceptions thrown by the handler are swallowed.
    //
    // Two handler shapes:
    //   1. Class string (recommended): \App\Metrics\ChunkUploaded::class
    //      Resolved via the container, then __invoke or handle() is
    //      called. config:cache-compatible.
    //   2. Closure: fn (array $p) => …
    //      Kept for backward compat; breaks config:cache.
    'metrics' => [
        'chunk_uploaded' => null,
        'chunk_upload_failed' => null,
        'assembly_started' => null,
        'assembly_completed' => null,
        'assembly_failed' => null,
    ],

    // ------------------------------------------------------------------
    // Class-based upload contexts (auto-registered on boot)
    // ------------------------------------------------------------------
    // 'contexts' => [
    //     App\Chunky\ProfileAvatarContext::class,
    //     App\Chunky\DocumentContext::class,
    // ],
    'contexts' => [],

    // ------------------------------------------------------------------
    // Authorization
    // ------------------------------------------------------------------
    'authorization' => [
        // When true (default), uploads / batches that have no recorded
        // userId are accessible to any caller — backward compatible for
        // setups without auth middleware. Set to false to require an
        // authenticated user for every chunky route.
        'allow_anonymous' => true,
    ],

    // ------------------------------------------------------------------
    // Routes / rate limiting
    // ------------------------------------------------------------------
    'routes' => [
        'prefix' => 'api/chunky',
        // Add 'auth:sanctum' or your auth middleware to protect the
        // upload endpoints. The throttle:chunky middleware uses the
        // RateLimiter registered by the service provider.
        'middleware' => ['api', 'throttle:chunky'],
    ],

    'throttle' => [
        // Set attempts to 0 to disable rate limiting.
        'attempts' => 120,
        'decay_minutes' => 1,
    ],

    // ------------------------------------------------------------------
    // Broadcasting (Laravel Echo / Reverb / Pusher)
    // ------------------------------------------------------------------
    'broadcasting' => [
        'enabled' => env('CHUNKY_BROADCASTING', false),
        'channel_prefix' => 'chunky',
        'queue' => null,

        // When true (default), Chunky auto-registers `Broadcast::channel()`
        // callbacks for upload/batch/user channels. Set to false if you
        // want to register the channel auth callbacks yourself in your
        // app's routes/channels.php.
        'register_channels' => true,

        // Set to true to include server-internal fields (the storage
        // `disk` name and the absolute `finalPath`) in the
        // UploadCompleted/UploadFailed broadcast payloads. Most apps
        // don't need this on the wire.
        'expose_internal_paths' => false,

        // Cache the upload/batch lookup performed by the broadcast
        // channel auth callbacks for this many seconds. Without it,
        // a client disconnect/reconnect storm would fan out to the
        // tracker on every subscription auth. Set to 0 to disable.
        'auth_cache_ttl_seconds' => 30,

        // Per-event broadcast opt-in. Every Chunky event extends
        // AbstractChunkyEvent and gates broadcasting on this map. The
        // four "completion" events default to true to preserve the
        // v0.17 behaviour; per-chunk events default to false because
        // they fire on every chunk write — turning them on is fine for
        // a low-throughput dashboard but expensive at scale.
        'events' => [
            'UploadInitiated' => false,
            'ChunkUploaded' => false,
            'ChunkUploadFailed' => false,
            'FileAssembled' => false,
            'UploadCompleted' => true,
            'UploadFailed' => true,
            'BatchInitiated' => false,
            'BatchCompleted' => true,
            'BatchPartiallyCompleted' => true,
            'BatchCancelled' => true,
        ],
    ],
];
