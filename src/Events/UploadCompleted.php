<?php

namespace NETipar\Chunky\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UploadCompleted
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public readonly string $uploadId,
        public readonly string $finalPath,
        public readonly string $disk,
        public readonly ?array $metadata = null,
    ) {}
}
