<?php

declare(strict_types=1);

namespace NETipar\Chunky\Exceptions;

class UploadExpiredException extends ChunkyException
{
    public static function forUpload(string $uploadId): self
    {
        return new self("Upload {$uploadId} has expired.");
    }
}
