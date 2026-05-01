<?php

namespace NETipar\Chunky\Enums;

enum UploadStatus: string
{
    case Pending = 'pending';
    case Assembling = 'assembling';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * True when the upload has settled into a state from which it cannot
     * progress any further. Used to gate retries, late chunk POSTs, and
     * status overwrites.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Expired, self::Cancelled => true,
            self::Pending, self::Assembling => false,
        };
    }
}
