<?php

declare(strict_types=1);

namespace NETipar\Chunky\Ports;

use Closure;
use NETipar\Chunky\Domain\BatchCounter;
use NETipar\Chunky\Domain\BatchRecord;
use NETipar\Chunky\Domain\BatchStatus;

interface BatchRepository
{
    public function create(BatchRecord $record): void;

    public function find(string $batchId): ?BatchRecord;

    /**
     * Atomically increment a counter and detect finalization. The closure is
     * called — inside the lock/transaction — if and only if this increment is
     * the one that finished the batch; it receives the just-incremented record
     * and returns the terminal status to settle into. The dispatch of any
     * finalization event happens OUTSIDE the lock, in the caller, from the
     * returned record.
     *
     * @param  Closure(BatchRecord): BatchStatus  $onFinalize
     */
    public function increment(string $batchId, BatchCounter $counter, Closure $onFinalize): BatchRecord;

    public function transition(string $batchId, BatchStatus $from, BatchStatus $to): bool;

    public function delete(string $batchId): void;
}
