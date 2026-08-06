<?php

declare(strict_types=1);

namespace NETipar\Chunky\Domain;

use NETipar\Chunky\Exceptions\InvalidStateException;

enum UploadStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Assembling = 'assembling';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * The lifecycle transition table. This models the user-facing lifecycle;
     * the assembly claim (Assembling -> Assembling stale takeover) is a
     * repository-level compare-and-swap, not a domain transition.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Uploading, self::Cancelled],
            self::Uploading => [self::Assembling, self::Cancelled],
            self::Assembling => [self::Completed, self::Failed],
            self::Completed, self::Failed, self::Cancelled => [],
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
            throw InvalidStateException::upload($this, $to);
        }
    }
}
