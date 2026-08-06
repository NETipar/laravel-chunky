<?php

declare(strict_types=1);

namespace NETipar\Chunky\Exceptions;

use NETipar\Chunky\Exceptions\Concerns\RendersErrorEnvelope;
use NETipar\Chunky\Http\ErrorCode;

class ChunkIndexOutOfRangeException extends ChunkyException
{
    use RendersErrorEnvelope;

    public static function make(int $chunkIndex, int $totalChunks): self
    {
        return new self("Chunk index {$chunkIndex} is out of range (0..{$totalChunks}).");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ChunkIndexOutOfRange;
    }
}
