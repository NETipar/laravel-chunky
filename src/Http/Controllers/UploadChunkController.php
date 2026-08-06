<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use NETipar\Chunky\Config\ChunkyConfig;
use NETipar\Chunky\Domain\ChunkUploadOutcome;
use NETipar\Chunky\Domain\UploadStatus;
use NETipar\Chunky\Http\ErrorCode;
use NETipar\Chunky\Http\Requests\UploadChunkRequest;
use NETipar\Chunky\Services\UploadService;
use NETipar\Chunky\Support\Coerce;

final class UploadChunkController
{
    public function __invoke(
        UploadChunkRequest $request,
        string $uploadId,
        UploadService $uploads,
        ChunkyConfig $config,
    ): JsonResponse {
        $chunkIndex = Coerce::toInt($request->input('chunk_index'));

        /** @var UploadedFile $chunk */
        $chunk = $request->file('chunk');

        $idempotencyKey = $this->idempotencyKey($request->header('Idempotency-Key'), $request->input('checksum'), $uploadId, $chunkIndex);

        if ($idempotencyKey !== null) {
            $cached = Cache::get($idempotencyKey);

            if (is_array($cached)) {
                return new JsonResponse($cached);
            }
        }

        try {
            $outcome = $uploads->uploadChunk(
                $uploadId,
                $chunkIndex,
                $chunk,
                Coerce::toNullableString($request->input('file_checksum')),
            );
        } catch (LockTimeoutException) {
            return new JsonResponse([
                'error' => ['code' => ErrorCode::LockTimeout->value, 'message' => ErrorCode::LockTimeout->message()],
            ], ErrorCode::LockTimeout->status());
        }

        $payload = $this->payload($outcome);

        if ($idempotencyKey !== null) {
            Cache::put($idempotencyKey, $payload, $config->idempotencyTtl);
        }

        return new JsonResponse($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ChunkUploadOutcome $outcome): array
    {
        return match ($outcome->status) {
            UploadStatus::Completed => [
                'status' => 'completed',
                'progress' => 100.0,
                'file' => $outcome->result?->file?->toArray(),
                'payload' => $outcome->result?->payload,
            ],
            UploadStatus::Assembling => [
                'status' => 'assembling',
                'progress' => 100.0,
            ],
            default => [
                'status' => 'uploading',
                'chunk_index' => $outcome->chunkIndex,
                'uploaded_count' => $outcome->uploadedCount,
                'total_chunks' => $outcome->totalChunks,
                'progress' => $outcome->progress,
            ],
        };
    }

    private function idempotencyKey(?string $header, mixed $checksum, string $uploadId, int $chunkIndex): ?string
    {
        if ($header !== null && $header !== '') {
            return 'chunky:idem:'.hash('sha256', $uploadId.':'.$chunkIndex.':'.$header);
        }

        $checksumString = Coerce::toNullableString($checksum);

        if ($checksumString !== null) {
            return 'chunky:idem:'.hash('sha256', $uploadId.':'.$chunkIndex.':cs:'.$checksumString);
        }

        return null;
    }
}
