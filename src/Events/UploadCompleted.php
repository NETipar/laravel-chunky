<?php

namespace NETipar\Chunky\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use NETipar\Chunky\Data\UploadMetadata;

class UploadCompleted
{
    use Dispatchable, SerializesModels;

    public readonly string $uploadId;

    public readonly string $finalPath;

    public readonly string $disk;

    /** @var array<string, mixed>|null */
    public readonly ?array $metadata;

    public function __construct(
        public readonly UploadMetadata $upload,
    ) {
        $this->uploadId = $upload->uploadId;
        $this->finalPath = $upload->finalPath ?? '';
        $this->disk = $upload->disk;
        $this->metadata = $upload->metadata ?: null;
    }
}
