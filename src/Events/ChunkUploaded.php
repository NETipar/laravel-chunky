<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events;

class ChunkUploaded extends AbstractChunkyEvent
{
    public readonly float $progress;

    public function __construct(
        public readonly string $uploadId,
        public readonly int $chunkIndex,
        public readonly int $totalChunks,
    ) {
        $this->progress = round(($chunkIndex + 1) / $totalChunks * 100, 2);
    }

    protected function broadcastEventKey(): string
    {
        return 'ChunkUploaded';
    }

    /**
     * @return array<int, string>
     */
    protected function broadcastChannelSuffixes(): array
    {
        // Per-chunk progress is expensive on a busy upload — broadcast
        // only when explicitly enabled via chunky.broadcasting.events.
        // Off by default in the shipped config.
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
            'totalChunks' => $this->totalChunks,
            'progress' => $this->progress,
        ];
    }
}
