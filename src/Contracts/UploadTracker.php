<?php

namespace NETipar\Chunky\Contracts;

use NETipar\Chunky\Data\UploadMetadata;

interface UploadTracker
{
    public function initiate(string $uploadId, UploadMetadata $metadata): void;

    public function markChunkUploaded(string $uploadId, int $chunkIndex, ?string $checksum = null): void;

    /**
     * @return array<int, int>
     */
    public function getUploadedChunks(string $uploadId): array;

    public function isComplete(string $uploadId): bool;

    public function getMetadata(string $uploadId): ?UploadMetadata;

    public function expire(string $uploadId): void;
}
