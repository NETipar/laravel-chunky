<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events;

use NETipar\Chunky\Data\UploadMetadata;

class UploadFailed extends AbstractChunkyEvent
{
    public readonly string $uploadId;

    public readonly string $disk;

    /** @var array<string, mixed>|null */
    public readonly ?array $metadata;

    public function __construct(
        public readonly UploadMetadata $upload,
        public readonly string $reason,
    ) {
        $this->uploadId = $upload->uploadId;
        $this->disk = $upload->disk;
        $this->metadata = $upload->metadata ?: null;
    }

    protected function broadcastEventKey(): string
    {
        return 'UploadFailed';
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
        $payload = [
            'uploadId' => $this->uploadId,
            'fileName' => $this->upload->fileName,
            'fileSize' => $this->upload->fileSize,
            'context' => $this->upload->context,
            'reason' => $this->reason,
        ];

        if (config('chunky.broadcasting.expose_internal_paths', false)) {
            $payload['disk'] = $this->disk;
        }

        return $payload;
    }
}
