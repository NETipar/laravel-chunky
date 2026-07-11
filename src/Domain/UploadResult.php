<?php

declare(strict_types=1);

namespace NETipar\Chunky\Domain;

final readonly class UploadResult
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        public string $uploadId,
        public UploadStatus $status,
        public ?CompletedFile $file = null,
        public ?array $payload = null,
    ) {}

    /**
     * @return array{status: string, file: array{path: string, size: int, url?: string}|null, payload: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'file' => $this->file?->toArray(),
            'payload' => $this->payload,
        ];
    }
}
