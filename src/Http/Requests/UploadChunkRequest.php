<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use NETipar\Chunky\Authorization\AuthorizesChunkyRequests;
use NETipar\Chunky\Contracts\UploadTracker;

class UploadChunkRequest extends FormRequest
{
    use AuthorizesChunkyRequests;

    public function authorize(): bool
    {
        return $this->userOwnsUpload();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $maxIndex = $this->resolveMaxChunkIndex();

        $chunkIndexRules = ['required', 'integer', 'min:0'];

        if ($maxIndex !== null) {
            $chunkIndexRules[] = "max:{$maxIndex}";
        }

        return [
            'chunk' => ['required', 'file'],
            'chunk_index' => $chunkIndexRules,
            // Tighten the format: SHA-256 → exactly 64 lowercase hex
            // characters. Without the regex any string was accepted,
            // which would let a hostile caller stuff arbitrary content
            // (including non-ASCII / SQL-shaped) into the cache key
            // (`Cache::get('chunky:idem:upid:0:cs:DROP TABLES')`).
            'checksum' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ];
    }

    private function resolveMaxChunkIndex(): ?int
    {
        $uploadId = $this->route('uploadId');

        if (! $uploadId) {
            return null;
        }

        $metadata = app(UploadTracker::class)->getMetadata((string) $uploadId);

        if (! $metadata) {
            return null;
        }

        return max(0, $metadata->totalChunks - 1);
    }
}
