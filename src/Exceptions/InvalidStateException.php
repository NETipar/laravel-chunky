<?php

declare(strict_types=1);

namespace NETipar\Chunky\Exceptions;

use NETipar\Chunky\Domain\BatchStatus;
use NETipar\Chunky\Domain\UploadStatus;
use NETipar\Chunky\Exceptions\Concerns\RendersErrorEnvelope;
use NETipar\Chunky\Http\ErrorCode;

class InvalidStateException extends ChunkyException
{
    use RendersErrorEnvelope;

    public static function upload(UploadStatus $from, UploadStatus $to): self
    {
        return new self("Cannot transition upload from '{$from->value}' to '{$to->value}'.");
    }

    public static function batch(BatchStatus $from, BatchStatus $to): self
    {
        return new self("Cannot transition batch from '{$from->value}' to '{$to->value}'.");
    }

    public static function batchFull(string $batchId, int $totalFiles): self
    {
        return new self("Batch '{$batchId}' already has its declared {$totalFiles} member upload(s).");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidState;
    }
}
