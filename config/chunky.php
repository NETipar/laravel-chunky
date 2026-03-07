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

    // Route config
    'routes' => [
        'prefix' => 'api/chunky',
        'middleware' => ['api'],
    ],

    // Chunk integrity verification (checksum)
    'verify_integrity' => true,

    // Automatic cleanup
    'auto_cleanup' => true,
];
