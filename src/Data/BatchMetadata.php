<?php

declare(strict_types=1);

namespace NETipar\Chunky\Data;

use NETipar\Chunky\Enums\BatchStatus;

class BatchMetadata
{
    public function __construct(
        public readonly string $batchId,
        public readonly int $totalFiles,
        public readonly int $completedFiles,
        public readonly int $failedFiles,
        public readonly BatchStatus $status,
        public readonly ?string $context = null,
        // See UploadMetadata::$userId for the type rationale.
        public readonly ?string $userId = null,
    ) {}

    public function pendingFiles(): int
    {
        return $this->totalFiles - $this->completedFiles - $this->failedFiles;
    }

    public function isFinished(): bool
    {
        return $this->completedFiles + $this->failedFiles >= $this->totalFiles;
    }

    /**
     * Total processing progress (0–100). Includes failed files because
     * "failed" is a terminal state — the batch is just as "done" with a
     * file whether it succeeded or failed. Use successProgress() for the
     * success-only view.
     */
    public function progress(): float
    {
        if ($this->totalFiles === 0) {
            return 0;
        }

        $processed = $this->completedFiles + $this->failedFiles;

        return round(($processed / $this->totalFiles) * 100, 2);
    }

    /**
     * Success-only progress (0–100): the share of files that have completed
     * successfully. Always less than or equal to progress().
     */
    public function successProgress(): float
    {
        if ($this->totalFiles === 0) {
            return 0;
        }

        return round(($this->completedFiles / $this->totalFiles) * 100, 2);
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
            'pending_files' => $this->pendingFiles(),
            'context' => $this->context,
            'status' => $this->status->value,
            'is_finished' => $this->isFinished(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            batchId: $data['batch_id'],
            totalFiles: (int) $data['total_files'],
            completedFiles: (int) ($data['completed_files'] ?? 0),
            failedFiles: (int) ($data['failed_files'] ?? 0),
            status: self::resolveStatus($data['status'] ?? null),
            context: $data['context'] ?? null,
            userId: isset($data['user_id']) && $data['user_id'] !== ''
                ? (string) $data['user_id']
                : null,
        );
    }

    private static function resolveStatus(mixed $raw): BatchStatus
    {
        if ($raw instanceof BatchStatus) {
            return $raw;
        }

        if (! is_string($raw)) {
            return BatchStatus::Pending;
        }

        return BatchStatus::tryFrom($raw) ?? BatchStatus::Pending;
    }
}
