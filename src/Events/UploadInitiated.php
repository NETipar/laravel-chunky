<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events;

class UploadInitiated extends AbstractChunkyEvent
{
    public function __construct(
        public readonly string $uploadId,
        public readonly string $fileName,
        public readonly int $fileSize,
        public readonly int $totalChunks,
    ) {}

    protected function broadcastEventKey(): string
    {
        return 'UploadInitiated';
    }

    /**
     * @return array<int, string>
     */
    protected function broadcastChannelSuffixes(): array
    {
        return ["uploads.{$this->uploadId}"];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'uploadId' => $this->uploadId,
            'fileName' => $this->fileName,
            'fileSize' => $this->fileSize,
            'totalChunks' => $this->totalChunks,
        ];
    }
}
