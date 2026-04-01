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

    // Max file size in bytes - 0 = unlimited
    'max_file_size' => 0,

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
    ],
];
