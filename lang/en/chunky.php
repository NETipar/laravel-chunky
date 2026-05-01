<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Chunky Language Lines
|--------------------------------------------------------------------------
|
| Customer-facing strings used in HTTP responses. The exception messages
| inside the package itself (ChunkyException, UploadExpiredException) carry
| richer dynamic context (upload ids, status values) and are not localised
| — they're surfaced via $exception->getMessage() in the JSON `message`
| field of error responses, which is more useful for log analysis than a
| translated string would be.
|
| Publish this file with:
|     php artisan vendor:publish --tag=chunky-lang
|
*/

return [
    'http' => [
        'upload_not_found' => 'Upload not found.',
        'upload_finalized' => 'Upload not found or already finalized.',
        'batch_not_found' => 'Batch not found.',
        'busy' => 'Upload temporarily busy, please retry.',
    ],
];
