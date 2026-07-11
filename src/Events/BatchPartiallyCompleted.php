<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use NETipar\Chunky\Contracts\ChunkyEvent;
use NETipar\Chunky\Events\Concerns\BroadcastsChunkyEvent;

final class BatchPartiallyCompleted implements ChunkyEvent, ShouldBroadcast
{
    use BroadcastsChunkyEvent;
    use Dispatchable;

    public function __construct(
        public readonly string $batchId,
        public readonly int $completedFiles,
        public readonly int $failedFiles,
        public readonly int $totalFiles,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chunky.batch.'.$this->batchId)];
    }

    public function broadcastAs(): string
    {
        return 'batch.partially_completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->versionedPayload([
            'batch_id' => $this->batchId,
            'completed_files' => $this->completedFiles,
            'failed_files' => $this->failedFiles,
            'total_files' => $this->totalFiles,
        ]);
    }
}
