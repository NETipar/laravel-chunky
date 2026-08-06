<?php

declare(strict_types=1);

namespace NETipar\Chunky\Tests\Doubles;

use DateTimeImmutable;
use NETipar\Chunky\Domain\ChunkProgress;
use NETipar\Chunky\Domain\UploadRecord;
use NETipar\Chunky\Domain\UploadStatus;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Ports\UploadRepository;

/**
 * Single-process reference implementation of UploadRepository. It is the
 * behavioural etalon the Database/Filesystem adapters must match; the same
 * contract suite runs against all three.
 */
final class InMemoryUploadRepository implements UploadRepository
{
    /** @var array<string, UploadRecord> */
    private array $store = [];

    public function create(UploadRecord $record): void
    {
        $this->store[$record->uploadId] = $record;
    }

    public function find(string $uploadId): ?UploadRecord
    {
        return $this->store[$uploadId] ?? null;
    }

    public function findByFingerprint(string $fingerprint, ?string $userId): ?UploadRecord
    {
        foreach ($this->store as $record) {
            if ($record->fingerprint === $fingerprint
                && $record->userId === $userId
                && ! $record->status->isTerminal()) {
                return $record;
            }
        }

        return null;
    }

    public function findByBatch(string $batchId): array
    {
        return array_values(array_filter(
            $this->store,
            static fn (UploadRecord $r): bool => $r->batchId === $batchId,
        ));
    }

    public function markChunk(string $uploadId, int $chunkIndex): ChunkProgress
    {
        $record = $this->store[$uploadId] ?? throw new ChunkyException("Upload '{$uploadId}' not found.");

        if (! in_array($chunkIndex, $record->uploadedChunks, true)) {
            $chunks = $record->uploadedChunks;
            $chunks[] = $chunkIndex;
            sort($chunks);
            $record = $record->with(uploadedChunks: array_values($chunks));
            $this->store[$uploadId] = $record;
        }

        return new ChunkProgress(count($record->uploadedChunks), $record->totalChunks);
    }

    public function setFileChecksum(string $uploadId, string $checksum): void
    {
        $record = $this->store[$uploadId] ?? null;

        if ($record === null || $record->fileChecksum !== null) {
            return;
        }

        $this->store[$uploadId] = $record->with(fileChecksum: $checksum);
    }

    public function transition(
        string $uploadId,
        UploadStatus $from,
        UploadStatus $to,
        array $attrs = [],
        array $guard = [],
    ): bool {
        $record = $this->store[$uploadId] ?? null;

        if ($record === null || $record->status !== $from) {
            return false;
        }

        if (isset($guard['claimed_before'])) {
            $threshold = $guard['claimed_before'];

            if (! $threshold instanceof DateTimeImmutable
                || $record->claimedAt === null
                || $record->claimedAt >= $threshold) {
                return false;
            }
        }

        $finalPath = isset($attrs['final_path']) ? (string) $attrs['final_path'] : null;
        $claimedAt = $attrs['claimed_at'] ?? null;
        /** @var array<string, mixed>|null $resultPayload */
        $resultPayload = isset($attrs['result_payload']) && is_array($attrs['result_payload'])
            ? $attrs['result_payload']
            : null;

        $this->store[$uploadId] = $record->with(
            status: $to,
            finalPath: $finalPath,
            claimedAt: $claimedAt instanceof DateTimeImmutable ? $claimedAt : null,
            resultPayload: $resultPayload,
        );

        return true;
    }

    public function findExpired(DateTimeImmutable $before, int $limit): array
    {
        $expired = [];

        foreach ($this->store as $record) {
            if (count($expired) >= $limit) {
                break;
            }

            if ($record->isExpiredAt($before) && ! $record->status->isTerminal()) {
                $expired[] = $record;
            }
        }

        return $expired;
    }

    public function delete(string $uploadId): void
    {
        unset($this->store[$uploadId]);
    }
}
