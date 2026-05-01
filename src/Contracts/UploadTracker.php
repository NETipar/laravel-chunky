<?php

declare(strict_types=1);

namespace NETipar\Chunky\Contracts;

use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Enums\UploadStatus;

interface UploadTracker
{
    public function initiate(string $uploadId, UploadMetadata $metadata): void;

    /**
     * Persist a completed chunk and return the freshly updated metadata so callers
     * do not need a follow-up read to learn the new state.
     */
    public function markChunkUploaded(string $uploadId, int $chunkIndex, ?string $checksum = null): UploadMetadata;

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

    /**
     * Return the upload IDs that have passed their expiration timestamp and are
     * still safe to purge (i.e. not currently being assembled).
     *
     * @return array<int, string>
     */
    public function expiredUploadIds(): array;

    /**
     * Forget the upload metadata. The handler is responsible for removing the
     * actual chunk files.
     */
    public function forget(string $uploadId): void;
}
