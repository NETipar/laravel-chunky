<?php

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Exceptions\UploadExpiredException;
use NETipar\Chunky\Http\Requests\UploadChunkRequest;
use NETipar\Chunky\Jobs\AssembleFileJob;

class UploadChunkController extends Controller
{
    public function __invoke(UploadChunkRequest $request, string $uploadId, ChunkyManager $manager): JsonResponse
    {
        try {
            $result = $manager->uploadChunk(
                uploadId: $uploadId,
                chunkIndex: (int) $request->validated('chunk_index'),
                chunk: $request->file('chunk'),
            );
        } catch (UploadExpiredException $e) {
            return response()->json(['message' => $e->getMessage()], 410);
        } catch (ChunkyException $e) {
            // 409 Conflict: the upload is no longer in a state where it can
            // accept chunks (cancelled / completed / failed / assembling).
            return response()->json(['message' => $e->getMessage()], 409);
        }

        if ($result->isComplete) {
            AssembleFileJob::dispatch($uploadId);
        }

        return response()->json([
            'chunk_index' => (int) $request->validated('chunk_index'),
            'is_complete' => $result->isComplete,
            'uploaded_count' => count($result->metadata->uploadedChunks),
            'total_chunks' => $result->metadata->totalChunks,
            'progress' => $result->metadata->progress(),
        ]);
    }
}
