<?php

declare(strict_types=1);

namespace NETipar\Chunky\Domain;

/**
 * The result of accepting one chunk. On the final chunk it also carries the
 * assembly outcome (in sync mode) or signals that assembly was queued.
 */
final readonly class ChunkUploadOutcome
{
    public function __construct(
        public UploadStatus $status,
        public int $chunkIndex,
        public int $uploadedCount,
        public int $totalChunks,
        public float $progress,
        public ?UploadResult $result = null,
    ) {}

    public function isComplete(): bool
    {
        return $this->status === UploadStatus::Completed;
    }
}
