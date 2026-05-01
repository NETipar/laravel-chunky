<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ChunkUploadFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $uploadId,
        public readonly int $chunkIndex,
        public readonly Throwable $exception,
    ) {}
}
