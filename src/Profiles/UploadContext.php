<?php

declare(strict_types=1);

namespace NETipar\Chunky\Profiles;

use Illuminate\Contracts\Auth\Authenticatable;
use NETipar\Chunky\Support\Coerce;

/**
 * The request-time context handed to a profile's authorize()/directory()/
 * fileName() hooks. Carries the live authenticated user (available at initiate).
 */
final readonly class UploadContext
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ?Authenticatable $user,
        public string $fileName,
        public int $fileSize,
        public ?string $mimeType,
        public array $metadata = [],
        public ?string $batchId = null,
    ) {}

    public function userId(): ?string
    {
        return Coerce::toNullableString($this->user?->getAuthIdentifier());
    }
}
