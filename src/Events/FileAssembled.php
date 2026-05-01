<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FileAssembled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $uploadId,
        public readonly string $finalPath,
        public readonly string $disk,
        public readonly string $fileName,
        public readonly int $fileSize,
    ) {}
}
