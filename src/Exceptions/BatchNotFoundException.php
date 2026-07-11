<?php

declare(strict_types=1);

namespace NETipar\Chunky\Exceptions;

use NETipar\Chunky\Exceptions\Concerns\RendersErrorEnvelope;
use NETipar\Chunky\Http\ErrorCode;

class BatchNotFoundException extends ChunkyException
{
    use RendersErrorEnvelope;

    public static function forBatch(string $batchId): self
    {
        return new self("Batch '{$batchId}' not found.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::BatchNotFound;
    }
}
