<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use NETipar\Chunky\Http\Controllers\Concerns\BuildsUploadInput;
use NETipar\Chunky\Http\Requests\InitiateUploadRequest;
use NETipar\Chunky\Services\InitiateInput;
use NETipar\Chunky\Services\UploadService;
use NETipar\Chunky\Support\Coerce;
use Symfony\Component\HttpFoundation\Response;

final class InitiateUploadController
{
    use BuildsUploadInput;

    public function __invoke(InitiateUploadRequest $request, UploadService $uploads): JsonResponse
    {
        $result = $uploads->initiate(new InitiateInput(
            fileName: Coerce::toString($request->input('file_name')),
            fileSize: Coerce::toInt($request->input('file_size')),
            mimeType: $this->nullableString($request, 'mime_type'),
            profile: $this->nullableString($request, 'profile'),
            metadata: $this->metadataFrom($request),
            fingerprint: $this->nullableString($request, 'fingerprint'),
            batchId: null,
            user: $request->user(),
        ));

        return new JsonResponse(
            $result->toArray(),
            $result->resumed ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }
}
