<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Domain\BatchCounter;
use NETipar\Chunky\Domain\BatchRecord;
use NETipar\Chunky\Domain\BatchStatus;
use NETipar\Chunky\Domain\UploadStatus;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

dataset('drivers', ['database', 'filesystem']);

it('resolves two racing transitions from the same status to exactly one winner', function (string $driver) {
    $repo = makeUploadRepository($driver);
    $repo->create(contractUploadRecord('race-1', status: UploadStatus::Pending));

    $first = $repo->transition('race-1', UploadStatus::Pending, UploadStatus::Uploading);
    $second = $repo->transition('race-1', UploadStatus::Pending, UploadStatus::Uploading);

    expect([$first, $second])->toContain(true)->toContain(false);
    expect($repo->find('race-1')->status)->toBe(UploadStatus::Uploading);
})->with('drivers');

it('accumulates every distinct chunk index without loss', function (string $driver) {
    $repo = makeUploadRepository($driver);
    $repo->create(contractUploadRecord('acc-1', totalChunks: 4));

    foreach ([0, 3, 1, 2, 1, 0] as $index) {
        $repo->markChunk('acc-1', $index);
    }

    $record = $repo->find('acc-1');
    expect($record->uploadedChunks)->toBe([0, 1, 2, 3]);
    expect($record->isComplete())->toBeTrue();
})->with('drivers');

it('only claims assembly once across repeated claim attempts', function (string $driver) {
    $repo = makeUploadRepository($driver);
    $repo->create(contractUploadRecord('claim-1', status: UploadStatus::Uploading));

    $claimedAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    $wins = 0;
    foreach (range(1, 5) as $ignored) {
        if ($repo->transition('claim-1', UploadStatus::Uploading, UploadStatus::Assembling, ['claimed_at' => $claimedAt])) {
            $wins++;
        }
    }

    expect($wins)->toBe(1);
    expect($repo->find('claim-1')->status)->toBe(UploadStatus::Assembling);
})->with('drivers');

it('finalizes a batch exactly once over a sequence of increments', function (string $driver) {
    $repo = makeBatchRepository($driver);
    $repo->create(new BatchRecord(batchId: 'fin-1', totalFiles: 5));

    $finalizeCalls = 0;
    $onFinalize = function (BatchRecord $b) use (&$finalizeCalls): BatchStatus {
        $finalizeCalls++;

        return $b->resolveFinalStatus();
    };

    $sequence = [
        BatchCounter::Completed,
        BatchCounter::Completed,
        BatchCounter::Failed,
        BatchCounter::Completed,
        BatchCounter::Completed,
    ];

    $last = null;
    foreach ($sequence as $counter) {
        $last = $repo->increment('fin-1', $counter, $onFinalize);
    }

    expect($finalizeCalls)->toBe(1);
    expect($last->status)->toBe(BatchStatus::PartiallyCompleted);
    expect($last->completedFiles)->toBe(4);
    expect($last->failedFiles)->toBe(1);
})->with('drivers');
