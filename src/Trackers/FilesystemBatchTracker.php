<?php

declare(strict_types=1);

namespace NETipar\Chunky\Trackers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Contracts\BatchTracker;
use NETipar\Chunky\Data\BatchMetadata;
use NETipar\Chunky\Enums\BatchStatus;
use NETipar\Chunky\Events\BatchCompleted;
use NETipar\Chunky\Events\BatchPartiallyCompleted;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Support\CacheKeys;

/**
 * JSON-on-disk batch tracker. Mutation paths run under the same locking
 * primitive as the FilesystemTracker: flock() against the local path by
 * default, Cache::lock() when chunky.lock_driver = "cache".
 */
class FilesystemBatchTracker implements BatchTracker
{
    public function initiate(BatchMetadata $batch): void
    {
        $expiresAt = $batch->expiresAt
            ?? now()->addMinutes(config('chunky.lifecycle.expiration_minutes', 360));

        $this->writeRaw($batch->batchId, [
            'batch_id' => $batch->batchId,
            'user_id' => $batch->userId,
            'total_files' => $batch->totalFiles,
            'completed_files' => $batch->completedFiles,
            'failed_files' => $batch->failedFiles,
            'context' => $batch->context,
            'metadata' => $batch->metadata,
            'status' => $batch->status->value,
            'expires_at' => $expiresAt->toIso8601String(),
            'created_at' => now()->toIso8601String(),
        ]);
    }

    public function find(string $batchId): ?BatchMetadata
    {
        $data = $this->readRaw($batchId);

        return $data ? BatchMetadata::fromArray($data) : null;
    }

    public function incrementCompleted(string $batchId): ?BatchMetadata
    {
        return $this->incrementCounter($batchId, 'completed_files');
    }

    public function incrementFailed(string $batchId): ?BatchMetadata
    {
        return $this->incrementCounter($batchId, 'failed_files');
    }

    public function markProcessing(string $batchId): void
    {
        $this->withLock($batchId, function () use ($batchId): void {
            $data = $this->readRaw($batchId);

            if (! $data || ($data['status'] ?? null) !== BatchStatus::Pending->value) {
                return;
            }

            $data['status'] = BatchStatus::Processing->value;
            $this->writeRaw($batchId, $data);
        });
    }

    public function markCancelled(string $batchId): bool
    {
        $cancelled = false;

        $this->withLock($batchId, function () use ($batchId, &$cancelled): void {
            $data = $this->readRaw($batchId);

            if (! $data) {
                return;
            }

            $current = BatchStatus::tryFrom($data['status'] ?? '') ?? BatchStatus::Pending;

            if ($current->isTerminal()) {
                return;
            }

            $data['status'] = BatchStatus::Cancelled->value;
            $data['completed_at'] = now()->toIso8601String();
            $this->writeRaw($batchId, $data);
            $cancelled = true;
        });

        return $cancelled;
    }

    public function updateStatus(string $batchId, BatchStatus $status): void
    {
        $this->withLock($batchId, function () use ($batchId, $status): void {
            $data = $this->readRaw($batchId);

            if (! $data) {
                return;
            }

            $data['status'] = $status->value;
            $this->writeRaw($batchId, $data);
        });
    }

    /**
     * @return array<int, string>
     */
    public function expiredBatchIds(): array
    {
        $batchesDir = config('chunky.storage.temp_directory').'/batches';
        $disk = $this->disk();

        if (! $disk->exists($batchesDir)) {
            return [];
        }

        $directories = $disk->directories($batchesDir);
        $expired = [];
        $now = now();
        $terminal = array_map(fn (BatchStatus $s) => $s->value, BatchStatus::terminalCases());

        foreach ($directories as $directory) {
            $batchId = basename($directory);
            $data = $this->readRaw($batchId);

            if (! $data) {
                continue;
            }

            if (in_array($data['status'] ?? null, $terminal, true)) {
                continue;
            }

            if (isset($data['expires_at']) && $now->isAfter($data['expires_at'])) {
                $expired[] = $batchId;
            }
        }

        return $expired;
    }

    public function forget(string $batchId): void
    {
        $this->disk()->deleteDirectory(
            config('chunky.storage.temp_directory')."/batches/{$batchId}"
        );
    }

    private function incrementCounter(string $batchId, string $column): ?BatchMetadata
    {
        $shouldDispatch = null;
        $dispatchPayload = null;
        $resultMetadata = null;

        $this->withLock($batchId, function () use ($batchId, $column, &$shouldDispatch, &$dispatchPayload, &$resultMetadata): void {
            $data = $this->readRaw($batchId);

            if (! $data) {
                return;
            }

            $alreadyTerminal = in_array(
                $data['status'] ?? null,
                array_map(fn (BatchStatus $s) => $s->value, BatchStatus::terminalCases()),
                true,
            );

            $data[$column] = ($data[$column] ?? 0) + 1;

            $completed = (int) ($data['completed_files'] ?? 0);
            $failed = (int) ($data['failed_files'] ?? 0);
            $total = (int) ($data['total_files'] ?? 0);

            if ($completed + $failed >= $total && ! $alreadyTerminal) {
                $status = $failed > 0
                    ? BatchStatus::PartiallyCompleted
                    : BatchStatus::Completed;

                $data['status'] = $status->value;
                $data['completed_at'] = now()->toIso8601String();

                $shouldDispatch = $status;
                $dispatchPayload = [
                    'completed' => $completed,
                    'failed' => $failed,
                    'total' => $total,
                    'userId' => isset($data['user_id']) && $data['user_id'] !== ''
                        ? (string) $data['user_id']
                        : null,
                ];
            } elseif (($data['status'] ?? null) === BatchStatus::Pending->value) {
                $data['status'] = BatchStatus::Processing->value;
            }

            $this->writeRaw($batchId, $data);
            $resultMetadata = BatchMetadata::fromArray($data);
        });

        // Dispatch outside the lock so a slow listener can't extend hold time.
        if ($shouldDispatch === BatchStatus::Completed) {
            BatchCompleted::dispatch($batchId, $dispatchPayload['total'], $dispatchPayload['userId']);
        } elseif ($shouldDispatch === BatchStatus::PartiallyCompleted) {
            BatchPartiallyCompleted::dispatch(
                $batchId,
                $dispatchPayload['completed'],
                $dispatchPayload['failed'],
                $dispatchPayload['total'],
                $dispatchPayload['userId'],
            );
        }

        return $resultMetadata;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readRaw(string $batchId): ?array
    {
        $disk = $this->disk();
        $path = $this->batchPath($batchId);

        if (! $disk->exists($path)) {
            return null;
        }

        $decoded = json_decode((string) $disk->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeRaw(string $batchId, array $data): void
    {
        $this->disk()->put($this->batchPath($batchId), json_encode($data));
    }

    private function batchPath(string $batchId): string
    {
        return config('chunky.storage.temp_directory')."/batches/{$batchId}/batch.json";
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('chunky.disk'));
    }

    private function withLock(string $batchId, \Closure $callback): void
    {
        if (config('chunky.locking.driver', 'flock') === 'cache') {
            $ttl = (int) config('chunky.locking.ttl_seconds', 30);
            $waitFor = (int) config('chunky.locking.wait_seconds', 5);

            $lock = Cache::lock(CacheKeys::batchLock($batchId), $ttl);

            try {
                $lock->block($waitFor);
                $callback();
            } finally {
                $lock->release();
            }

            return;
        }

        $disk = $this->disk();
        $relativePath = $this->batchPath($batchId);

        try {
            $fullPath = $disk->path($relativePath);
        } catch (\Throwable $e) {
            throw new ChunkyException(
                "Batch lock for {$batchId} cannot be acquired: the configured disk "
                .'does not expose a local path. Set chunky.lock_driver = "cache" '
                .'to use cache-backed locks for cloud disks.',
                previous: $e,
            );
        }

        $directory = dirname($fullPath);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new ChunkyException(
                "Could not create batch lock directory at {$directory}.",
            );
        }

        $lockPath = $fullPath.'.lock';
        $handle = @fopen($lockPath, 'c+');

        if ($handle === false) {
            throw new ChunkyException(
                "Could not open batch lock file at {$lockPath}. "
                .'Refusing to run critical section without lock.',
            );
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new ChunkyException(
                    "Failed to acquire flock on batch lock {$lockPath}.",
                );
            }

            try {
                $callback();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
