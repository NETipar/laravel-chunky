<?php

declare(strict_types=1);

namespace NETipar\Chunky\Exceptions;

class ChunkIntegrityException extends ChunkyException
{
    public static function checksumMismatch(string $uploadId, int $chunkIndex): self
    {
        return new self("Checksum mismatch for chunk {$chunkIndex} of upload {$uploadId}.");
    }
}
