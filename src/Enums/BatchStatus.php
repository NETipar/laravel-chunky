<?php

namespace NETipar\Chunky\Enums;

enum BatchStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case PartiallyCompleted = 'partially_completed';
    case Expired = 'expired';
}
