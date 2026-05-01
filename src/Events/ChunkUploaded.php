<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChunkUploaded
{
    use Dispatchable, SerializesModels;

    public readonly float $progress;

    public function __construct(
        public readonly string $uploadId,
        public readonly int $chunkIndex,
        public readonly int $totalChunks,
    ) {
        $this->progress = round(($chunkIndex + 1) / $totalChunks * 100, 2);
    }
}
