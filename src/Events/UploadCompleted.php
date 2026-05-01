<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use NETipar\Chunky\Data\UploadMetadata;

class UploadCompleted implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public readonly string $uploadId;

    public readonly string $finalPath;

    public readonly string $disk;

    /** @var array<string, mixed>|null */
    public readonly ?array $metadata;

    public function __construct(
        public readonly UploadMetadata $upload,
    ) {
        $this->uploadId = $upload->uploadId;
        $this->finalPath = $upload->finalPath ?? '';
        $this->disk = $upload->disk;
        $this->metadata = $upload->metadata ?: null;
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $prefix = config('chunky.broadcasting.channel_prefix', 'chunky');
        $channels = [new PrivateChannel("{$prefix}.uploads.{$this->uploadId}")];

        if ($this->upload->userId) {
            $channels[] = new PrivateChannel("{$prefix}.user.{$this->upload->userId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'UploadCompleted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // Internal-by-default: disk and finalPath are server-side details
        // (a path inside config('chunky.disk')'s root). Most consumers
        // only need the upload id and human-readable file metadata. Set
        // chunky.broadcasting.expose_internal_paths = true to opt in.
        $payload = [
            'uploadId' => $this->uploadId,
            'fileName' => $this->upload->fileName,
            'fileSize' => $this->upload->fileSize,
            'context' => $this->upload->context,
            'status' => $this->upload->status->value,
        ];

        if (config('chunky.broadcasting.expose_internal_paths', false)) {
            $payload['finalPath'] = $this->finalPath;
            $payload['disk'] = $this->disk;
        }

        return $payload;
    }

    public function broadcastQueue(): ?string
    {
        return config('chunky.broadcasting.queue');
    }

    public function broadcastWhen(): bool
    {
        return config('chunky.broadcasting.enabled', false);
    }
}
