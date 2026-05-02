<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use NETipar\Chunky\Data\BatchMetadata;
use NETipar\Chunky\Enums\BatchStatus;

it('progress() includes failed files (terminal-state semantics)', function () {
    $batch = new BatchMetadata(
        batchId: 'b-1',
        totalFiles: 4,
        completedFiles: 3,
        failedFiles: 1,
        status: BatchStatus::PartiallyCompleted,
    );

    expect($batch->progress())->toBe(100.0);
    expect($batch->successProgress())->toBe(75.0);
});

it('progress() reports 0 when no files', function () {
    $batch = new BatchMetadata(
        batchId: 'b-2',
        totalFiles: 0,
        completedFiles: 0,
        failedFiles: 0,
        status: BatchStatus::Pending,
    );

    expect((float) $batch->progress())->toBe(0.0);
    expect((float) $batch->successProgress())->toBe(0.0);
});

it('successProgress() decouples from progress() for partial batches', function () {
    $batch = new BatchMetadata(
        batchId: 'b-3',
        totalFiles: 10,
        completedFiles: 4,
        failedFiles: 2,
        status: BatchStatus::Processing,
    );

    expect($batch->progress())->toBe(60.0);
    expect($batch->successProgress())->toBe(40.0);
});

it('accepts CarbonImmutable for expiresAt (Date::use(CarbonImmutable))', function () {
    // Apps that opt into immutable dates via `Date::use(CarbonImmutable::class)`
    // pass CarbonImmutable instances around — including the now()
    // result that flows into ChunkyManager::initiateBatch() and on into
    // this constructor. Regression test for the v0.22.0 type-error
    // where the parameter was annotated `?Illuminate\Support\Carbon`.
    $expires = CarbonImmutable::now()->addHours(6);

    $batch = new BatchMetadata(
        batchId: 'b-4',
        totalFiles: 1,
        completedFiles: 0,
        failedFiles: 0,
        status: BatchStatus::Pending,
        expiresAt: $expires,
    );

    expect($batch->expiresAt)->toBeInstanceOf(CarbonImmutable::class);
    expect($batch->isExpired())->toBeFalse();
});

it('accepts mutable Carbon for expiresAt', function () {
    $expires = Carbon::now()->addHours(6);

    $batch = new BatchMetadata(
        batchId: 'b-5',
        totalFiles: 1,
        completedFiles: 0,
        failedFiles: 0,
        status: BatchStatus::Pending,
        expiresAt: $expires,
    );

    expect($batch->expiresAt)->toBeInstanceOf(Carbon::class);
});
