<?php

declare(strict_types=1);

namespace NETipar\Chunky\Domain;

enum BatchCounter: string
{
    case Completed = 'completed';
    case Failed = 'failed';
}
