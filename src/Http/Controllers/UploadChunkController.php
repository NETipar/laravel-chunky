<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Exceptions\UploadExpiredException;
use NETipar\Chunky\Http\Requests\UploadChunkRequest;
use NETipar\Chunky\Jobs\AssembleFileJob;

class UploadChunkController extends Controller
{
    public function __invoke(UploadChunkRequest $request, string $uploadId, ChunkyManager $manager): JsonResponse
    {
        $chunkIndex = (int) $request->validated('chunk_index');

        // Idempotency: a chunk POST that times out on the network may be
        // retried by the client even though the server actually accepted
        // the original. Without protection the second request fires
        // ChunkUploaded twice and (on the final chunk) dispatches a
        // duplicate AssembleFileJob. The Idempotency-Key header is
        // optional; when present we cache the response for the configured
        // TTL and replay it byte-for-byte on retry.
        $idempotencyKey = $this->resolveIdempotencyKey($request, $uploadId, $chunkIndex);

        if ($idempotencyKey && ($cached = Cache::get($idempotencyKey))) {
            return response()->json($cached);
        }

        try {
            $result = $manager->uploadChunk(
                uploadId: $uploadId,
                chunkIndex: $chunkIndex,
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

        $payload = [
            'chunk_index' => $chunkIndex,
            'is_complete' => $result->isComplete,
            'uploaded_count' => count($result->metadata->uploadedChunks),
            'total_chunks' => $result->metadata->totalChunks,
            'progress' => $result->metadata->progress(),
        ];

        if ($idempotencyKey) {
            $ttl = (int) config('chunky.idempotency_ttl_seconds', 300);
            Cache::put($idempotencyKey, $payload, $ttl);
        }

        return response()->json($payload);
    }

    private function resolveIdempotencyKey(UploadChunkRequest $request, string $uploadId, int $chunkIndex): ?string
    {
        if (! config('chunky.idempotency.enabled', true)) {
            return null;
        }

        $clientKey = $request->header('Idempotency-Key');

        if ($clientKey) {
            return "chunky:idem:{$uploadId}:{$chunkIndex}:".sha1((string) $clientKey);
        }

        // Fall back to a server-derived key when the client didn't supply
        // one but a checksum is available — covers the common case where
        // a network retry replays the same chunk bytes.
        $checksum = $request->input('checksum');

        if ($checksum) {
            return "chunky:idem:{$uploadId}:{$chunkIndex}:cs:{$checksum}";
        }

        return null;
    }
}
