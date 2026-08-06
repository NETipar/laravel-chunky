<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use NETipar\Chunky\Contracts\ChunkyEvent;
use NETipar\Chunky\Events\Concerns\BroadcastsChunkyEvent;

final class ChunkUploadFailed implements ChunkyEvent, ShouldBroadcast
{
    use BroadcastsChunkyEvent;
    use Dispatchable;

    public function __construct(
        public readonly string $uploadId,
        public readonly int $chunkIndex,
        public readonly string $reason,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chunky.upload.'.$this->uploadId)];
    }

    public function broadcastAs(): string
    {
        return 'chunk.upload_failed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->versionedPayload([
            'upload_id' => $this->uploadId,
            'chunk_index' => $this->chunkIndex,
            'reason' => $this->reason,
        ]);
    }
}
