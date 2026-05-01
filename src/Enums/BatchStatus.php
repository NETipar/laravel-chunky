<?php

declare(strict_types=1);

namespace NETipar\Chunky\Enums;

enum BatchStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case PartiallyCompleted = 'partially_completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * True when the batch has settled into a state from which it cannot
     * accept further uploads. Used by validateBatchExists and the request
     * layer to reject late initiateInBatch calls.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::PartiallyCompleted, self::Cancelled, self::Expired => true,
            self::Pending, self::Processing => false,
        };
    }

    /**
     * @return array<int, self>
     */
    public static function terminalCases(): array
    {
        return [
            self::Completed,
            self::PartiallyCompleted,
            self::Cancelled,
            self::Expired,
        ];
    }
}
