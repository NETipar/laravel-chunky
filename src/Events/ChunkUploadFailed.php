<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events;

use Throwable;

class ChunkUploadFailed extends AbstractChunkyEvent
{
    public function __construct(
        public readonly string $uploadId,
        public readonly int $chunkIndex,
        public readonly Throwable $exception,
    ) {}

    protected function broadcastEventKey(): string
    {
        return 'ChunkUploadFailed';
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
            'chunkIndex' => $this->chunkIndex,
            'message' => $this->exception->getMessage(),
        ];
    }
}
