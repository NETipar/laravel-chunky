<?php

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\ChunkyManager;

class UploadStatusController extends Controller
{
    public function __invoke(string $uploadId, ChunkyManager $manager, Authorizer $authorizer): JsonResponse
    {
        $status = $manager->status($uploadId);

        if (! $status) {
            return response()->json(['message' => 'Upload not found.'], 404);
        }

        if (! $authorizer->canAccessUpload(auth()->user(), $status)) {
            // Match the not-found response so non-owners can't probe which
            // upload IDs exist.
            return response()->json(['message' => 'Upload not found.'], 404);
        }

        return response()->json($status->toPublicArray());
    }
}
