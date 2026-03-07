<?php

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use NETipar\Chunky\ChunkyManager;

class UploadStatusController extends Controller
{
    public function __invoke(string $uploadId, ChunkyManager $manager): JsonResponse
    {
        $status = $manager->status($uploadId);

        if (! $status) {
            return response()->json(['message' => 'Upload not found.'], 404);
        }

        return response()->json($status->toArray());
    }
}
