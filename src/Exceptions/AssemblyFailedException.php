<?php

declare(strict_types=1);

namespace NETipar\Chunky\Exceptions;

use NETipar\Chunky\Exceptions\Concerns\RendersErrorEnvelope;
use NETipar\Chunky\Http\ErrorCode;
use Throwable;

class AssemblyFailedException extends ChunkyException
{
    use RendersErrorEnvelope;

    public static function forUpload(string $uploadId, Throwable $previous): self
    {
        return new self("Assembly failed for upload '{$uploadId}': {$previous->getMessage()}", previous: $previous);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::AssemblyFailed;
    }
}
