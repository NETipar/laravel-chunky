<?php

declare(strict_types=1);

namespace NETipar\Chunky\Exceptions;

use NETipar\Chunky\Exceptions\Concerns\RendersErrorEnvelope;
use NETipar\Chunky\Http\ErrorCode;

class InvalidDirectUploadException extends ChunkyException
{
    use RendersErrorEnvelope;

    public static function partTooSmall(int $chunkSize): self
    {
        return new self("chunks.size {$chunkSize} is below the 5 MB S3 part minimum required by the direct_s3 transport.");
    }

    public static function tooManyParts(int $totalChunks): self
    {
        return new self("The file needs {$totalChunks} parts, above the 10000 S3 part maximum for the direct_s3 transport.");
    }

    public static function incompleteParts(int $given, int $expected): self
    {
        return new self("complete requires all {$expected} parts, got {$given}.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ValidationFailed;
    }
}
