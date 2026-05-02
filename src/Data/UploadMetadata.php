<?php

declare(strict_types=1);

namespace NETipar\Chunky\Data;

use NETipar\Chunky\Data\Concerns\HasArrayPayload;
use NETipar\Chunky\Enums\UploadStatus;
use NETipar\Chunky\Support\ChunkCalculator;

class UploadMetadata
{
    use HasArrayPayload;

    /**
     * @param  array<int, int>  $uploadedChunks
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $uploadId,
        public readonly string $fileName,
        public readonly int $fileSize,
        public readonly ?string $mimeType,
        public readonly int $chunkSize,
        public readonly int $totalChunks,
        public readonly string $disk,
        public readonly ?string $context = null,
        public readonly array $metadata = [],
        public readonly array $uploadedChunks = [],
        public readonly UploadStatus $status = UploadStatus::Pending,
        public readonly ?string $finalPath = null,
        public readonly ?string $batchId = null,
        // string accommodates int IDs, UUIDs, ULIDs alike. The package never
        // arithmetically operates on user IDs, only compares them.
        public readonly ?string $userId = null,
    ) {}

    public function progress(): float
    {
        return ChunkCalculator::progress(count($this->uploadedChunks), $this->totalChunks);
    }

    public function withStatus(UploadStatus $status, ?string $finalPath = null): self
    {
        return new self(
            uploadId: $this->uploadId,
            fileName: $this->fileName,
            fileSize: $this->fileSize,
            mimeType: $this->mimeType,
            chunkSize: $this->chunkSize,
            totalChunks: $this->totalChunks,
            disk: $this->disk,
            context: $this->context,
            metadata: $this->metadata,
            uploadedChunks: $this->uploadedChunks,
            status: $status,
            finalPath: $finalPath ?? $this->finalPath,
            batchId: $this->batchId,
            userId: $this->userId,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawStatus = self::read($data, 'status', 'pending');

        return new self(
            uploadId: self::read($data, 'upload_id'),
            fileName: self::read($data, 'file_name'),
            fileSize: (int) self::read($data, 'file_size', 0),
            mimeType: self::read($data, 'mime_type'),
            chunkSize: (int) self::read($data, 'chunk_size', 0),
            totalChunks: (int) self::read($data, 'total_chunks', 0),
            disk: self::read($data, 'disk', config('chunky.disk')),
            context: self::read($data, 'context'),
            metadata: self::read($data, 'metadata', []) ?? [],
            uploadedChunks: self::read($data, 'uploaded_chunks', []) ?? [],
            status: $rawStatus instanceof UploadStatus
                ? $rawStatus
                : (UploadStatus::tryFrom((string) $rawStatus) ?? UploadStatus::Pending),
            finalPath: self::read($data, 'final_path'),
            batchId: self::read($data, 'batch_id'),
            userId: self::readUserId(self::read($data, 'user_id')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'upload_id' => $this->uploadId,
            'file_name' => $this->fileName,
            'file_size' => $this->fileSize,
            'mime_type' => $this->mimeType,
            'chunk_size' => $this->chunkSize,
            'total_chunks' => $this->totalChunks,
            'disk' => $this->disk,
            'context' => $this->context,
            'metadata' => $this->metadata,
            'uploaded_chunks' => $this->uploadedChunks,
            'status' => $this->status->value,
            'final_path' => $this->finalPath,
            'batch_id' => $this->batchId,
            'user_id' => $this->userId,
        ];
    }

    /**
     * Same as toArray() but strips fields that should not leave the server:
     * the storage `disk`, the absolute `final_path`, and the owning `user_id`.
     * Used by the public status endpoint so probe-style requests can't leak
     * internal paths or user attribution.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $payload = $this->toArray();

        unset($payload['disk'], $payload['final_path'], $payload['user_id']);

        return $payload;
    }
}
