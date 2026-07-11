<?php

declare(strict_types=1);

namespace NETipar\Chunky\Exceptions;

use NETipar\Chunky\Exceptions\Concerns\RendersErrorEnvelope;
use NETipar\Chunky\Http\ErrorCode;

class UploadNotFoundException extends ChunkyException
{
    use RendersErrorEnvelope;

    public static function forUpload(string $uploadId): self
    {
        return new self("Upload '{$uploadId}' not found.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::UploadNotFound;
    }
}
