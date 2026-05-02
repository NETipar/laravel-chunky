<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Support\CacheKeys;

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
| Each callback caches the upload/batch lookup for 30 seconds so a client
| disconnect/reconnect storm does not hammer the tracker. The window is
| short enough that a status flip (e.g. cancel) propagates before the
| next subscription auth.
|
*/

$prefix = config('chunky.broadcasting.channel_prefix', 'chunky');
$authCacheTtl = (int) config('chunky.broadcasting.auth_cache_ttl_seconds', 30);

Broadcast::channel("{$prefix}.uploads.{uploadId}", function (Authenticatable $user, string $uploadId) use ($authCacheTtl) {
    $upload = $authCacheTtl > 0
        ? Cache::remember(
            CacheKeys::prefix()."auth:upload:{$uploadId}",
            $authCacheTtl,
            fn () => app(ChunkyManager::class)->status($uploadId),
        )
        : app(ChunkyManager::class)->status($uploadId);

    if (! $upload) {
        return false;
    }

    return app(Authorizer::class)->canAccessUpload($user, $upload);
});

Broadcast::channel("{$prefix}.batches.{batchId}", function (Authenticatable $user, string $batchId) use ($authCacheTtl) {
    $batch = $authCacheTtl > 0
        ? Cache::remember(
            CacheKeys::prefix()."auth:batch:{$batchId}",
            $authCacheTtl,
            fn () => app(ChunkyManager::class)->getBatchStatus($batchId),
        )
        : app(ChunkyManager::class)->getBatchStatus($batchId);

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
