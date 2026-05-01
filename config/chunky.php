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

    // Max file size in bytes - 0 = unlimited
    'max_file_size' => 0,

    // Max number of files allowed per batch (DOS protection — without it
    // a malicious caller could request a billion-file batch and exhaust
    // memory/storage during validation).
    'max_files_per_batch' => 1000,

    // Allowed MIME types - empty = all
    'allowed_mimes' => [],

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
    ],
];
