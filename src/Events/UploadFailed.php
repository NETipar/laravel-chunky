<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use NETipar\Chunky\Contracts\ChunkyEvent;
use NETipar\Chunky\Domain\UploadRecord;
use NETipar\Chunky\Events\Concerns\BroadcastsChunkyEvent;

final class UploadFailed implements ChunkyEvent, ShouldBroadcast
{
    use BroadcastsChunkyEvent;
    use Dispatchable;

    public function __construct(
        public readonly UploadRecord $upload,
        public readonly string $reason,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chunky.upload.'.$this->upload->uploadId)];
    }

    public function broadcastAs(): string
    {
        return 'upload.failed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->versionedPayload($this->upload->toPublicArray() + ['reason' => $this->reason]);
    }
}
