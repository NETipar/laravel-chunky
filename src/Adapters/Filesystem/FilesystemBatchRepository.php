<?php

declare(strict_types=1);

namespace NETipar\Chunky\Adapters\Filesystem;

use Closure;
use NETipar\Chunky\Domain\BatchCounter;
use NETipar\Chunky\Domain\BatchRecord;
use NETipar\Chunky\Domain\BatchStatus;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Ports\BatchRepository;
use NETipar\Chunky\Ports\LockProvider;

final class FilesystemBatchRepository implements BatchRepository
{
    public function __construct(
        private readonly JsonFileStore $store,
        private readonly LockProvider $lock,
        private readonly string $directory = 'chunky/state/batches',
    ) {}

    public function create(BatchRecord $record): void
    {
        $this->store->write($this->path($record->batchId), $record->toArray());
    }

    public function find(string $batchId): ?BatchRecord
    {
        $data = $this->store->read($this->path($batchId));

        return $data === null ? null : BatchRecord::fromArray($data);
    }

    public function increment(string $batchId, BatchCounter $counter, Closure $onFinalize): BatchRecord
    {
        return $this->lock->withLock($this->lockKey($batchId), function () use ($batchId, $counter, $onFinalize): BatchRecord {
            $record = $this->find($batchId);

            if ($record === null) {
                throw new ChunkyException("Batch '{$batchId}' not found.");
            }

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

            $this->store->write($this->path($batchId), $record->toArray());

            return $record;
        });
    }

    public function transition(string $batchId, BatchStatus $from, BatchStatus $to): bool
    {
        return $this->lock->withLock($this->lockKey($batchId), function () use ($batchId, $from, $to): bool {
            $record = $this->find($batchId);

            if ($record === null || $record->status !== $from) {
                return false;
            }

            $this->store->write($this->path($batchId), $record->with(status: $to)->toArray());

            return true;
        });
    }

    public function delete(string $batchId): void
    {
        $this->store->delete($this->path($batchId));
    }

    private function path(string $batchId): string
    {
        return $this->directory.'/'.$this->safeId($batchId).'.json';
    }

    private function lockKey(string $batchId): string
    {
        return 'chunky:batch:'.$batchId;
    }

    private function safeId(string $batchId): string
    {
        if ($batchId === ''
            || str_contains($batchId, '/')
            || str_contains($batchId, '\\')
            || str_contains($batchId, '..')
            || str_contains($batchId, "\0")) {
            throw new ChunkyException("Unsafe batch id '{$batchId}'.");
        }

        return $batchId;
    }
}
