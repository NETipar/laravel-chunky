<?php

namespace NETipar\Chunky\Data;

use NETipar\Chunky\Enums\UploadStatus;
use NETipar\Chunky\Support\ChunkCalculator;

class UploadMetadata
{
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
        public readonly ?string $context,
        public readonly array $metadata = [],
        public readonly array $uploadedChunks = [],
        public readonly UploadStatus $status = UploadStatus::Pending,
        public readonly ?string $finalPath = null,
    ) {}

    public function progress(): float
    {
        return ChunkCalculator::progress(count($this->uploadedChunks), $this->totalChunks);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            uploadId: $data['upload_id'],
            fileName: $data['file_name'],
            fileSize: (int) $data['file_size'],
            mimeType: $data['mime_type'] ?? null,
            chunkSize: (int) $data['chunk_size'],
            totalChunks: (int) $data['total_chunks'],
            disk: $data['disk'] ?? config('chunky.disk'),
            context: $data['context'] ?? null,
            metadata: $data['metadata'] ?? [],
            uploadedChunks: $data['uploaded_chunks'] ?? [],
            status: isset($data['status']) && $data['status'] instanceof UploadStatus
                ? $data['status']
                : UploadStatus::tryFrom($data['status'] ?? 'pending') ?? UploadStatus::Pending,
            finalPath: $data['final_path'] ?? null,
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
        ];
    }
}
