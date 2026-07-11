<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\Http\Controllers\Concerns\ResolvesOwnedUpload;
use NETipar\Chunky\Services\UploadService;

final class UploadStatusController
{
    use ResolvesOwnedUpload;

    public function __invoke(
        Request $request,
        string $uploadId,
        UploadService $uploads,
        Authorizer $authorizer,
        FilesystemFactory $filesystem,
    ): JsonResponse {
        $upload = $this->ownedUploadOr404($uploads, $authorizer, $request->user(), $uploadId);

        return new JsonResponse($this->statusPayload($upload, $filesystem));
    }
}
