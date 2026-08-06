<?php

declare(strict_types=1);

namespace NETipar\Chunky\Domain;

use NETipar\Chunky\Support\ChunkCalculator;

final readonly class ChunkProgress
{
    public function __construct(
        public int $uploadedCount,
        public int $totalChunks,
    ) {}

    public function isComplete(): bool
    {
        return $this->totalChunks > 0 && $this->uploadedCount >= $this->totalChunks;
    }

    public function percentage(): float
    {
        return ChunkCalculator::progress($this->uploadedCount, $this->totalChunks);
    }
}
