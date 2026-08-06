<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use NETipar\Chunky\Http\Requests\CompleteDirectUploadRequest;
use NETipar\Chunky\Services\DirectUploadService;

final class CompleteDirectUploadController
{
    public function __invoke(CompleteDirectUploadRequest $request, string $uploadId, DirectUploadService $direct): JsonResponse
    {
        $result = $direct->complete($uploadId, $request->parts());

        return new JsonResponse([
            'status' => 'completed',
            'progress' => 100.0,
            'file' => $result->file?->toArray(),
            'payload' => $result->payload,
        ]);
    }
}
