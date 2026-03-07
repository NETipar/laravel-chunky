<?php

namespace NETipar\Chunky\Trackers;

use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Contracts\UploadTracker;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Enums\UploadStatus;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Exceptions\UploadExpiredException;

class FilesystemTracker implements UploadTracker
{
    public function initiate(string $uploadId, UploadMetadata $metadata): void
    {
        $data = [
            ...$metadata->toArray(),
            'uploaded_chunks' => [],
            'expires_at' => now()->addMinutes(config('chunky.expiration', 1440))->toIso8601String(),
            'created_at' => now()->toIso8601String(),
        ];

        $this->disk()->put(
            $this->metadataPath($uploadId),
            json_encode($data)
        );
    }

    public function markChunkUploaded(string $uploadId, int $chunkIndex, ?string $checksum = null): void
    {
        $data = $this->readRawMetadata($uploadId);
        $chunks = $data['uploaded_chunks'] ?? [];

        if (! in_array($chunkIndex, $chunks)) {
            $chunks[] = $chunkIndex;
            sort($chunks);
        }

        $data['uploaded_chunks'] = $chunks;

        $this->writeRawMetadata($uploadId, $data);
    }

    /**
     * @return array<int, int>
     */
    public function getUploadedChunks(string $uploadId): array
    {
        $data = $this->readRawMetadata($uploadId);

        return $data['uploaded_chunks'] ?? [];
    }

    public function isComplete(string $uploadId): bool
    {
        $data = $this->readRawMetadata($uploadId);

        return count($data['uploaded_chunks'] ?? []) >= ($data['total_chunks'] ?? PHP_INT_MAX);
    }

    public function getMetadata(string $uploadId): ?UploadMetadata
    {
        if (! $this->disk()->exists($this->metadataPath($uploadId))) {
            return null;
        }

        $data = $this->readRawMetadata($uploadId);

        return UploadMetadata::fromArray($data);
    }

    public function expire(string $uploadId): void
    {
        $data = $this->readRawMetadata($uploadId);
        $data['status'] = UploadStatus::Expired->value;
        $this->writeRawMetadata($uploadId, $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function readRawMetadata(string $uploadId): array
    {
        $path = $this->metadataPath($uploadId);

        if (! $this->disk()->exists($path)) {
            throw new ChunkyException("Upload {$uploadId} not found.");
        }

        $data = json_decode($this->disk()->get($path), true);

        if (isset($data['expires_at']) && now()->isAfter($data['expires_at'])) {
            $data['status'] = UploadStatus::Expired->value;
            $this->writeRawMetadata($uploadId, $data);

            throw UploadExpiredException::forUpload($uploadId);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeRawMetadata(string $uploadId, array $data): void
    {
        $this->disk()->put(
            $this->metadataPath($uploadId),
            json_encode($data)
        );
    }

    private function metadataPath(string $uploadId): string
    {
        return config('chunky.temp_directory')."/{$uploadId}/metadata.json";
    }

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(config('chunky.disk'));
    }
}
