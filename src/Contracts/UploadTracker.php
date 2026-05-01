<?php

namespace NETipar\Chunky\Contracts;

use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Enums\UploadStatus;

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

    public function updateStatus(string $uploadId, UploadStatus $status, ?string $finalPath = null): void;

    /**
     * Atomically claim an upload for assembly. Returns true if the caller acquired
     * the claim (status transitioned from Pending to Assembling), false if another
     * worker already started assembling this upload.
     */
    public function claimForAssembly(string $uploadId): bool;
}
