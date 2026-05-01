<?php

declare(strict_types=1);

namespace NETipar\Chunky\Contracts;

use NETipar\Chunky\Data\BatchMetadata;
use NETipar\Chunky\Enums\BatchStatus;

/**
 * Persists batch metadata across the lifetime of a multi-file upload.
 *
 * Both implementations (database / filesystem) MUST atomically increment
 * `completed_files` / `failed_files` so concurrent AssembleFileJob workers
 * cannot lose updates. The increment helpers return the freshly persisted
 * metadata so the caller can decide whether the batch reached a terminal
 * state and dispatch the appropriate event.
 */
interface BatchTracker
{
    public function initiate(BatchMetadata $batch): void;

    public function find(string $batchId): ?BatchMetadata;

    /**
     * Atomically increment the completed-file counter and return the
     * fresh metadata. The implementation MUST honour batch finalisation
     * semantics: when `completed + failed >= total`, transition to a
     * terminal status (Completed or PartiallyCompleted depending on
     * whether `failed > 0`).
     */
    public function incrementCompleted(string $batchId): ?BatchMetadata;

    /**
     * Atomically increment the failed-file counter — see incrementCompleted.
     */
    public function incrementFailed(string $batchId): ?BatchMetadata;

    /**
     * Mark the batch as transitioning out of Pending (the first file's
     * AssembleFileJob has been dispatched). Idempotent — a no-op when
     * the batch is already past Pending.
     */
    public function markProcessing(string $batchId): void;

    /**
     * Mark the batch as cancelled (terminal). Implementations MUST
     * refuse the transition when the batch is already in a terminal
     * state.
     */
    public function markCancelled(string $batchId): bool;

    /**
     * Override the batch status directly. Use sparingly — most state
     * transitions should go through the typed helpers above.
     */
    public function updateStatus(string $batchId, BatchStatus $status): void;

    /**
     * Return the IDs of batches that have passed their expiration
     * timestamp and are still safe to purge (not in-flight).
     *
     * @return array<int, string>
     */
    public function expiredBatchIds(): array;

    /**
     * Forget the batch metadata. Does NOT cascade to the per-file uploads
     * — the caller is responsible for orchestrating that cleanup.
     */
    public function forget(string $batchId): void;
}
