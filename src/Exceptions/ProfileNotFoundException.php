<?php

declare(strict_types=1);

namespace NETipar\Chunky\Exceptions;

use NETipar\Chunky\Exceptions\Concerns\RendersErrorEnvelope;
use NETipar\Chunky\Http\ErrorCode;

class ProfileNotFoundException extends ChunkyException
{
    use RendersErrorEnvelope;

    public static function forName(string $name): self
    {
        return new self("Upload profile '{$name}' is not registered.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ProfileNotFound;
    }
}
