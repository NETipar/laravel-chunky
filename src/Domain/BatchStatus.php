<?php

declare(strict_types=1);

namespace NETipar\Chunky\Domain;

use NETipar\Chunky\Exceptions\InvalidStateException;

enum BatchStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case PartiallyCompleted = 'partially_completed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Completed, self::PartiallyCompleted, self::Cancelled],
            self::Completed, self::PartiallyCompleted, self::Cancelled => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function assertCanTransitionTo(self $to): void
    {
        if (! $this->canTransitionTo($to)) {
            throw InvalidStateException::batch($this, $to);
        }
    }
}
