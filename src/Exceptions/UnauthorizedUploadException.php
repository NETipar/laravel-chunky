<?php

declare(strict_types=1);

namespace NETipar\Chunky\Exceptions;

use NETipar\Chunky\Exceptions\Concerns\RendersErrorEnvelope;
use NETipar\Chunky\Http\ErrorCode;

class UnauthorizedUploadException extends ChunkyException
{
    use RendersErrorEnvelope;

    public static function make(): self
    {
        return new self('This action is unauthorized.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::Unauthorized;
    }
}
