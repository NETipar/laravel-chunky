<?php

declare(strict_types=1);

namespace NETipar\Chunky\Exceptions;

use NETipar\Chunky\Exceptions\Concerns\RendersErrorEnvelope;
use NETipar\Chunky\Http\ErrorCode;

class MissingFileChecksumException extends ChunkyException
{
    use RendersErrorEnvelope;

    public static function forUpload(string $uploadId): self
    {
        return new self("Upload '{$uploadId}' completed without the required file_checksum (integrity.require_full_file is enabled).");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ValidationFailed;
    }
}
