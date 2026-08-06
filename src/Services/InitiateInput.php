<?php

declare(strict_types=1);

namespace NETipar\Chunky\Services;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class InitiateInput
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $fileName,
        public int $fileSize,
        public ?string $mimeType = null,
        public ?string $profile = null,
        public array $metadata = [],
        public ?string $fingerprint = null,
        public ?string $batchId = null,
        public ?Authenticatable $user = null,
    ) {}
}
