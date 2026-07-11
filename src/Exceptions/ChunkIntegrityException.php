<?php

declare(strict_types=1);

namespace NETipar\Chunky\Exceptions;

use NETipar\Chunky\Exceptions\Concerns\RendersErrorEnvelope;
use NETipar\Chunky\Http\ErrorCode;

class ChunkIntegrityException extends ChunkyException
{
    use RendersErrorEnvelope;

    public static function checksumMismatch(string $uploadId, int $chunkIndex): self
    {
        return new self("Checksum mismatch for chunk {$chunkIndex} of upload '{$uploadId}'.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ChecksumMismatch;
    }
}
