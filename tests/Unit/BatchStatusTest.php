<?php

declare(strict_types=1);

use NETipar\Chunky\Domain\BatchStatus;
use NETipar\Chunky\Exceptions\InvalidStateException;

it('has the five v1 cases', function () {
    expect(array_map(fn (BatchStatus $s): string => $s->value, BatchStatus::cases()))
        ->toBe(['pending', 'in_progress', 'completed', 'partially_completed', 'cancelled']);
});

it('classifies terminal states', function () {
    expect(BatchStatus::Pending->isTerminal())->toBeFalse();
    expect(BatchStatus::InProgress->isTerminal())->toBeFalse();
    expect(BatchStatus::Completed->isTerminal())->toBeTrue();
    expect(BatchStatus::PartiallyCompleted->isTerminal())->toBeTrue();
    expect(BatchStatus::Cancelled->isTerminal())->toBeTrue();
});

it('allows the lifecycle transitions', function () {
    expect(BatchStatus::Pending->canTransitionTo(BatchStatus::InProgress))->toBeTrue();
    expect(BatchStatus::Pending->canTransitionTo(BatchStatus::Cancelled))->toBeTrue();
    expect(BatchStatus::InProgress->canTransitionTo(BatchStatus::Completed))->toBeTrue();
    expect(BatchStatus::InProgress->canTransitionTo(BatchStatus::PartiallyCompleted))->toBeTrue();
    expect(BatchStatus::InProgress->canTransitionTo(BatchStatus::Cancelled))->toBeTrue();
});

it('throws asserting an illegal transition', function () {
    expect(fn () => BatchStatus::Completed->assertCanTransitionTo(BatchStatus::InProgress))
        ->toThrow(InvalidStateException::class);
});
