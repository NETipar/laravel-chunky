<?php

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Http\Requests\InitiateBatchUploadRequest;

class InitiateBatchUploadController extends Controller
{
    public function __invoke(InitiateBatchUploadRequest $request, string $batchId, ChunkyManager $manager): JsonResponse
    {
        $batch = $manager->getBatchStatus($batchId);

        $result = $manager->initiateInBatch(
            batchId: $batchId,
            fileName: $request->validated('file_name'),
            fileSize: (int) $request->validated('file_size'),
            mimeType: $request->validated('mime_type'),
            metadata: $request->validated('metadata') ?? [],
            context: $batch?->context ?? $request->validated('context'),
        );

        return response()->json($result->toArray(), 201);
    }
}
