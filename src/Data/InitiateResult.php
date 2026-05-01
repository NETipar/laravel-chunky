<?php

declare(strict_types=1);

namespace NETipar\Chunky\Data;

class InitiateResult
{
    public function __construct(
        public readonly string $uploadId,
        public readonly int $chunkSize,
        public readonly int $totalChunks,
        public readonly ?string $batchId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'upload_id' => $this->uploadId,
            'chunk_size' => $this->chunkSize,
            'total_chunks' => $this->totalChunks,
        ];

        if ($this->batchId !== null) {
            $data['batch_id'] = $this->batchId;
        }

        return $data;
    }
}
