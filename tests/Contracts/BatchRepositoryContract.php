<?php

declare(strict_types=1);

use NETipar\Chunky\Domain\BatchCounter;
use NETipar\Chunky\Domain\BatchRecord;
use NETipar\Chunky\Domain\BatchStatus;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Ports\BatchRepository;

if (! function_exists('contractBatchRecord')) {
    function contractBatchRecord(string $id, int $totalFiles = 3): BatchRecord
    {
        return new BatchRecord(batchId: $id, totalFiles: $totalFiles);
    }
}

/**
 * @param  Closure(): BatchRepository  $makeRepository
 */
function batchRepositoryContract(Closure $makeRepository): void
{
    $finalize = static fn (BatchRecord $b): BatchStatus => $b->resolveFinalStatus();

    it('creates and finds a batch', function () use ($makeRepository) {
        $repo = $makeRepository();
        $repo->create(contractBatchRecord('b-1'));

        expect($repo->find('b-1')?->batchId)->toBe('b-1');
    });

    it('returns null for a missing batch', function () use ($makeRepository) {
        expect($makeRepository()->find('nope'))->toBeNull();
    });

    it('enters in_progress on the first increment without finalizing early', function () use ($makeRepository, $finalize) {
        $repo = $makeRepository();
        $repo->create(contractBatchRecord('b-1', totalFiles: 3));

        $finalizeCalls = 0;
        $tracked = function (BatchRecord $b) use (&$finalizeCalls, $finalize): BatchStatus {
            $finalizeCalls++;

            return $finalize($b);
        };

        $record = $repo->increment('b-1', BatchCounter::Completed, $tracked);

        expect($record->status)->toBe(BatchStatus::InProgress);
        expect($record->completedFiles)->toBe(1);
        expect($finalizeCalls)->toBe(0);
    });

    it('finalizes to completed when every file succeeds', function () use ($makeRepository, $finalize) {
        $repo = $makeRepository();
        $repo->create(contractBatchRecord('b-1', totalFiles: 2));

        $repo->increment('b-1', BatchCounter::Completed, $finalize);
        $final = $repo->increment('b-1', BatchCounter::Completed, $finalize);

        expect($final->status)->toBe(BatchStatus::Completed);
        expect($final->completedFiles)->toBe(2);
    });

    it('finalizes to partially_completed when at least one file fails', function () use ($makeRepository, $finalize) {
        $repo = $makeRepository();
        $repo->create(contractBatchRecord('b-1', totalFiles: 2));

        $repo->increment('b-1', BatchCounter::Completed, $finalize);
        $final = $repo->increment('b-1', BatchCounter::Failed, $finalize);

        expect($final->status)->toBe(BatchStatus::PartiallyCompleted);
        expect($final->completedFiles)->toBe(1);
        expect($final->failedFiles)->toBe(1);
    });

    it('calls the finalize closure exactly once, on the closing increment', function () use ($makeRepository, $finalize) {
        $repo = $makeRepository();
        $repo->create(contractBatchRecord('b-1', totalFiles: 3));

        $calls = 0;
        $counting = function (BatchRecord $b) use (&$calls, $finalize): BatchStatus {
            $calls++;

            return $finalize($b);
        };

        $repo->increment('b-1', BatchCounter::Completed, $counting);
        $repo->increment('b-1', BatchCounter::Failed, $counting);
        $repo->increment('b-1', BatchCounter::Completed, $counting);

        expect($calls)->toBe(1);
    });

    it('throws when incrementing a missing batch', function () use ($makeRepository, $finalize) {
        expect(fn () => $makeRepository()->increment('nope', BatchCounter::Completed, $finalize))
            ->toThrow(ChunkyException::class);
    });

    it('transitions atomically only from the expected status', function () use ($makeRepository) {
        $repo = $makeRepository();
        $repo->create(contractBatchRecord('b-1'));

        expect($repo->transition('b-1', BatchStatus::Pending, BatchStatus::Cancelled))->toBeTrue();
        expect($repo->find('b-1')?->status)->toBe(BatchStatus::Cancelled);
        expect($repo->transition('b-1', BatchStatus::Pending, BatchStatus::InProgress))->toBeFalse();
    });

    it('deletes a batch', function () use ($makeRepository) {
        $repo = $makeRepository();
        $repo->create(contractBatchRecord('b-1'));

        $repo->delete('b-1');

        expect($repo->find('b-1'))->toBeNull();
    });
}
