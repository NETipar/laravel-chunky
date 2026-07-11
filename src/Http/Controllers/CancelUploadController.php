<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\Http\Controllers\Concerns\ResolvesOwnedUpload;
use NETipar\Chunky\Services\UploadService;

final class CancelUploadController
{
    use ResolvesOwnedUpload;

    public function __invoke(
        Request $request,
        string $uploadId,
        UploadService $uploads,
        Authorizer $authorizer,
    ): JsonResponse {
        // 404 for non-owner (anti-enumeration) before attempting the cancel.
        $this->ownedUploadOr404($uploads, $authorizer, $request->user(), $uploadId);

        $uploads->cancel($uploadId);

        return new JsonResponse(['status' => 'cancelled']);
    }
}
