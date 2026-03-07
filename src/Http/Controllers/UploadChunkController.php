<?php

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Http\Requests\UploadChunkRequest;
use NETipar\Chunky\Jobs\AssembleFileJob;

class UploadChunkController extends Controller
{
    public function __invoke(UploadChunkRequest $request, string $uploadId, ChunkyManager $manager): JsonResponse
    {
        $result = $manager->uploadChunk(
            uploadId: $uploadId,
            chunkIndex: (int) $request->validated('chunk_index'),
            chunk: $request->file('chunk'),
        );

        if ($result['is_complete']) {
            AssembleFileJob::dispatch($uploadId);
        }

        $metadata = $result['metadata'];

        return response()->json([
            'chunk_index' => (int) $request->validated('chunk_index'),
            'is_complete' => $result['is_complete'],
            'uploaded_count' => count($metadata->uploadedChunks),
            'total_chunks' => $metadata->totalChunks,
            'progress' => $metadata->progress(),
        ]);
    }
}
