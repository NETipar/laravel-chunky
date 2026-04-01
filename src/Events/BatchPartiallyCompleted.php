<?php

namespace NETipar\Chunky\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BatchPartiallyCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $batchId,
        public readonly int $completedFiles,
        public readonly int $failedFiles,
        public readonly int $totalFiles,
    ) {}
}
