<?php

declare(strict_types=1);

namespace NETipar\Chunky\Exceptions;

use NETipar\Chunky\Exceptions\Concerns\RendersErrorEnvelope;
use NETipar\Chunky\Http\ErrorCode;

class UploadExpiredException extends ChunkyException
{
    use RendersErrorEnvelope;

    public static function forUpload(string $uploadId): self
    {
        return new self("Upload '{$uploadId}' has expired and can no longer accept chunks.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::UploadExpired;
    }
}
