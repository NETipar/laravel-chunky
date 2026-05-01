<?php

declare(strict_types=1);

namespace NETipar\Chunky\Contracts;

use Illuminate\Http\UploadedFile;
use NETipar\Chunky\Data\UploadMetadata;

interface ChunkHandler
{
    public function store(string $uploadId, int $chunkIndex, UploadedFile $chunk): void;

    /**
     * Assemble all chunks into a final file. The handler receives the
     * full UploadMetadata so it has access to fileSize (for disk-space
     * pre-flight and post-write size assertion) and any custom fields a
     * downstream override may need.
     *
     * @return string The final file path on the configured disk.
     */
    public function assemble(UploadMetadata $metadata): string;

    public function cleanup(string $uploadId): void;
}
