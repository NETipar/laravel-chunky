<?php

declare(strict_types=1);

namespace NETipar\Chunky\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use NETipar\Chunky\Support\ChunkCalculator;
use NETipar\Chunky\Support\Coerce;

final readonly class BatchRecord
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $batchId,
        public int $totalFiles,
        public int $completedFiles = 0,
        public int $failedFiles = 0,
        public BatchStatus $status = BatchStatus::Pending,
        public ?string $profile = null,
        public array $metadata = [],
        public ?string $userId = null,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    /**
     * Terminal-state progress: a failed file is "done" too, so this reaches
     * 100% once every file has resolved either way.
     */
    public function progress(): float
    {
        return ChunkCalculator::progress($this->completedFiles + $this->failedFiles, $this->totalFiles);
    }

    /**
     * Success-only progress, decoupled from progress() for partial batches.
     */
    public function successProgress(): float
    {
        return ChunkCalculator::progress($this->completedFiles, $this->totalFiles);
    }

    public function isFinished(): bool
    {
        return $this->completedFiles + $this->failedFiles >= $this->totalFiles;
    }

    /**
     * The terminal status a finished batch should settle into.
     */
    public function resolveFinalStatus(): BatchStatus
    {
        return $this->failedFiles === 0 ? BatchStatus::Completed : BatchStatus::PartiallyCompleted;
    }

    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < $now;
    }

    public function with(
        ?BatchStatus $status = null,
        ?int $completedFiles = null,
        ?int $failedFiles = null,
    ): self {
        return new self(
            batchId: $this->batchId,
            totalFiles: $this->totalFiles,
            completedFiles: $completedFiles ?? $this->completedFiles,
            failedFiles: $failedFiles ?? $this->failedFiles,
            status: $status ?? $this->status,
            profile: $this->profile,
            metadata: $this->metadata,
            userId: $this->userId,
            expiresAt: $this->expiresAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'total_files' => $this->totalFiles,
            'completed_files' => $this->completedFiles,
            'failed_files' => $this->failedFiles,
            'status' => $this->status->value,
            'profile' => $this->profile,
            'metadata' => $this->metadata,
            'user_id' => $this->userId,
            'expires_at' => $this->expiresAt?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $data = $this->toArray();

        unset($data['user_id'], $data['expires_at']);

        $data['progress'] = $this->progress();
        $data['success_progress'] = $this->successProgress();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $expiresAt = $data['expires_at'] ?? null;
        $metadata = $data['metadata'] ?? [];

        /** @var array<string, mixed> $normalizedMetadata */
        $normalizedMetadata = [];
        if (is_array($metadata)) {
            foreach ($metadata as $k => $v) {
                $normalizedMetadata[(string) $k] = $v;
            }
        }

        return new self(
            batchId: Coerce::toString($data['batch_id'] ?? null),
            totalFiles: Coerce::toInt($data['total_files'] ?? null),
            completedFiles: Coerce::toInt($data['completed_files'] ?? null),
            failedFiles: Coerce::toInt($data['failed_files'] ?? null),
            status: BatchStatus::tryFrom(Coerce::toString($data['status'] ?? 'pending')) ?? BatchStatus::Pending,
            profile: Coerce::toNullableString($data['profile'] ?? null),
            metadata: $normalizedMetadata,
            userId: Coerce::toNullableString($data['user_id'] ?? null),
            expiresAt: $expiresAt instanceof DateTimeImmutable
                ? $expiresAt
                : (is_string($expiresAt) && $expiresAt !== '' ? new DateTimeImmutable($expiresAt) : null),
        );
    }
}
