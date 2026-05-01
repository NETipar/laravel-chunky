<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events;

class BatchInitiated extends AbstractChunkyEvent
{
    public function __construct(
        public readonly string $batchId,
        public readonly int $totalFiles,
        public readonly ?string $userId = null,
    ) {}

    protected function broadcastEventKey(): string
    {
        return 'BatchInitiated';
    }

    /**
     * @return array<int, string>
     */
    protected function broadcastChannelSuffixes(): array
    {
        $suffixes = ["batches.{$this->batchId}"];

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
            'batchId' => $this->batchId,
            'totalFiles' => $this->totalFiles,
        ];
    }
}
