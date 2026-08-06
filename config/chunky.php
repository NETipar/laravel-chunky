<?php

declare(strict_types=1);

use NETipar\Chunky\Authorization\DefaultAuthorizer;
use NETipar\Chunky\Events\BatchInitiated;
use NETipar\Chunky\Events\ChunkUploaded;
use NETipar\Chunky\Events\ChunkUploadFailed;

return [

    /*
    |--------------------------------------------------------------------------
    | Tracker
    |--------------------------------------------------------------------------
    | Where upload/batch state lives: 'database' (Eloquent) or 'filesystem'
    | (JSON on disk, zero-migration onboarding).
    */
    'tracker' => env('CHUNKY_TRACKER', 'database'),

    // Disk for the final assembled files.
    'disk' => env('CHUNKY_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Chunks
    |--------------------------------------------------------------------------
    */
    'chunks' => [
        'disk' => env('CHUNKY_CHUNK_DISK', 'local'),
        'directory' => 'chunky/chunks',
        'size' => (int) env('CHUNKY_CHUNK_SIZE', 5 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'max_file_size' => (int) env('CHUNKY_MAX_FILE_SIZE', 2 * 1024 ** 3),
        'expiration_hours' => 24,
        // Max number of client-supplied metadata keys (DoS guard).
        'metadata_max_keys' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Assembly
    |--------------------------------------------------------------------------
    | mode: 'sync' assembles in-request (result returned immediately),
    | 'queue' dispatches a job, 'auto' picks per file size (below the
    | sync_threshold => sync, above => queue).
    */
    'assembly' => [
        'mode' => env('CHUNKY_ASSEMBLY_MODE', 'auto'),
        'sync_threshold' => 256 * 1024 * 1024,
        'connection' => env('CHUNKY_ASSEMBLY_CONNECTION'),
        'queue' => env('CHUNKY_ASSEMBLY_QUEUE'),
        'timeout' => 600,
        'tries' => 3,
        'backoff' => 30,
        'stale_claim_seconds' => 900,
    ],

    /*
    |--------------------------------------------------------------------------
    | Locking
    |--------------------------------------------------------------------------
    | driver: 'auto' picks a cache lock when the store supports one, else flock.
    */
    'locking' => [
        'driver' => 'auto',
        'timeout' => 10,
    ],

    'idempotency' => [
        'ttl' => 300,
    ],

    'integrity' => [
        'algorithm' => 'sha256',
        'required' => false,
        // Reject the final chunk unless the client supplied a whole-file
        // SHA-256 via the optional `file_checksum` field.
        'require_full_file' => false,
    ],

    'resume' => [
        'fingerprint' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Transports
    |--------------------------------------------------------------------------
    | The direct_s3 transport uploads chunks straight to S3 with presigned
    | part URLs; Laravel only orchestrates (initiate, URL issuing, complete,
    | abort). Opt in per profile via UploadProfile::transport(). Requires
    | aws/aws-sdk-php and an s3 filesystem disk.
    */
    'transports' => [
        'direct_s3' => [
            'disk' => env('CHUNKY_DIRECT_S3_DISK'),
            // Presigned part URL lifetime in seconds.
            'url_ttl' => 3600,
        ],
    ],

    'authorization' => [
        'authorizer' => DefaultAuthorizer::class,
    ],

    'routes' => [
        'enabled' => true,
        'prefix' => 'api/chunky',
        'middleware' => ['api'],
    ],

    'throttle' => [
        'initiate' => '30,1',
        'chunks' => '300,1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcasting (optional enhancement)
    |--------------------------------------------------------------------------
    | Broadcasting is off by default; the whole package works with polling.
    | When enabled, high-frequency events are excluded by default so turning
    | broadcasting on only emits the rare, useful completion events.
    */
    'broadcasting' => [
        'enabled' => env('CHUNKY_BROADCASTING', false),
        'except' => [
            ChunkUploaded::class,
            ChunkUploadFailed::class,
            BatchInitiated::class,
        ],
        'queue' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Profiles
    |--------------------------------------------------------------------------
    | name => UploadProfile class-string.
    */
    'profiles' => [],

    'cleanup' => [
        'enabled' => true,
    ],
];
