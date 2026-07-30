<?php

declare(strict_types=1);

namespace NETipar\Chunky\Domain;

final readonly class InitiateResult
{
    /**
     * @param  list<int>  $uploadedChunks
     * @param  array<string, mixed>|null  $transport  Direct-transport bootstrap (mode, part_urls, expires_at); null for server transport.
     */
    public function __construct(
        public string $uploadId,
        public int $chunkSize,
        public int $totalChunks,
        public bool $resumed,
        public array $uploadedChunks,
        public ?string $batchId = null,
        public ?array $transport = null,
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

        if ($this->transport !== null) {
            $data['transport'] = $this->transport;
        }

        return $data;
    }
}
