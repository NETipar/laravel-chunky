<?php

declare(strict_types=1);

namespace NETipar\Chunky\Ports;

use DateTimeImmutable;
use NETipar\Chunky\Domain\ChunkProgress;
use NETipar\Chunky\Domain\UploadRecord;
use NETipar\Chunky\Domain\UploadStatus;

interface UploadRepository
{
    public function create(UploadRecord $record): void;

    public function find(string $uploadId): ?UploadRecord;

    public function findByFingerprint(string $fingerprint, ?string $userId): ?UploadRecord;

    /**
     * @return list<UploadRecord>
     */
    public function findByBatch(string $batchId): array;

    /**
     * Atomically append a chunk index and return the fresh progress. Idempotent
     * per index (marking the same index twice counts once).
     */
    public function markChunk(string $uploadId, int $chunkIndex): ChunkProgress;

    /**
     * Persist the client-reported whole-file checksum. First write wins: a
     * record that already has a checksum keeps it. No-op for missing records.
     */
    public function setFileChecksum(string $uploadId, string $checksum): void;

    /**
     * Atomic compare-and-swap. Returns false if the record's status is not
     * $from at the moment of the swap, or if a guard condition fails. $attrs is
     * written together with $to in the same atomic operation (e.g. final_path,
     * claimed_at, result_payload). Supported guard keys:
     *   - 'claimed_before' => DateTimeImmutable: succeeds only if the record's
     *     claimed_at is set and strictly before the given instant (stale-claim
     *     takeover).
     *
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $guard
     */
    public function transition(
        string $uploadId,
        UploadStatus $from,
        UploadStatus $to,
        array $attrs = [],
        array $guard = [],
    ): bool;

    /**
     * Non-terminal records whose expires_at is before $before.
     *
     * @return list<UploadRecord>
     */
    public function findExpired(DateTimeImmutable $before, int $limit): array;

    public function delete(string $uploadId): void;
}
