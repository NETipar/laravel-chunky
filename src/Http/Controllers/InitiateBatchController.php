<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use NETipar\Chunky\Http\Controllers\Concerns\BuildsUploadInput;
use NETipar\Chunky\Http\Requests\InitiateBatchRequest;
use NETipar\Chunky\Services\BatchService;
use NETipar\Chunky\Support\Coerce;
use Symfony\Component\HttpFoundation\Response;

final class InitiateBatchController
{
    use BuildsUploadInput;

    public function __invoke(InitiateBatchRequest $request, BatchService $batches): JsonResponse
    {
        $batch = $batches->initiate(
            totalFiles: Coerce::toInt($request->input('total_files')),
            profile: $this->nullableString($request, 'profile'),
            metadata: $this->metadataFrom($request),
            user: $request->user(),
        );

        return new JsonResponse([
            'batch_id' => $batch->batchId,
            'total_files' => $batch->totalFiles,
        ], Response::HTTP_CREATED);
    }
}
