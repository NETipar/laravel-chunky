<?php

declare(strict_types=1);

namespace NETipar\Chunky\Tests\Doubles;

use Closure;
use NETipar\Chunky\Domain\BatchCounter;
use NETipar\Chunky\Domain\BatchRecord;
use NETipar\Chunky\Domain\BatchStatus;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Ports\BatchRepository;

final class InMemoryBatchRepository implements BatchRepository
{
    /** @var array<string, BatchRecord> */
    private array $store = [];

    public function create(BatchRecord $record): void
    {
        $this->store[$record->batchId] = $record;
    }

    public function find(string $batchId): ?BatchRecord
    {
        return $this->store[$batchId] ?? null;
    }

    public function increment(string $batchId, BatchCounter $counter, Closure $onFinalize): BatchRecord
    {
        $record = $this->store[$batchId] ?? throw new ChunkyException("Batch '{$batchId}' not found.");

        $record = $record->with(
            completedFiles: $record->completedFiles + ($counter === BatchCounter::Completed ? 1 : 0),
            failedFiles: $record->failedFiles + ($counter === BatchCounter::Failed ? 1 : 0),
        );

        if ($record->status === BatchStatus::Pending) {
            $record = $record->with(status: BatchStatus::InProgress);
        }

        if ($record->isFinished() && ! $record->status->isTerminal()) {
            $record = $record->with(status: $onFinalize($record));
        }

        $this->store[$batchId] = $record;

        return $record;
    }

    public function transition(string $batchId, BatchStatus $from, BatchStatus $to): bool
    {
        $record = $this->store[$batchId] ?? null;

        if ($record === null || $record->status !== $from) {
            return false;
        }

        $this->store[$batchId] = $record->with(status: $to);

        return true;
    }

    public function delete(string $batchId): void
    {
        unset($this->store[$batchId]);
    }
}
