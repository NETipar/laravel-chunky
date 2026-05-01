<?php

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use NETipar\Chunky\ChunkyManager;

class CancelUploadController extends Controller
{
    public function __invoke(string $uploadId, ChunkyManager $manager): JsonResponse
    {
        $cancelled = $manager->cancel($uploadId);

        if (! $cancelled) {
            return response()->json(['message' => 'Upload not found or already finalized.'], 404);
        }

        return response()->json(null, 204);
    }
}
