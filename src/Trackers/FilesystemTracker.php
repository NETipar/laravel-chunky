<?php

declare(strict_types=1);

namespace NETipar\Chunky\Trackers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Contracts\UploadTracker;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Enums\UploadStatus;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Exceptions\UploadExpiredException;
use NETipar\Chunky\Support\CacheKeys;

class FilesystemTracker implements UploadTracker
{
    public function __construct()
    {
        // The mutation paths in this tracker need atomic critical sections.
        // Two locking strategies are supported:
        //
        //  1. flock() against a real local file path. The default — works
        //     out of the box on local-disk setups, no extra infrastructure.
        //
        //  2. Cache::lock() against the configured cache driver (Redis,
        //     Memcached, DB). Required for cloud disks (S3/GCS) where
        //     `disk()->path()` throws and flock() is impossible. Opt in
        //     via chunky.locking.driver = 'cache'.
        //
        // If neither is available we'd silently run the critical sections
        // lock-free, turning every chunk-write/claim/status flip into a
        // lost-update race. Detect the combination and refuse to boot.
        if (config('chunky.locking.driver', 'flock') === 'flock') {
            $this->assertLocalDisk();
        }
    }

    public function initiate(string $uploadId, UploadMetadata $metadata): void
    {
        $data = [
            ...$metadata->toArray(),
            'uploaded_chunks' => [],
            'expires_at' => now()->addMinutes(config('chunky.lifecycle.expiration_minutes', 360))->toIso8601String(),
            'created_at' => now()->toIso8601String(),
        ];

        $this->disk()->put(
            $this->metadataPath($uploadId),
            json_encode($data)
        );
    }

    public function markChunkUploaded(string $uploadId, int $chunkIndex, ?string $checksum = null): UploadMetadata
    {
        return $this->withLock($uploadId, function () use ($uploadId, $chunkIndex): UploadMetadata {
            $data = $this->readRawMetadata($uploadId);
            $status = $data['status'] ?? UploadStatus::Pending->value;

            // See DatabaseTracker::markChunkUploaded for the full rationale.
            if ($status !== UploadStatus::Pending->value) {
                throw new ChunkyException(
                    "Upload {$uploadId} is no longer accepting chunks (status: {$status}).",
                );
            }

            $chunks = $data['uploaded_chunks'] ?? [];

            if (! in_array($chunkIndex, $chunks)) {
                $chunks[] = $chunkIndex;
                sort($chunks);
            }

            $data['uploaded_chunks'] = $chunks;

            $this->writeRawMetadata($uploadId, $data);

            return UploadMetadata::fromArray($data);
        });
    }

    /**
     * @return array<int, int>
     */
    public function getUploadedChunks(string $uploadId): array
    {
        $data = $this->readRawMetadata($uploadId);

        return $data['uploaded_chunks'] ?? [];
    }

    public function isComplete(string $uploadId): bool
    {
        $data = $this->readRawMetadata($uploadId);

        return count($data['uploaded_chunks'] ?? []) >= ($data['total_chunks'] ?? PHP_INT_MAX);
    }

    public function getMetadata(string $uploadId): ?UploadMetadata
    {
        if (! $this->disk()->exists($this->metadataPath($uploadId))) {
            return null;
        }

        $data = $this->readRawMetadata($uploadId);

        return UploadMetadata::fromArray($data);
    }

    public function expire(string $uploadId): void
    {
        $this->updateStatus($uploadId, UploadStatus::Expired);
    }

    public function updateStatus(string $uploadId, UploadStatus $status, ?string $finalPath = null): void
    {
        $this->withLock($uploadId, function () use ($uploadId, $status, $finalPath): void {
            $data = $this->readRawMetadata($uploadId);
            $data['status'] = $status->value;

            if ($finalPath) {
                $data['final_path'] = $finalPath;
            }

            if ($status === UploadStatus::Completed) {
                $data['completed_at'] = now()->toIso8601String();
            }

            $this->writeRawMetadata($uploadId, $data);
        });
    }

    public function claimForAssembly(string $uploadId): bool
    {
        if (! $this->disk()->exists($this->metadataPath($uploadId))) {
            return false;
        }

        return $this->withLock($uploadId, function () use ($uploadId): bool {
            $data = $this->readRawMetadata($uploadId);
            $status = $data['status'] ?? UploadStatus::Pending->value;
            $now = now();

            if ($status === UploadStatus::Pending->value) {
                $data['status'] = UploadStatus::Assembling->value;
                $data['claimed_at'] = $now->toIso8601String();
                $this->writeRawMetadata($uploadId, $data);

                return true;
            }

            // Stale-claim takeover: previous worker flipped to Assembling
            // but never persisted a terminal status — most likely crashed.
            if ($status === UploadStatus::Assembling->value) {
                $staleAfter = (int) config('chunky.lifecycle.assembly_stale_after_minutes', 10);
                $claimedAt = $data['claimed_at'] ?? null;

                if ($claimedAt === null || $now->isAfter(Carbon::parse($claimedAt)->addMinutes($staleAfter))) {
                    $data['claimed_at'] = $now->toIso8601String();
                    $this->writeRawMetadata($uploadId, $data);

                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @return array<int, string>
     */
    public function expiredUploadIds(): array
    {
        $directories = $this->disk()->directories(config('chunky.storage.temp_directory'));
        $expired = [];
        $staleAfter = (int) config('chunky.lifecycle.assembly_stale_after_minutes', 10);
        $now = now();

        foreach ($directories as $directory) {
            $uploadId = basename($directory);

            if ($uploadId === 'batches') {
                continue;
            }

            $metadataPath = $this->metadataPath($uploadId);

            if (! $this->disk()->exists($metadataPath)) {
                continue;
            }

            $data = json_decode((string) $this->disk()->get($metadataPath), true) ?? [];

            if (($data['status'] ?? null) === UploadStatus::Assembling->value) {
                // Skip in-flight assemblies, but reclaim the slot if the
                // assembly has been stuck longer than the stale window —
                // that almost always indicates a crashed worker.
                $claimedAt = $data['claimed_at'] ?? null;

                if ($claimedAt === null) {
                    continue;
                }

                if (! $now->isAfter(Carbon::parse($claimedAt)->addMinutes($staleAfter))) {
                    continue;
                }
            }

            if (isset($data['expires_at']) && $now->isAfter($data['expires_at'])) {
                $expired[] = $uploadId;
            }
        }

        return $expired;
    }

    public function forget(string $uploadId): void
    {
        $this->disk()->delete($this->metadataPath($uploadId));
    }

    /**
     * @return array<string, mixed>
     */
    private function readRawMetadata(string $uploadId): array
    {
        $path = $this->metadataPath($uploadId);

        if (! $this->disk()->exists($path)) {
            throw new ChunkyException("Upload {$uploadId} not found.");
        }

        $data = json_decode($this->disk()->get($path), true);

        if (isset($data['expires_at']) && now()->isAfter($data['expires_at'])) {
            $data['status'] = UploadStatus::Expired->value;
            $this->writeRawMetadata($uploadId, $data);

            throw UploadExpiredException::forUpload($uploadId);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeRawMetadata(string $uploadId, array $data): void
    {
        $this->disk()->put(
            $this->metadataPath($uploadId),
            json_encode($data)
        );
    }

    private function metadataPath(string $uploadId): string
    {
        return config('chunky.storage.temp_directory')."/{$uploadId}/metadata.json";
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('chunky.disk'));
    }

    private function assertLocalDisk(): void
    {
        try {
            $this->disk()->path('chunky-probe');
        } catch (\Throwable $e) {
            throw new ChunkyException(
                'FilesystemTracker requires a local-path-capable disk because its '
                .'mutation paths depend on flock(). Switch chunky.tracker to '
                .'"database", use a local Laravel disk for chunky.disk, or set '
                .'chunky.locking.driver to "cache" to use cache-backed locking.',
                previous: $e,
            );
        }
    }

    /**
     * Run $callback under an exclusive lock scoped to this upload. The
     * locking primitive is selected by chunky.lock_driver (`flock` or
     * `cache`) — see assertLocalDisk() / __construct() for the rationale.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withLock(string $uploadId, callable $callback): mixed
    {
        if (config('chunky.locking.driver', 'flock') === 'cache') {
            return $this->withCacheLock(CacheKeys::uploadLock($uploadId), $callback);
        }

        return $this->withFileLock($uploadId, $callback);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withFileLock(string $uploadId, callable $callback): mixed
    {
        $disk = $this->disk();

        try {
            $fullPath = $disk->path($this->metadataPath($uploadId));
        } catch (\Throwable $e) {
            // Cloud disk: path() unavailable. Refuse to silently run the
            // critical section unlocked — the caller must opt into
            // chunky.lock_driver = 'cache' for cloud-disk setups.
            throw new ChunkyException(
                "FilesystemTracker cannot acquire flock for upload {$uploadId}: "
                .'the configured disk does not expose a local path. '
                .'Set chunky.lock_driver = "cache" to use cache-backed locks.',
                previous: $e,
            );
        }

        $directory = dirname($fullPath);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new ChunkyException(
                "FilesystemTracker could not create lock directory at {$directory}.",
            );
        }

        $lockPath = $fullPath.'.lock';
        $handle = @fopen($lockPath, 'c+');

        if ($handle === false) {
            throw new ChunkyException(
                "FilesystemTracker could not open lock file at {$lockPath}. "
                .'Refusing to run critical section without lock — risk of race condition. '
                .'Consider chunky.lock_driver = "cache".',
            );
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new ChunkyException(
                    "FilesystemTracker failed to acquire flock on {$lockPath}.",
                );
            }

            try {
                return $callback();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withCacheLock(string $key, callable $callback): mixed
    {
        $ttl = (int) config('chunky.locking.ttl_seconds', 30);
        $waitFor = (int) config('chunky.locking.wait_seconds', 5);

        $lock = Cache::lock($key, $ttl);

        try {
            $lock->block($waitFor);

            return $callback();
        } finally {
            $lock->release();
        }
    }
}
