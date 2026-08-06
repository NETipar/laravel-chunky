<?php

declare(strict_types=1);

namespace NETipar\Chunky\Profiles;

/**
 * Handed to a profile's completed() hook after assembly. Assembly may run in a
 * queue worker with no live user, so this carries the owning user id (string),
 * not an Authenticatable.
 */
final readonly class CompletedUpload
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $uploadId,
        public string $disk,
        public string $path,
        public string $fileName,
        public int $fileSize,
        public ?string $mimeType,
        public array $metadata,
        public ?string $userId,
        public ?string $batchId,
    ) {}
}
