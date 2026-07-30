<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use NETipar\Chunky\Http\Requests\PartUrlsRequest;
use NETipar\Chunky\Services\DirectUploadService;

final class PartUrlsController
{
    public function __invoke(PartUrlsRequest $request, string $uploadId, DirectUploadService $direct): JsonResponse
    {
        return new JsonResponse($direct->partUrls($uploadId, $request->indexes()));
    }
}
