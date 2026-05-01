<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events;

use NETipar\Chunky\Data\UploadMetadata;

class UploadCompleted extends AbstractChunkyEvent
{
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

    protected function broadcastEventKey(): string
    {
        return 'UploadCompleted';
    }

    /**
     * @return array<int, string>
     */
    protected function broadcastChannelSuffixes(): array
    {
        $suffixes = ["uploads.{$this->uploadId}"];

        if ($this->upload->userId) {
            $suffixes[] = "user.{$this->upload->userId}";
        }

        return $suffixes;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // Internal-by-default: disk and finalPath are server-side details
        // (a path inside config('chunky.disk')'s root). Most consumers
        // only need the upload id and human-readable file metadata. Set
        // chunky.broadcasting.expose_internal_paths = true to opt in.
        $payload = [
            'uploadId' => $this->uploadId,
            'fileName' => $this->upload->fileName,
            'fileSize' => $this->upload->fileSize,
            'context' => $this->upload->context,
            'status' => $this->upload->status->value,
        ];

        if (config('chunky.broadcasting.expose_internal_paths', false)) {
            $payload['finalPath'] = $this->finalPath;
            $payload['disk'] = $this->disk;
        }

        return $payload;
    }
}
