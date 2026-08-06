<?php

declare(strict_types=1);

use NETipar\Chunky\Domain\UploadStatus;
use NETipar\Chunky\Exceptions\InvalidStateException;

it('has the six v1 cases', function () {
    expect(array_map(fn (UploadStatus $s): string => $s->value, UploadStatus::cases()))
        ->toBe(['pending', 'uploading', 'assembling', 'completed', 'failed', 'cancelled']);
});

it('classifies terminal states', function () {
    expect(UploadStatus::Pending->isTerminal())->toBeFalse();
    expect(UploadStatus::Uploading->isTerminal())->toBeFalse();
    expect(UploadStatus::Assembling->isTerminal())->toBeFalse();
    expect(UploadStatus::Completed->isTerminal())->toBeTrue();
    expect(UploadStatus::Failed->isTerminal())->toBeTrue();
    expect(UploadStatus::Cancelled->isTerminal())->toBeTrue();
});

it('allows the lifecycle transitions', function () {
    expect(UploadStatus::Pending->canTransitionTo(UploadStatus::Uploading))->toBeTrue();
    expect(UploadStatus::Pending->canTransitionTo(UploadStatus::Cancelled))->toBeTrue();
    expect(UploadStatus::Uploading->canTransitionTo(UploadStatus::Assembling))->toBeTrue();
    expect(UploadStatus::Assembling->canTransitionTo(UploadStatus::Completed))->toBeTrue();
    expect(UploadStatus::Assembling->canTransitionTo(UploadStatus::Failed))->toBeTrue();
});

it('rejects illegal transitions', function () {
    expect(UploadStatus::Pending->canTransitionTo(UploadStatus::Completed))->toBeFalse();
    expect(UploadStatus::Assembling->canTransitionTo(UploadStatus::Cancelled))->toBeFalse();
    expect(UploadStatus::Completed->canTransitionTo(UploadStatus::Uploading))->toBeFalse();
});

it('asserts a legal transition without throwing', function () {
    UploadStatus::Pending->assertCanTransitionTo(UploadStatus::Uploading);

    expect(true)->toBeTrue();
});

it('throws asserting an illegal transition', function () {
    expect(fn () => UploadStatus::Completed->assertCanTransitionTo(UploadStatus::Failed))
        ->toThrow(InvalidStateException::class);
});
