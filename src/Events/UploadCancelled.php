<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events;

class UploadCancelled extends AbstractChunkyEvent
{
    public function __construct(
        public readonly string $uploadId,
        public readonly ?string $batchId = null,
        public readonly ?string $userId = null,
    ) {}

    protected function broadcastEventKey(): string
    {
        return 'UploadCancelled';
    }

    /**
     * @return array<int, string>
     */
    protected function broadcastChannelSuffixes(): array
    {
        $suffixes = ["uploads.{$this->uploadId}"];

        if ($this->userId) {
            $suffixes[] = "user.{$this->userId}";
        }

        return $suffixes;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'uploadId' => $this->uploadId,
            'batchId' => $this->batchId,
        ];
    }
}
