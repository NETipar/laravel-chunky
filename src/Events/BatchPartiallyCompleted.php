<?php

namespace NETipar\Chunky\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BatchPartiallyCompleted implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $batchId,
        public readonly int $completedFiles,
        public readonly int $failedFiles,
        public readonly int $totalFiles,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $prefix = config('chunky.broadcasting.channel_prefix', 'chunky');

        return [new PrivateChannel("{$prefix}.batches.{$this->batchId}")];
    }

    public function broadcastAs(): string
    {
        return 'BatchPartiallyCompleted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'batchId' => $this->batchId,
            'completedFiles' => $this->completedFiles,
            'failedFiles' => $this->failedFiles,
            'totalFiles' => $this->totalFiles,
        ];
    }

    public function broadcastQueue(): ?string
    {
        return config('chunky.broadcasting.queue');
    }

    public function broadcastWhen(): bool
    {
        return config('chunky.broadcasting.enabled', false);
    }
}
