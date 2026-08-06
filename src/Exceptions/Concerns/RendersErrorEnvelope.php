<?php

declare(strict_types=1);

namespace NETipar\Chunky\Exceptions\Concerns;

use Illuminate\Http\JsonResponse;
use NETipar\Chunky\Http\ErrorCode;

/**
 * Makes a ChunkyException renderable by Laravel's handler as the standard error
 * envelope. The client sees the ErrorCode's generic message, never the internal
 * exception message.
 */
trait RendersErrorEnvelope
{
    abstract public function errorCode(): ErrorCode;

    public function render(): JsonResponse
    {
        $code = $this->errorCode();

        return new JsonResponse([
            'error' => [
                'code' => $code->value,
                'message' => $code->message(),
            ],
        ], $code->status());
    }
}
