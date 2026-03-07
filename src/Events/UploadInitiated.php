<?php

namespace NETipar\Chunky\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UploadInitiated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $uploadId,
        public readonly string $fileName,
        public readonly int $fileSize,
        public readonly int $totalChunks,
    ) {}
}
