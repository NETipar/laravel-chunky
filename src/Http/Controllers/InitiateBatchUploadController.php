<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\Domain\BatchStatus;
use NETipar\Chunky\Exceptions\InvalidStateException;
use NETipar\Chunky\Http\Controllers\Concerns\BuildsUploadInput;
use NETipar\Chunky\Http\Controllers\Concerns\ResolvesOwnedBatch;
use NETipar\Chunky\Http\Requests\InitiateBatchUploadRequest;
use NETipar\Chunky\Services\BatchService;
use NETipar\Chunky\Services\InitiateInput;
use NETipar\Chunky\Services\UploadService;
use NETipar\Chunky\Support\Coerce;
use Symfony\Component\HttpFoundation\Response;

final class InitiateBatchUploadController
{
    use BuildsUploadInput;
    use ResolvesOwnedBatch;

    public function __invoke(
        InitiateBatchUploadRequest $request,
        string $batchId,
        BatchService $batches,
        UploadService $uploads,
        Authorizer $authorizer,
    ): JsonResponse {
        // 404 for non-owner (anti-enumeration), same as batch status/cancel.
        $batch = $this->ownedBatchOr404($batches, $authorizer, $request->user(), $batchId);

        if ($batch->status->isTerminal()) {
            throw InvalidStateException::batch($batch->status, BatchStatus::InProgress);
        }

        $result = $uploads->initiate(new InitiateInput(
            fileName: Coerce::toString($request->input('file_name')),
            fileSize: Coerce::toInt($request->input('file_size')),
            mimeType: $this->nullableString($request, 'mime_type'),
            profile: $batch->profile,
            metadata: $this->metadataFrom($request),
            fingerprint: $this->nullableString($request, 'fingerprint'),
            batchId: $batchId,
            user: $request->user(),
        ));

        return new JsonResponse(
            $result->toArray(),
            $result->resumed ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }
}
