<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use NETipar\Chunky\Data\UploadMetadata;

class UploadFailed implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public readonly string $uploadId;

    public readonly string $disk;

    /** @var array<string, mixed>|null */
    public readonly ?array $metadata;

    public function __construct(
        public readonly UploadMetadata $upload,
        public readonly string $reason,
    ) {
        $this->uploadId = $upload->uploadId;
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
        return 'UploadFailed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $payload = [
            'uploadId' => $this->uploadId,
            'fileName' => $this->upload->fileName,
            'fileSize' => $this->upload->fileSize,
            'context' => $this->upload->context,
            'reason' => $this->reason,
        ];

        if (config('chunky.broadcasting.expose_internal_paths', false)) {
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
