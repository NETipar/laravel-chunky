<?php

namespace NETipar\Chunky\Trackers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Contracts\UploadTracker;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Enums\UploadStatus;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Exceptions\UploadExpiredException;

class FilesystemTracker implements UploadTracker
{
    public function __construct()
    {
        // The mutation paths in this tracker rely on flock() against a real
        // local file path. On non-local Flysystem drivers (S3, GCS, etc.)
        // `disk()->path()` throws and we silently fall back to running the
        // critical sections lock-free — every chunk-write/claim/status flip
        // becomes a lost-update race. Refuse to boot in that combination
        // instead of inviting silent data loss.
        if (! (bool) config('chunky.skip_local_disk_guard', false)) {
            $this->assertLocalDisk();
        }
    }

    public function initiate(string $uploadId, UploadMetadata $metadata): void
    {
        $data = [
            ...$metadata->toArray(),
            'uploaded_chunks' => [],
            'expires_at' => now()->addMinutes(config('chunky.expiration', 1440))->toIso8601String(),
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
                $staleAfter = (int) config('chunky.assembly_stale_after_minutes', 10);
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
        $directories = $this->disk()->directories(config('chunky.temp_directory'));
        $expired = [];
        $staleAfter = (int) config('chunky.assembly_stale_after_minutes', 10);
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
        return config('chunky.temp_directory')."/{$uploadId}/metadata.json";
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
                .'"database", or use a local Laravel disk for chunky.disk. '
                .'(Set chunky.skip_local_disk_guard=true to bypass this check '
                .'if you have implemented external locking.)',
                previous: $e,
            );
        }
    }

    /**
     * Run $callback under an exclusive flock() guard against the upload's
     * metadata file. Falls back to running the callback unguarded when the
     * underlying filesystem driver does not expose a real local path (for
     * example S3 or any non-local Flysystem adapter).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withLock(string $uploadId, callable $callback): mixed
    {
        $disk = $this->disk();

        try {
            $fullPath = $disk->path($this->metadataPath($uploadId));
        } catch (\Throwable) {
            return $callback();
        }

        $directory = dirname($fullPath);

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $lockPath = $fullPath.'.lock';
        $handle = @fopen($lockPath, 'c+');

        if ($handle === false) {
            return $callback();
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return $callback();
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
}
