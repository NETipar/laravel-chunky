<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events;

class FileAssembled extends AbstractChunkyEvent
{
    public function __construct(
        public readonly string $uploadId,
        public readonly string $finalPath,
        public readonly string $disk,
        public readonly string $fileName,
        public readonly int $fileSize,
    ) {}

    protected function broadcastEventKey(): string
    {
        return 'FileAssembled';
    }

    /**
     * @return array<int, string>
     */
    protected function broadcastChannelSuffixes(): array
    {
        return ["uploads.{$this->uploadId}"];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $payload = [
            'uploadId' => $this->uploadId,
            'fileName' => $this->fileName,
            'fileSize' => $this->fileSize,
        ];

        if (config('chunky.broadcasting.expose_internal_paths', false)) {
            $payload['finalPath'] = $this->finalPath;
            $payload['disk'] = $this->disk;
        }

        return $payload;
    }
}
