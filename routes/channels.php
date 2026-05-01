<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\ChunkyManager;

/*
|--------------------------------------------------------------------------
| Chunky Broadcast Channels
|--------------------------------------------------------------------------
|
| These callbacks authorise subscriptions to the private channels Chunky
| broadcasts on. They are auto-registered by ChunkyServiceProvider and use
| the same Authorizer service the HTTP request guards use, so a custom
| binding flows through both surfaces.
|
*/

$prefix = config('chunky.broadcasting.channel_prefix', 'chunky');

Broadcast::channel("{$prefix}.uploads.{uploadId}", function (Authenticatable $user, string $uploadId) {
    $manager = app(ChunkyManager::class);
    $upload = $manager->status($uploadId);

    if (! $upload) {
        return false;
    }

    return app(Authorizer::class)->canAccessUpload($user, $upload);
});

Broadcast::channel("{$prefix}.batches.{batchId}", function (Authenticatable $user, string $batchId) {
    $manager = app(ChunkyManager::class);
    $batch = $manager->getBatchStatus($batchId);

    if (! $batch) {
        return false;
    }

    return app(Authorizer::class)->canAccessBatch($user, $batch);
});

Broadcast::channel("{$prefix}.user.{userId}", function (Authenticatable $user, int|string $userId) {
    $authId = $user->getAuthIdentifier();

    // String comparison covers int IDs, UUIDs, and ULIDs uniformly.
    return $authId !== null && (string) $authId === (string) $userId;
});
