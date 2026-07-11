<?php

declare(strict_types=1);

namespace NETipar\Chunky\Services\Assembly;

use NETipar\Chunky\Domain\UploadRecord;
use NETipar\Chunky\Profiles\UploadProfile;

/**
 * Mutable carrier threaded through the assembly steps. Assembly runs
 * single-threaded within a claim, so mutation is safe here.
 */
final class AssemblyState
{
    public ?string $stagingPath = null;

    public ?string $finalPath = null;

    /** @var array<string, mixed>|null */
    public ?array $payload = null;

    public function __construct(
        public readonly UploadRecord $record,
        public readonly ?UploadProfile $profile,
        public readonly string $disk,
    ) {}
}
