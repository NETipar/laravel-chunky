<?php

declare(strict_types=1);

namespace NETipar\Chunky\Support;

/**
 * Centralised cache-key construction with a versioned prefix.
 *
 * The prefix is configurable via `chunky.cache.prefix` (default
 * `chunky:v1:`). Major bumps that change cached payload shapes can flip
 * the version segment to invalidate stale entries cleanly without
 * cooperating cache backends.
 */
final class CacheKeys
{
    public static function prefix(): string
    {
        $prefix = config('chunky.cache.prefix', 'chunky:v1:');

        return is_string($prefix) ? $prefix : 'chunky:v1:';
    }

    public static function uploadLock(string $uploadId): string
    {
        return self::prefix()."upload:{$uploadId}";
    }

    public static function batchLock(string $batchId): string
    {
        return self::prefix()."batch:{$batchId}";
    }

    public static function idempotency(string $uploadId, int $chunkIndex, string $suffix): string
    {
        return self::prefix()."idem:{$uploadId}:{$chunkIndex}:{$suffix}";
    }
}
