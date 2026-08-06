<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\Services\BatchService;
use NETipar\Chunky\Services\UploadService;

Broadcast::channel('chunky.upload.{uploadId}', function (Authenticatable $user, string $uploadId): bool {
    $upload = app(UploadService::class)->find($uploadId);

    return $upload !== null && app(Authorizer::class)->owns($user, $upload);
});

Broadcast::channel('chunky.batch.{batchId}', function (Authenticatable $user, string $batchId): bool {
    $batch = app(BatchService::class)->find($batchId);

    return $batch !== null && app(Authorizer::class)->ownsBatch($user, $batch);
});
