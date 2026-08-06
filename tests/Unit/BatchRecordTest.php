<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use NETipar\Chunky\Domain\BatchRecord;
use NETipar\Chunky\Domain\BatchStatus;

it('includes failed files in progress (terminal semantics)', function () {
    $batch = new BatchRecord(
        batchId: 'b-1',
        totalFiles: 4,
        completedFiles: 3,
        failedFiles: 1,
        status: BatchStatus::PartiallyCompleted,
    );

    expect($batch->progress())->toBe(100.0);
    expect($batch->successProgress())->toBe(75.0);
});

it('reports zero progress with no files', function () {
    $batch = new BatchRecord(batchId: 'b-2', totalFiles: 0);

    expect($batch->progress())->toBe(0.0);
    expect($batch->successProgress())->toBe(0.0);
});

it('decouples success progress from overall progress', function () {
    $batch = new BatchRecord(batchId: 'b-3', totalFiles: 10, completedFiles: 4, failedFiles: 2, status: BatchStatus::InProgress);

    expect($batch->progress())->toBe(60.0);
    expect($batch->successProgress())->toBe(40.0);
});

it('resolves the terminal status by failure count', function () {
    $clean = new BatchRecord(batchId: 'b-4', totalFiles: 2, completedFiles: 2);
    $partial = new BatchRecord(batchId: 'b-5', totalFiles: 2, completedFiles: 1, failedFiles: 1);

    expect($clean->isFinished())->toBeTrue();
    expect($clean->resolveFinalStatus())->toBe(BatchStatus::Completed);
    expect($partial->resolveFinalStatus())->toBe(BatchStatus::PartiallyCompleted);
});

it('accepts a DateTimeImmutable (incl. CarbonImmutable) for expiry', function () {
    $expires = CarbonImmutable::parse('2026-01-01T06:00:00+00:00')->toDateTimeImmutable();
    $batch = new BatchRecord(batchId: 'b-6', totalFiles: 1, expiresAt: $expires);

    $restored = BatchRecord::fromArray($batch->toArray());

    expect($restored->expiresAt?->format('c'))->toBe('2026-01-01T06:00:00+00:00');
});
