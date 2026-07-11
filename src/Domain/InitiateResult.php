<?php

declare(strict_types=1);

namespace NETipar\Chunky\Domain;

final readonly class InitiateResult
{
    /**
     * @param  list<int>  $uploadedChunks
     */
    public function __construct(
        public string $uploadId,
        public int $chunkSize,
        public int $totalChunks,
        public bool $resumed,
        public array $uploadedChunks,
        public ?string $batchId = null,
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
            'resumed' => $this->resumed,
            'uploaded_chunks' => $this->uploadedChunks,
        ];

        if ($this->batchId !== null) {
            $data['batch_id'] = $this->batchId;
        }

        return $data;
    }
}
