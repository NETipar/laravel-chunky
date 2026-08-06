<?php

declare(strict_types=1);

namespace NETipar\Chunky\Domain;

final readonly class BatchStatusResult
{
    /**
     * @param  list<array{upload_id: string, status: string, progress: float}>  $uploads
     */
    public function __construct(
        public string $batchId,
        public BatchStatus $status,
        public int $totalFiles,
        public int $completedCount,
        public int $failedCount,
        public array $uploads,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'status' => $this->status->value,
            'total_files' => $this->totalFiles,
            'completed_count' => $this->completedCount,
            'failed_count' => $this->failedCount,
            'uploads' => $this->uploads,
        ];
    }
}
