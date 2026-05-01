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
            'checksum' => ['nullable', 'string'],
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
