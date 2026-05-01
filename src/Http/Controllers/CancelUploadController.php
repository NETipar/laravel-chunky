<?php

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\ChunkyManager;

class CancelUploadController extends Controller
{
    public function __invoke(string $uploadId, ChunkyManager $manager, Authorizer $authorizer): JsonResponse
    {
        $upload = $manager->status($uploadId);

        if (! $upload) {
            return response()->json(['message' => 'Upload not found or already finalized.'], 404);
        }

        if (! $authorizer->canAccessUpload(auth()->user(), $upload)) {
            return response()->json(['message' => 'Upload not found or already finalized.'], 404);
        }

        $cancelled = $manager->cancel($uploadId);

        if (! $cancelled) {
            return response()->json(['message' => 'Upload not found or already finalized.'], 404);
        }

        return response()->json(null, 204);
    }
}
