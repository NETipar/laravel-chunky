<?php

namespace NETipar\Chunky\Trackers;

use Illuminate\Support\Facades\DB;
use NETipar\Chunky\Contracts\UploadTracker;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Enums\UploadStatus;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Exceptions\UploadExpiredException;
use NETipar\Chunky\Models\ChunkedUpload;

class DatabaseTracker implements UploadTracker
{
    public function initiate(string $uploadId, UploadMetadata $metadata): void
    {
        ChunkedUpload::create([
            'upload_id' => $uploadId,
            'batch_id' => $metadata->batchId,
            'user_id' => $metadata->userId,
            'file_name' => $metadata->fileName,
            'file_size' => $metadata->fileSize,
            'mime_type' => $metadata->mimeType,
            'chunk_size' => $metadata->chunkSize,
            'total_chunks' => $metadata->totalChunks,
            'uploaded_chunks' => [],
            'disk' => $metadata->disk,
            'context' => $metadata->context,
            'metadata' => $metadata->metadata ?: null,
            'status' => UploadStatus::Pending,
            'expires_at' => now()->addMinutes(config('chunky.expiration', 1440)),
        ]);
    }

    public function markChunkUploaded(string $uploadId, int $chunkIndex, ?string $checksum = null): void
    {
        DB::transaction(function () use ($uploadId, $chunkIndex, $checksum): void {
            $upload = ChunkedUpload::where('upload_id', $uploadId)
                ->lockForUpdate()
                ->first();

            if (! $upload) {
                throw new ChunkyException("Upload {$uploadId} not found.");
            }

            if ($upload->isExpired()) {
                $upload->update(['status' => UploadStatus::Expired]);

                throw UploadExpiredException::forUpload($uploadId);
            }

            $upload->markChunkUploaded($chunkIndex, $checksum);
        });
    }

    /**
     * @return array<int, int>
     */
    public function getUploadedChunks(string $uploadId): array
    {
        $upload = $this->findOrFail($uploadId);

        return $upload->uploaded_chunks ?? [];
    }

    public function isComplete(string $uploadId): bool
    {
        $upload = $this->findOrFail($uploadId);

        return $upload->isComplete();
    }

    public function getMetadata(string $uploadId): ?UploadMetadata
    {
        $upload = ChunkedUpload::where('upload_id', $uploadId)->first();

        if (! $upload) {
            return null;
        }

        return UploadMetadata::fromArray([
            'upload_id' => $upload->upload_id,
            'batch_id' => $upload->batch_id,
            'user_id' => $upload->user_id,
            'file_name' => $upload->file_name,
            'file_size' => $upload->file_size,
            'mime_type' => $upload->mime_type,
            'chunk_size' => $upload->chunk_size,
            'total_chunks' => $upload->total_chunks,
            'uploaded_chunks' => $upload->uploaded_chunks,
            'disk' => $upload->disk,
            'context' => $upload->context,
            'metadata' => $upload->metadata,
            'status' => $upload->status,
            'final_path' => $upload->final_path,
        ]);
    }

    public function expire(string $uploadId): void
    {
        ChunkedUpload::where('upload_id', $uploadId)->update([
            'status' => UploadStatus::Expired,
        ]);
    }

    public function updateStatus(string $uploadId, UploadStatus $status, ?string $finalPath = null): void
    {
        $data = ['status' => $status];

        if ($finalPath) {
            $data['final_path'] = $finalPath;
        }

        if ($status === UploadStatus::Completed) {
            $data['completed_at'] = now();
        }

        ChunkedUpload::where('upload_id', $uploadId)->update($data);
    }

    private function findOrFail(string $uploadId): ChunkedUpload
    {
        $upload = ChunkedUpload::where('upload_id', $uploadId)->first();

        if (! $upload) {
            throw new ChunkyException("Upload {$uploadId} not found.");
        }

        if ($upload->isExpired()) {
            $this->expire($uploadId);
            throw UploadExpiredException::forUpload($uploadId);
        }

        return $upload;
    }
}
